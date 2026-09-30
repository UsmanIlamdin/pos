<?php

namespace App\Controllers;

use App\Libraries\Consolidated_invoice_lib;
use App\Libraries\Customer_account_lib;
use App\Models\Customer;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use RuntimeException;

class Accounts extends Secure_Controller
{
    private Customer $customer;
    private Customer_account_lib $accountLib;
    private Consolidated_invoice_lib $invoiceLib;
    private array $config;

    public function __construct()
    {
        parent::__construct('accounts');
        $this->customer = model(Customer::class);
        $this->accountLib = new Customer_account_lib();
        $this->invoiceLib = new Consolidated_invoice_lib($this->accountLib);
        $this->config = config(OSPOS::class)->settings;
        helper(['tabular', 'locale', 'dompdf']);
    }

    public function getIndex(): string
    {
        $data['table_headers'] = $this->accountListHeaders();

        return view('accounts/manage', $data);
    }

    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit = (int) $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = (int) $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort = $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'last_name';
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'asc';

        if ($sort === 'total_due') {
            $sort = 'last_name';
        }

        $customers = $this->customer->search($search, $limit, $offset, $sort, $order);
        $totalRows = $this->customer->get_found_rows($search);

        $rows = [];
        foreach ($customers->getResult() as $person) {
            $due = $this->accountLib->getCustomerOutstanding((int) $person->person_id);
            $rows[] = [
                'people.person_id' => $person->person_id,
                'last_name'        => $person->last_name,
                'first_name'       => $person->first_name,
                'phone_number'     => $person->phone_number,
                'total_due'        => to_currency($due),
                'account'          => anchor(
                    'accounts/view/' . $person->person_id,
                    '<span class="glyphicon glyphicon-folder-open"></span>',
                    ['title' => lang('Accounts.view_account')]
                ),
            ];
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $rows]);
    }

    public function getView(int $customerId = NEW_ENTRY): string
    {
        $person = $this->customer->get_info($customerId);
        if (empty($person->person_id)) {
            throw new RuntimeException(lang('Common.no_persons_to_display'));
        }

        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $filters = $this->accountViewFilters();
        $perPage = Customer_account_lib::DEFAULT_PAGE_SIZE;

        $ledgerPage = $this->accountLib->getLedgerPage($customerId, $filters['ledger']['page'], $perPage, $filters['ledger']);
        $activityPage = $this->accountLib->getAccountActivity($customerId, $filters['activity']['page'], $perPage, $filters['activity']);
        $invoicePage = $this->invoiceLib->listForCustomerPaginated($customerId, $filters['ci']['page'], $perPage, $filters['ci']);
        $allInvoices = $this->invoiceLib->listForCustomer($customerId);
        $outstandingSalesAll = $this->accountLib->getOutstandingSales($customerId, false);
        $outstandingSales = $this->accountLib->getOutstandingSales($customerId, false, $filters['outstanding']);
        $payableSales = array_values(array_filter(
            $outstandingSalesAll,
            static fn (array $sale): bool => empty($sale['on_consolidated_invoice'])
        ));
        $txPage = $this->accountLib->getFinancialTransactions($customerId, $filters['transaction']['page'], $perPage, $filters['transaction']);

        $data = [
            'person_info'             => $person,
            'customer_id'             => $customerId,
            'outstanding_balance'     => $ledgerPage['summary']['outstanding_receivables'] ?? $this->accountLib->getCustomerOutstanding($customerId),
            'customer_credit'         => $ledgerPage['summary']['customer_credit'] ?? $this->accountLib->getCustomerCreditBalance($customerId),
            'outstanding_sales_all'   => $outstandingSalesAll,
            'outstanding_sales'       => $outstandingSales,
            'payable_sales'           => $payableSales,
            'eligible_sales'          => $this->accountLib->getOutstandingSales($customerId, true),
            'consolidated_invoices'   => $invoicePage['rows'],
            'invoices_page'           => $invoicePage,
            'open_invoices'           => array_values(array_filter(
                $allInvoices,
                static fn (array $invoice): bool => !in_array((int) $invoice['status'], [CI_STATUS_CANCELLED, CI_STATUS_PAID], true)
            )),
            'open_invoice_count'      => $this->invoiceLib->countOpenForCustomer($customerId),
            'ledger'                  => $ledgerPage['rows'],
            'ledger_page'             => $ledgerPage,
            'account_level'           => $ledgerPage['account_level'] ?? [],
            'transactions'            => $txPage['rows'],
            'transactions_page'       => $txPage,
            'activity'                => $activityPage['rows'],
            'activity_page'           => $activityPage,
            'payment_options'         => $this->accountLib->getAccountPaymentOptions(),
            'reference_types'         => get_reference_code_payment_types(),
            'can_pay'                 => $this->employee->has_grant('accounts_payments', $employeeId),
            'can_void'                => $this->employee->has_grant('accounts_void', $employeeId),
            'can_reallocate'          => $this->employee->has_grant('accounts_reallocate', $employeeId),
            'can_credit_apply'        => $this->employee->has_grant('accounts_credit_apply', $employeeId),
            'can_refund'              => $this->employee->has_grant('accounts_refund', $employeeId),
            'can_consolidate'         => $this->employee->has_grant('accounts_consolidated', $employeeId),
            'can_cancel'              => $this->employee->has_grant('accounts_cancel', $employeeId),
            'status_labels'           => $this->statusLabels(),
            'per_page'                => $perPage,
            'account_filters'         => $filters,
        ];

        return view('accounts/view', $data);
    }

    public function getLedger(int $customerId): ResponseInterface
    {
        $filters = $this->accountViewFilters()['ledger'];
        $page = $filters['page'];
        $perPage = max(1, (int) ($this->request->getGet('per_page') ?: Customer_account_lib::DEFAULT_PAGE_SIZE));
        $result = $this->accountLib->getLedgerPage($customerId, $page, $perPage, $filters);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $children = [];
            foreach ($row['children'] ?? [] as $child) {
                $children[] = [
                    'source'         => $child['source'],
                    'date'           => esc(to_datetime(strtotime((string) ($child['date'] ?? '')))),
                    'reference'      => esc((string) ($child['reference'] ?? '')),
                    'amount'         => to_currency($child['amount'] ?? $child['credit'] ?? 0),
                    'payment_id'     => $child['payment_id'] ?? null,
                    'return_credit_id' => $child['return_credit_id'] ?? null,
                    'can_void'       => !empty($child['can_void']),
                    'can_reallocate' => !empty($child['can_reallocate']),
                ];
            }
            $rows[] = [
                'sale_id'    => $row['sale_id'],
                'date'       => esc(to_datetime(strtotime((string) $row['date']))),
                'reference'  => esc((string) $row['reference']),
                'sale_total' => to_currency($row['sale_total']),
                'payments'   => to_currency($row['payments']),
                'credits'    => to_currency($row['credits']),
                'balance'    => to_currency($row['balance']),
                'status'     => esc((string) $row['status']),
                'children'   => $children,
            ];
        }

        return $this->response->setJSON(array_merge($result, [
            'rows'    => $rows,
            'summary' => [
                'outstanding_receivables' => to_currency($result['summary']['outstanding_receivables'] ?? 0),
                'customer_credit'         => to_currency($result['summary']['customer_credit'] ?? 0),
                'outstanding_sales_count' => (int) ($result['summary']['outstanding_sales_count'] ?? 0),
                'open_ci_count'           => (int) ($result['summary']['open_ci_count'] ?? 0),
            ],
        ]));
    }

    public function getTransactions(int $customerId): ResponseInterface
    {
        $filters = $this->accountViewFilters()['transaction'];
        $page = $filters['page'];
        $perPage = max(1, (int) ($this->request->getGet('per_page') ?: Customer_account_lib::DEFAULT_PAGE_SIZE));
        $result = $this->accountLib->getFinancialTransactions($customerId, $page, $perPage, $filters);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'date'         => esc(to_datetime(strtotime((string) $row['date']))),
                'reference'    => esc((string) $row['reference']),
                'allocated_to' => esc((string) ($row['allocated_to'] ?? '')),
                'memo'         => esc((string) ($row['memo'] ?? '')),
                'debit'        => $row['debit'] ? to_currency($row['debit']) : '',
                'credit'       => $row['credit'] ? to_currency($row['credit']) : '',
                'balance'      => to_currency($row['balance']),
            ];
        }

        return $this->response->setJSON(array_merge($result, ['rows' => $rows]));
    }

    public function getActivity(int $customerId): ResponseInterface
    {
        $filters = $this->accountViewFilters()['activity'];
        $page = $filters['page'];
        $perPage = max(1, (int) ($this->request->getGet('per_page') ?: Customer_account_lib::DEFAULT_PAGE_SIZE));
        $result = $this->accountLib->getAccountActivity($customerId, $page, $perPage, $filters);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'date'      => esc((string) $row['date']),
                'reference' => esc((string) $row['reference']),
                'memo'      => esc((string) ($row['memo'] ?? '')),
            ];
        }

        return $this->response->setJSON(array_merge($result, ['rows' => $rows]));
    }

    public function getInvoices(int $customerId): ResponseInterface
    {
        $filters = $this->accountViewFilters()['ci'];
        $page = $filters['page'];
        $perPage = max(1, (int) ($this->request->getGet('per_page') ?: Customer_account_lib::DEFAULT_PAGE_SIZE));
        $result = $this->invoiceLib->listForCustomerPaginated($customerId, $page, $perPage, $filters);
        $statusLabels = $this->statusLabels();

        $rows = [];
        foreach ($result['rows'] as $invoice) {
            $id = (int) $invoice['consolidated_invoice_id'];
            $rows[] = [
                'invoice_number' => esc((string) $invoice['invoice_number']),
                'invoice_date'   => esc((string) $invoice['invoice_date']),
                'status'         => esc($statusLabels[(int) $invoice['status']] ?? ''),
                'total'          => to_currency($invoice['total_amount']),
                'paid'           => to_currency($invoice['paid']),
                'balance'        => to_currency($invoice['balance']),
                'actions'        => anchor('accounts/invoice/' . $id, lang('Accounts.view_account')),
            ];
        }

        return $this->response->setJSON(array_merge($result, ['rows' => $rows]));
    }

    public function postPayment(int $customerId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_payments', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $amount = (float) $this->request->getPost('payment_amount');
            $paymentType = (string) $this->request->getPost('payment_type');
            $referenceCode = $this->request->getPost('reference_code');
            $comment = $this->request->getPost('comment');
            $applyTo = (string) $this->request->getPost('apply_to');
            $consolidatedInvoiceId = null;
            $allocations = null;

            if ($applyTo === 'consolidated_invoice') {
                $consolidatedInvoiceId = (int) $this->request->getPost('consolidated_invoice_id');
                if ($consolidatedInvoiceId <= 0) {
                    throw new RuntimeException(lang('Accounts.error_allocation_required'));
                }
                $allocations = null; // FIFO within CI
            } elseif ($applyTo === 'sale') {
                $saleId = (int) $this->request->getPost('sale_id');
                if ($saleId <= 0) {
                    throw new RuntimeException(lang('Accounts.error_allocation_required'));
                }
                $allocations = [['sale_id' => $saleId, 'amount' => $amount]];
            } elseif ($applyTo === 'sales') {
                $allocations = $this->parseMultiSaleAllocations();
            } else {
                throw new RuntimeException(lang('Accounts.error_allocation_required'));
            }

            $result = $this->accountLib->recordPayment(
                $customerId,
                $amount,
                $paymentType,
                $employeeId,
                $referenceCode !== null && $referenceCode !== '' ? (string) $referenceCode : null,
                $comment !== null && $comment !== '' ? (string) $comment : null,
                null,
                $consolidatedInvoiceId,
                $allocations,
                $this->request->getPost('idempotency_key') !== null && $this->request->getPost('idempotency_key') !== ''
                    ? (string) $this->request->getPost('idempotency_key')
                    : null
            );

            if ($consolidatedInvoiceId !== null) {
                $this->invoiceLib->refreshStatus($consolidatedInvoiceId);
            }

            return $this->response->setJSON([
                'success'    => true,
                'message'    => lang('Accounts.payment_recorded'),
                'payment_id' => $result['payment_id'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postCreateInvoice(int $customerId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_consolidated', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $saleIds = $this->request->getPost('sale_ids') ?? [];
            if (!is_array($saleIds)) {
                $saleIds = [$saleIds];
            }
            $dueDate = $this->request->getPost('due_date');
            $comment = $this->request->getPost('comment');

            $result = $this->invoiceLib->create(
                $customerId,
                $saleIds,
                $employeeId,
                $dueDate !== null && $dueDate !== '' ? (string) $dueDate : null,
                $comment !== null && $comment !== '' ? (string) $comment : null
            );

            return $this->response->setJSON([
                'success'                 => true,
                'message'                 => lang('Accounts.consolidated_invoice_created'),
                'consolidated_invoice_id' => $result['consolidated_invoice_id'],
                'invoice_number'          => $result['invoice_number'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getInvoice(int $invoiceId): string
    {
        $details = $this->invoiceLib->getInvoiceDetails($invoiceId);
        $customerId = (int) $details['invoice']['customer_id'];
        $person = $this->customer->get_info($customerId);
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;

        $payments = [];
        foreach ($details['payments'] as $payment) {
            $payment['allocations'] = $this->accountLib->getPaymentAllocations((int) $payment['payment_id']);
            $payments[] = $payment;
        }
        $details['payments'] = $payments;

        $data = [
            'details'         => $details,
            'person_info'     => $person,
            'payment_options' => $this->accountLib->getAccountPaymentOptions(),
            'reference_types' => get_reference_code_payment_types(),
            'can_pay'         => $this->employee->has_grant('accounts_payments', $employeeId),
            'can_cancel'      => $this->employee->has_grant('accounts_cancel', $employeeId),
            'status_labels'   => $this->statusLabels(),
        ];

        return view('accounts/invoice', $data);
    }

    public function postPayInvoice(int $invoiceId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_payments', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $amount = (float) $this->request->getPost('payment_amount');
            $paymentType = (string) $this->request->getPost('payment_type');
            $referenceCode = $this->request->getPost('reference_code');
            $comment = $this->request->getPost('comment');

            $result = $this->invoiceLib->pay(
                $invoiceId,
                $amount,
                $paymentType,
                $employeeId,
                $referenceCode !== null && $referenceCode !== '' ? (string) $referenceCode : null,
                $comment !== null && $comment !== '' ? (string) $comment : null
            );

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Accounts.payment_recorded'),
                'status'  => $result['status'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postCancelInvoice(int $invoiceId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_cancel', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $this->invoiceLib->cancel($invoiceId);

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Accounts.status_cancelled'),
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postVoidPayment(int $paymentId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_void', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $reason = $this->request->getPost('void_reason');
            $this->accountLib->voidPayment(
                $paymentId,
                $employeeId,
                $reason !== null && $reason !== '' ? (string) $reason : null
            );

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Accounts.payment_voided'),
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getReallocatePayment(int $paymentId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_reallocate', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $data = $this->accountLib->getReallocateFormData($paymentId);

            return $this->response->setJSON(['success' => true] + $data);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postReallocatePayment(int $paymentId): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_reallocate', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $allocations = $this->parseMultiSaleAllocations();
            $result = $this->accountLib->reallocatePayment($paymentId, $allocations, $employeeId);

            return $this->response->setJSON([
                'success'     => true,
                'message'     => lang('Accounts.payment_reallocated'),
                'payment_id'  => $result['payment_id'],
                'allocations' => $result['allocations'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postApplyCredit(): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_credit_apply', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $customerId = (int) $this->request->getPost('customer_id');
            $saleId = (int) $this->request->getPost('sale_id');
            $amount = (float) $this->request->getPost('amount');
            $comment = $this->request->getPost('comment');
            $idempotencyKey = $this->request->getPost('idempotency_key');

            $result = $this->accountLib->applyCustomerCredit(
                $customerId,
                $saleId,
                $amount,
                $employeeId,
                $comment !== null && $comment !== '' ? (string) $comment : null,
                $idempotencyKey !== null && $idempotencyKey !== '' ? (string) $idempotencyKey : null
            );

            return $this->response->setJSON([
                'success'        => true,
                'message'        => lang('Accounts.credit_applied'),
                'application_id' => $result['application_id'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postRefundCredit(): ResponseInterface
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        if (!$this->employee->has_grant('accounts_refund', $employeeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Error.no_permission_module'),
            ]);
        }

        try {
            $customerId = (int) $this->request->getPost('customer_id');
            $amount = (float) $this->request->getPost('amount');
            $paymentType = (string) $this->request->getPost('payment_type');
            $referenceCode = $this->request->getPost('reference_code');
            $comment = $this->request->getPost('comment');
            $idempotencyKey = $this->request->getPost('idempotency_key');

            $result = $this->accountLib->refundCustomerCredit(
                $customerId,
                $amount,
                $paymentType,
                $employeeId,
                $referenceCode !== null && $referenceCode !== '' ? (string) $referenceCode : null,
                $comment !== null && $comment !== '' ? (string) $comment : null,
                $idempotencyKey !== null && $idempotencyKey !== '' ? (string) $idempotencyKey : null
            );

            return $this->response->setJSON([
                'success'   => true,
                'message'   => lang('Accounts.credit_refunded'),
                'refund_id' => $result['refund_id'],
            ]);
        } catch (RuntimeException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getPrintInvoice(int $invoiceId, string $template = 'short'): string
    {
        $data = $this->buildInvoicePrintData($invoiceId, $template);
        $view = $data['template'] === 'detailed'
            ? 'accounts/invoice_print_detailed'
            : 'accounts/invoice_print';

        return view($view, $data);
    }

    public function getPdf(int $invoiceId, string $template = 'short'): ResponseInterface
    {
        $data = $this->buildInvoicePrintData($invoiceId, $template);
        $view = $data['template'] === 'detailed'
            ? 'accounts/invoice_pdf_detailed'
            : 'accounts/invoice_pdf';
        $html = view($view, $data);
        $pdf = create_pdf($html);
        $suffix = $data['template'] === 'detailed' ? '-detailed' : '';
        $filename = ($data['page_title'] ?? $data['details']['invoice']['invoice_number']) . $suffix . '.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($pdf);
    }

    /**
     * @return list<array{sale_id:int, amount:float}>
     */
    private function parseMultiSaleAllocations(): array
    {
        $raw = $this->request->getPost('allocations');
        if (!is_array($raw)) {
            throw new RuntimeException(lang('Accounts.error_allocation_required'));
        }

        $allocations = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $saleId = (int) ($row['sale_id'] ?? 0);
            $amount = (float) ($row['amount'] ?? 0);
            if ($saleId <= 0 || $amount <= 0) {
                continue;
            }
            $allocations[] = ['sale_id' => $saleId, 'amount' => $amount];
        }

        if ($allocations === []) {
            throw new RuntimeException(lang('Accounts.error_allocation_required'));
        }

        return $allocations;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInvoicePrintData(int $invoiceId, string $template = 'short'): array
    {
        $details = $this->invoiceLib->getInvoiceDetails($invoiceId);
        $invoice = $details['invoice'];
        $person = $this->customer->get_info((int) $invoice['customer_id']);
        $barcodeLib = new \App\Libraries\Barcode_lib();

        $customerLines = array_filter([
            trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')),
            $person->address_1 ?? '',
            $person->address_2 ?? '',
            trim(($person->city ?? '') . ' ' . ($person->state ?? '') . ' ' . ($person->zip ?? '')),
            $person->country ?? '',
            $person->phone_number ?? '',
            $person->email ?? '',
        ]);

        $companyLines = array_filter([
            $this->config['address'] ?? '',
            $this->config['phone'] ?? '',
            $this->config['email'] ?? '',
        ]);
        if (!empty($this->config['account_number'])) {
            $companyLines[] = lang('Sales.account_number') . ': ' . $this->config['account_number'];
        }
        if (!empty($this->config['tax_id'])) {
            $companyLines[] = lang('Sales.tax_id') . ': ' . $this->config['tax_id'];
        }

        $invoiceNumber = (string) $invoice['invoice_number'];
        $logoPath = '';
        $logoMime = '';
        if (!empty($this->config['company_logo'])) {
            $candidate = FCPATH . 'uploads/' . $this->config['company_logo'];
            if (is_file($candidate)) {
                $logoPath = $candidate;
                $logoMime = mime_content_type($candidate) ?: 'image/png';
            }
        }

        $template = in_array($template, ['short', 'detailed'], true) ? $template : 'short';
        $autoPrint = $this->request->getGet('print') === '1';

        return [
            'details'          => $details,
            'person_info'      => $person,
            'customer'         => $person,
            'customer_info'    => implode("\n", $customerLines),
            'company_info'     => implode("\n", $companyLines),
            'status_labels'    => $this->statusLabels(),
            'config'           => $this->config,
            'barcode'          => $barcodeLib->generate_receipt_barcode($invoiceNumber),
            'page_title'       => $invoiceNumber,
            'print_filename'   => $invoiceNumber,
            'logo_path'        => $logoPath,
            'logo_mime'        => $logoMime,
            'print_after_sale' => false,
            'auto_print'       => $autoPrint,
            'template'         => $template,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function accountViewFilters(): array
    {
        $get = static function (string $key): string {
            return trim((string) service('request')->getGet($key));
        };

        $date = function (string $key) use ($get): string {
            return (string) ($this->accountLib->sanitizeFilterDate($get($key)) ?? '');
        };

        $pageOf = static function (string $key): int {
            $page = (int) service('request')->getGet($key);
            return max(1, $page);
        };

        $pick = static function (string $value, array $allowed): string {
            return in_array($value, $allowed, true) ? $value : '';
        };

        $outstanding = [
            'search' => $get('outstanding_search'),
            'from'   => $date('outstanding_from'),
            'to'     => $date('outstanding_to'),
            'status' => $pick($get('outstanding_status'), ['unpaid', 'partially_paid']),
            'page'   => 1,
        ];
        $ci = [
            'search'  => $get('ci_search'),
            'from'    => $date('ci_from'),
            'to'      => $date('ci_to'),
            'status'  => $pick($get('ci_status'), ['paid', 'partially_paid', 'unpaid']),
            'balance' => $pick($get('ci_balance'), ['with_balance', 'fully_paid']),
            'page'    => $pageOf('ci_page'),
        ];
        $ledger = [
            'search' => $get('ledger_search'),
            'from'   => $date('ledger_from'),
            'to'     => $date('ledger_to'),
            'status' => $pick($get('ledger_status'), ['paid', 'partially_paid', 'unpaid']),
            'page'   => $pageOf('ledger_page'),
        ];
        $transaction = [
            'search' => $get('transaction_search'),
            'from'   => $date('transaction_from'),
            'to'     => $date('transaction_to'),
            'type'   => $pick($get('transaction_type'), [
                'sale', 'payment', 'return', 'refund', 'customer_credit', 'credit_allocation', 'consolidated_invoice',
            ]),
            'side'   => $pick($get('transaction_side'), ['debit', 'credit']),
            'page'   => $pageOf('transaction_page'),
        ];
        $activity = [
            'search' => $get('activity_search'),
            'from'   => $date('activity_from'),
            'to'     => $date('activity_to'),
            'type'   => $pick($get('activity_type'), [
                'sale', 'payment', 'return', 'refund', 'consolidated_invoice', 'customer_credit', 'credit_allocation',
            ]),
            'voided' => $pick($get('activity_voided'), ['include', 'exclude']),
            'page'   => $pageOf('activity_page'),
        ];

        $markActive = static function (array $section, array $ignore = ['page']): array {
            $active = false;
            foreach ($section as $key => $value) {
                if (in_array($key, $ignore, true)) {
                    continue;
                }
                if ($value !== '' && $value !== null) {
                    $active = true;
                    break;
                }
            }
            $section['active'] = $active;

            return $section;
        };

        return [
            'outstanding' => $markActive($outstanding),
            'ci'          => $markActive($ci),
            'ledger'      => $markActive($ledger),
            'transaction' => $markActive($transaction),
            'activity'    => $markActive($activity),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            CI_STATUS_DRAFT          => lang('Accounts.status_draft'),
            CI_STATUS_OPEN           => lang('Accounts.status_open'),
            CI_STATUS_PARTIALLY_PAID => lang('Accounts.status_partially_paid'),
            CI_STATUS_PAID           => lang('Accounts.status_paid'),
            CI_STATUS_CANCELLED      => lang('Accounts.status_cancelled'),
        ];
    }

    private function accountListHeaders(): string
    {
        $headers = [
            ['people.person_id' => lang('Common.id')],
            ['last_name'        => lang('Common.last_name')],
            ['first_name'       => lang('Common.first_name')],
            ['phone_number'     => lang('Common.phone_number')],
            ['total_due'        => lang('Accounts.total_due'), 'sortable' => false],
            ['account'          => '', 'sortable' => false],
        ];

        return transform_headers($headers, false, false);
    }
}
