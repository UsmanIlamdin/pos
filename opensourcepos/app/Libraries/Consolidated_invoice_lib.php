<?php

namespace App\Libraries;

use App\Models\Appconfig;
use App\Models\Consolidated_invoice;
use App\Models\Consolidated_invoice_sale;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Consolidated invoice create / pay / cancel / numbering.
 *
 * Creating a CI does not change customer outstanding; payments allocate to linked sales.
 */
class Consolidated_invoice_lib
{
    private BaseConnection $db;
    private Customer_account_lib $accountLib;

    public function __construct(?Customer_account_lib $accountLib = null)
    {
        $this->db = Database::connect();
        $this->accountLib = $accountLib ?? new Customer_account_lib();
        helper(['locale']);
    }

    /**
     * @param list<int> $saleIds
     * @return array{consolidated_invoice_id:int, invoice_number:string, total_amount:float}
     */
    public function create(int $customerId, array $saleIds, int $employeeId, ?string $dueDate = null, ?string $comment = null): array
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));
        if ($saleIds === []) {
            throw new RuntimeException(lang('Accounts.error_no_sales_selected'));
        }

        $this->db->transStart();

        try {
            $links = [];
            $totalAmount = 0.0;

            foreach ($saleIds as $saleId) {
                $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
                if ($sale === null) {
                    throw new RuntimeException(lang('Accounts.error_no_sales_selected'));
                }
                if ((int) $sale['customer_id'] !== $customerId) {
                    throw new RuntimeException(lang('Accounts.error_different_customer'));
                }
                if ((int) $sale['sale_status'] !== COMPLETED) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }
                if (!in_array((int) $sale['sale_type'], [SALE_TYPE_POS, SALE_TYPE_INVOICE], true)) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }
                if ($this->accountLib->isSaleOnActiveConsolidatedInvoice($saleId)) {
                    throw new RuntimeException(lang('Accounts.error_sale_already_consolidated'));
                }

                $outstanding = $this->accountLib->getSaleOutstanding($saleId);
                if ($outstanding <= 0) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }

                $links[] = [
                    'sale_id' => $saleId,
                    'amount'  => $outstanding,
                ];
                $totalAmount += $outstanding;
            }

            $totalAmount = $this->moneyRound($totalAmount);
            $invoiceNumber = $this->nextInvoiceNumber();

            $invoiceModel = model(Consolidated_invoice::class);
            $invoiceModel->insert([
                'customer_id'    => $customerId,
                'invoice_number' => $invoiceNumber,
                'invoice_date'   => date('Y-m-d'),
                'due_date'       => $dueDate ?: null,
                'total_amount'   => $totalAmount,
                'status'         => CI_STATUS_OPEN,
                'comment'        => $comment,
                'employee_id'    => $employeeId,
            ]);
            $invoiceId = (int) $invoiceModel->getInsertID();

            $linkModel = model(Consolidated_invoice_sale::class);
            foreach ($links as $link) {
                $linkModel->insert([
                    'consolidated_invoice_id' => $invoiceId,
                    'sale_id'                 => $link['sale_id'],
                    'amount'                  => $link['amount'],
                ]);
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Failed to create consolidated invoice.');
            }

            return [
                'consolidated_invoice_id' => $invoiceId,
                'invoice_number'          => $invoiceNumber,
                'total_amount'            => $totalAmount,
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Pay against a consolidated invoice; allocates to linked sales oldest-first.
     *
     * @return array{payment_id:int, allocations:list<array{sale_id:int, amount:float}>, status:string}
     */
    public function pay(
        int $consolidatedInvoiceId,
        float $amount,
        string $paymentType,
        int $employeeId,
        ?string $referenceCode = null,
        ?string $comment = null,
        ?string $paymentTime = null
    ): array {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        $this->refreshStatus($consolidatedInvoiceId);
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            throw new RuntimeException(lang('Accounts.status_cancelled'));
        }
        if ((int) $invoice['status'] === CI_STATUS_PAID) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        $balance = $this->getBalance($consolidatedInvoiceId);
        $amount = $this->moneyRound($amount);
        if ($amount <= 0 || $amount - $balance > 0.00001) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        $result = $this->accountLib->recordPayment(
            (int) $invoice['customer_id'],
            $amount,
            $paymentType,
            $employeeId,
            $referenceCode,
            $comment,
            $paymentTime,
            $consolidatedInvoiceId,
            null
        );

        $status = $this->refreshStatus($consolidatedInvoiceId);

        return [
            'payment_id'  => $result['payment_id'],
            'allocations' => $result['allocations'],
            'status'      => $status,
        ];
    }

    public function cancel(int $consolidatedInvoiceId): void
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return;
        }

        $paymentCount = $this->db->table('customer_account_payments')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->countAllResults();

        if ($paymentCount > 0) {
            throw new RuntimeException(lang('Accounts.cannot_cancel_with_payments'));
        }

        $this->db->transStart();

        $this->db->table('consolidated_invoices')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->update(['status' => CI_STATUS_CANCELLED]);

        // Free sales for future consolidation (UNIQUE(sale_id) remains clean)
        $this->db->table('consolidated_invoice_sales')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->delete();

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new RuntimeException('Failed to cancel consolidated invoice.');
        }
    }

    /**
     * Authoritative CI balance = sum of outstanding on included underlying sales.
     * Creating a CI never creates a new receivable; sales remain authoritative.
     */
    public function getBalance(int $consolidatedInvoiceId): float
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return 0.0;
        }

        return $this->computeInvoiceFinancials($consolidatedInvoiceId)['balance_due'];
    }

    /**
     * Total settled against the document: prior sale payments + CI payments.
     */
    public function getPaidAmount(int $consolidatedInvoiceId): float
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return 0.0;
        }

        $financials = $this->computeInvoiceFinancials($consolidatedInvoiceId);

        return $this->moneyRound(
            (float) $financials['previously_paid_on_sales'] + (float) $financials['payments_applied_to_ci']
        );
    }

    public function refreshStatus(int $consolidatedInvoiceId): string
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return CI_STATUS_CANCELLED;
        }

        $financials = $this->computeInvoiceFinancials($consolidatedInvoiceId);
        $balance = (float) $financials['balance_due'];
        $net = (float) $financials['net_invoice_amount'];
        $status = CI_STATUS_OPEN;

        if ($balance <= 0 && $net > 0) {
            $status = CI_STATUS_PAID;
        } elseif ($balance + 0.00001 < $net) {
            $status = CI_STATUS_PARTIALLY_PAID;
        } else {
            $status = CI_STATUS_OPEN;
        }

        $this->db->table('consolidated_invoices')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->update(['status' => $status]);

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoiceDetails(int $consolidatedInvoiceId): array
    {
        $this->refreshStatus($consolidatedInvoiceId);
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        $financials = $this->computeInvoiceFinancials($consolidatedInvoiceId);
        $saleIds = $financials['sale_ids'];

        $sales = $this->db->table('consolidated_invoice_sales AS cis')
            ->select('cis.sale_id, cis.amount AS attached_amount, sales.sale_time, sales.invoice_number, sales.sale_type, sales.customer_id')
            ->join('sales', 'sales.sale_id = cis.sale_id')
            ->where('cis.consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('sales.sale_time', 'asc')
            ->get()
            ->getResultArray();

        $saleSummaries = $financials['sale_summaries'];
        $saleRows = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $saleSummary = $saleSummaries[$saleId] ?? [
                'original_total' => 0.0,
                'paid_on_sale'   => 0.0,
                'returns'        => 0.0,
                'balance'        => 0.0,
            ];
            $saleRows[] = [
                'sale_id'         => $saleId,
                'sale_time'       => $sale['sale_time'],
                'invoice_number'  => $sale['invoice_number'],
                'sale_type'       => (int) $sale['sale_type'],
                'attached_amount' => $this->moneyRound((float) $sale['attached_amount']),
                'original_total'  => (float) $saleSummary['original_total'],
                'paid_on_sale'    => (float) $saleSummary['paid_on_sale'],
                'returns'         => (float) $saleSummary['returns'],
                'outstanding'     => (float) $saleSummary['balance'],
                'current_balance' => (float) $saleSummary['balance'],
                'items'           => $this->getSaleItemRows($saleId),
            ];
        }

        $payments = $this->db->table('customer_account_payments')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('payment_time', 'asc')
            ->orderBy('payment_id', 'asc')
            ->get()
            ->getResultArray();

        $paymentRows = [];
        foreach ($payments as $payment) {
            $paymentId = (int) $payment['payment_id'];
            $paymentRows[] = [
                'payment_id'     => $paymentId,
                'payment_type'   => (string) $payment['payment_type'],
                'payment_amount' => $this->moneyRound((float) $payment['payment_amount']),
                'payment_time'   => $payment['payment_time'],
                'reference_code' => (string) ($payment['reference_code'] ?? ''),
                'comment'        => $payment['comment'] ?? '',
                'allocations'    => $this->accountLib->getPaymentAllocations($paymentId),
            ];
        }

        $returnRows = $this->getReturnAdjustmentRows($saleIds);
        $summary = [
            'original_sales_total'           => $financials['original_sales_total'],
            'returns_total'                  => $financials['returns_total'],
            'net_invoice_amount'             => $financials['net_invoice_amount'],
            'previously_paid_on_sales'       => $financials['previously_paid_on_sales'],
            'payments_applied_to_ci'         => $financials['payments_applied_to_ci'],
            'payments_applied'               => $financials['payments_applied_to_ci'], // backward-compatible alias (CI-only)
            'balance_due'                    => $financials['balance_due'],
            'invoice_number'                 => (string) $invoice['invoice_number'],
        ];

        return [
            'invoice'         => $invoice,
            'sales'           => $saleRows,
            'returns'         => $returnRows,
            'items'           => $this->getGroupedSaleItemRows($saleRows),
            'payments'        => $paymentRows,
            'paid'            => $this->moneyRound(
                (float) $financials['previously_paid_on_sales'] + (float) $financials['payments_applied_to_ci']
            ),
            'balance'         => $financials['balance_due'],
            'summary'         => $summary,
            'payment_summary' => $summary,
        ];
    }

    /**
     * Single source of truth for consolidated-invoice arithmetic.
     *
     * Balance Due always equals the sum of authoritative sale outstandings.
     *
     * @return array{
     *   sale_ids:list<int>,
     *   sale_summaries:array<int, array{original_total:float, paid_on_sale:float, returns:float, balance:float}>,
     *   original_sales_total:float,
     *   returns_total:float,
     *   net_invoice_amount:float,
     *   previously_paid_on_sales:float,
     *   payments_applied_to_ci:float,
     *   balance_due:float
     * }
     */
    public function computeInvoiceFinancials(int $consolidatedInvoiceId): array
    {
        $saleIds = $this->getIncludedSaleIds($consolidatedInvoiceId);
        $summaries = $saleIds === []
            ? []
            : $this->accountLib->getSalesFinancialSummaryMap($saleIds);

        $saleSummaries = [];
        $originalSalesTotal = 0.0;
        $returnsTotalAbs = 0.0;
        $previouslyPaid = 0.0;
        $balanceDue = 0.0;

        foreach ($saleIds as $saleId) {
            $summary = $summaries[$saleId] ?? [
                'sale_total'          => 0.0,
                'payments_applied'    => 0.0,
                'credits_returns'     => 0.0,
                'balance'             => 0.0,
            ];

            $ciAllocations = $this->getSaleAllocationsFromCi($saleId, $consolidatedInvoiceId);
            // Payments on the sale excluding allocations that came from THIS consolidated invoice.
            $paidOnSale = $this->moneyRound(max(0.0, (float) $summary['payments_applied'] - $ciAllocations));
            $returnsAbs = $this->moneyRound((float) $summary['credits_returns']);
            $original = $this->moneyRound((float) $summary['sale_total']);
            $balance = $this->moneyRound((float) $summary['balance']);

            $saleSummaries[$saleId] = [
                'original_total' => $original,
                'paid_on_sale'   => $paidOnSale,
                'returns'        => $this->moneyRound(-$returnsAbs),
                'balance'        => $balance,
            ];

            $originalSalesTotal += $original;
            $returnsTotalAbs += $returnsAbs;
            $previouslyPaid += $paidOnSale;
            $balanceDue += $balance;
        }

        $paymentsAppliedToCi = $this->getPaymentsAppliedToCi($consolidatedInvoiceId);
        $returnsTotal = $this->moneyRound(-$returnsTotalAbs);
        $originalSalesTotal = $this->moneyRound($originalSalesTotal);
        $previouslyPaid = $this->moneyRound($previouslyPaid);
        $balanceDue = $this->moneyRound(max(0.0, $balanceDue));
        $netInvoiceAmount = $this->moneyRound($originalSalesTotal + $returnsTotal);

        return [
            'sale_ids'                 => $saleIds,
            'sale_summaries'           => $saleSummaries,
            'original_sales_total'     => $originalSalesTotal,
            'returns_total'            => $returnsTotal,
            'net_invoice_amount'       => $netInvoiceAmount,
            'previously_paid_on_sales' => $previouslyPaid,
            'payments_applied_to_ci'   => $paymentsAppliedToCi,
            'balance_due'              => $balanceDue,
        ];
    }

    /**
     * @return list<int>
     */
    private function getIncludedSaleIds(int $consolidatedInvoiceId): array
    {
        $sales = $this->db->table('consolidated_invoice_sales')
            ->select('sale_id')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('sale_id', 'asc')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['sale_id'], $sales);
    }

    private function getPaymentsAppliedToCi(int $consolidatedInvoiceId): float
    {
        $paidRow = $this->db->table('customer_account_payments')
            ->select('COALESCE(SUM(payment_amount), 0) AS paid', false)
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($paidRow['paid'] ?? 0));
    }

    private function getSaleAllocationsFromCi(int $saleId, int $consolidatedInvoiceId): float
    {
        $row = $this->db->table('customer_payment_allocations AS cpa')
            ->select('COALESCE(SUM(cpa.amount), 0) AS allocated', false)
            ->join('customer_account_payments AS cap', 'cap.payment_id = cpa.customer_account_payment_id')
            ->where('cpa.sale_id', $saleId)
            ->where('cap.consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('cpa.status', CA_STATUS_ACTIVE)
            ->where('cap.status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['allocated'] ?? 0));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getSaleItemRows(int $saleId): array
    {
        $sale = model(Sale::class);
        $rows = $sale->get_sale_items_ordered($saleId)->getResultArray();

        $itemRows = [];
        foreach ($rows as $row) {
            $description = trim((string) ($row['name'] ?? $row['description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($row['description'] ?? ''));
            }
            if ($description === '') {
                $description = 'Item #' . ((int) ($row['item_id'] ?? 0));
            }

            $qty = (float) ($row['quantity_purchased'] ?? 0);
            $unitPrice = (float) ($row['item_unit_price'] ?? 0);
            $lineTotal = $this->calculateLineAmount($row);

            $itemRows[] = [
                'sale_id'         => $saleId,
                'item_id'         => (int) ($row['item_id'] ?? 0),
                'description'     => $description,
                'reference'       => $row['invoice_number'] ?? '',
                'qty'             => $qty,
                'quantity'        => $qty,
                'unit_price'      => $this->moneyRound($unitPrice),
                'amount'          => $this->moneyRound($lineTotal),
                'line_total'      => $this->moneyRound($lineTotal),
                'discount'        => (float) ($row['discount'] ?? 0),
                'discount_type'   => (int) ($row['discount_type'] ?? 0),
                'item_number'     => $row['item_number'] ?? null,
                'serialnumber'    => $row['serialnumber'] ?? null,
                'item_location'   => $row['item_location'] ?? null,
            ];
        }

        return $itemRows;
    }

    /**
     * @param list<int> $saleIds
     * @return list<array<string,mixed>>
     */
    private function getReturnAdjustmentRows(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        // Only active, non-cash return credits reduce AR and belong on the CI document.
        $credits = $this->db->table('customer_return_credits')
            ->whereIn('original_sale_id', $saleIds)
            ->where('status', CA_STATUS_ACTIVE)
            ->where('settlement_mode !=', 'cash')
            ->orderBy('created_at', 'asc')
            ->orderBy('return_credit_id', 'asc')
            ->get()
            ->getResultArray();

        if ($credits === []) {
            return [];
        }

        $saleModel = model(Sale::class);
        $returnRows = [];

        foreach ($credits as $credit) {
            $returnSaleId = (int) $credit['return_sale_id'];
            $originalSaleId = (int) ($credit['original_sale_id'] ?? 0);
            $returnSale = $this->db->table('sales')->where('sale_id', $returnSaleId)->get()->getRowArray();
            if ($returnSale === null || (int) $returnSale['sale_status'] !== COMPLETED) {
                continue;
            }

            $relatedSale = $this->db->table('sales')
                ->select('invoice_number, sale_time')
                ->where('sale_id', $originalSaleId)
                ->get()
                ->getRowArray();

            $items = $saleModel->get_sale_items_ordered($returnSaleId)->getResultArray();
            if ($items === []) {
                $amount = $this->moneyRound(-(float) $credit['return_amount']);
                $returnRows[] = [
                    'return_sale_id'   => $returnSaleId,
                    'return_number'    => (string) ($returnSale['invoice_number'] ?? 'Return #' . $returnSaleId),
                    'description'      => 'Return #' . $returnSaleId,
                    'reference'        => 'Return #' . $returnSaleId,
                    'related_sale_id'  => $originalSaleId,
                    'related_sale'     => $relatedSale['invoice_number'] ?? ('Sale #' . $originalSaleId),
                    'qty'              => 1.0,
                    'quantity'         => 1.0,
                    'unit_price'       => $this->moneyRound((float) $credit['return_amount']),
                    'amount'           => $amount,
                    'line_total'       => $amount,
                    'sale_time'        => $returnSale['sale_time'],
                    'return_total'     => $this->moneyRound((float) $credit['return_amount']),
                ];
                continue;
            }

            foreach ($items as $row) {
                $description = trim((string) ($row['name'] ?? $row['description'] ?? ''));
                if ($description === '') {
                    $description = 'Returned Item #' . ((int) ($row['item_id'] ?? 0));
                }

                $qty = abs((float) ($row['quantity_purchased'] ?? 0));
                $unitPrice = abs((float) ($row['item_unit_price'] ?? 0));
                $lineTotal = $this->calculateLineAmount($row);
                $amount = $this->moneyRound($lineTotal > 0 ? -$lineTotal : $lineTotal);

                $returnRows[] = [
                    'return_sale_id'   => $returnSaleId,
                    'return_number'    => (string) ($returnSale['invoice_number'] ?? 'Return #' . $returnSaleId),
                    'description'      => $description,
                    'reference'        => 'Return #' . $returnSaleId,
                    'related_sale_id'  => $originalSaleId,
                    'related_sale'     => $relatedSale['invoice_number'] ?? ('Sale #' . $originalSaleId),
                    'qty'              => $qty,
                    'quantity'         => $qty,
                    'unit_price'       => $this->moneyRound($unitPrice),
                    'amount'           => $amount,
                    'line_total'       => $amount,
                    'sale_time'        => $returnSale['sale_time'],
                    'return_total'     => $this->moneyRound((float) $credit['return_amount']),
                ];
            }
        }

        return $returnRows;
    }

    /**
     * @param list<array<string,mixed>> $saleRows
     * @return list<array<string,mixed>>
     */
    private function getGroupedSaleItemRows(array $saleRows): array
    {
        $flat = [];
        foreach ($saleRows as $sale) {
            foreach ($sale['items'] as $item) {
                $flat[] = [
                    'sale_id'        => (int) ($sale['sale_id'] ?? 0),
                    'invoice_number' => (string) ($sale['invoice_number'] ?? ''),
                    'description'    => (string) ($item['description'] ?? ''),
                    'reference'      => (string) ($sale['invoice_number'] ?? ''),
                    'qty'            => (float) ($item['qty'] ?? 0),
                    'unit_price'     => (float) ($item['unit_price'] ?? 0),
                    'amount'         => (float) ($item['amount'] ?? 0),
                ];
            }
        }

        return $flat;
    }

    private function calculateLineAmount(array $row): float
    {
        $quantity = (float) ($row['quantity_purchased'] ?? 0);
        $unitPrice = (float) ($row['item_unit_price'] ?? 0);
        $discount = (float) ($row['discount'] ?? 0);
        $discountType = (int) ($row['discount_type'] ?? 0);

        $base = $quantity * $unitPrice;
        $discounted = $discountType === PERCENT
            ? $base - round($base * $discount / 100, totals_decimals())
            : $quantity * max(0.0, $unitPrice - $discount);

        return $this->moneyRound($discounted);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSaleTotalSummary(int $saleId): array
    {
        $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        if ($sale === null) {
            return ['sale_id' => $saleId, 'original_total' => 0.0, 'balance' => 0.0];
        }

        return [
            'sale_id'        => $saleId,
            'original_total' => $this->moneyRound((float) $this->accountLib->computeSaleTotal($saleId)),
            'balance'        => $this->moneyRound((float) $this->accountLib->getSaleOutstanding($saleId)),
            'invoice_number' => (string) ($sale['invoice_number'] ?? ''),
            'sale_time'      => $sale['sale_time'],
        ];
    }
    /**
     * @return list<array<string, mixed>>
     */
    private function getSaleTransactionRows(int $consolidatedInvoiceId): array
    {
        $rows = [];
        $sales = $this->db->table('consolidated_invoice_sales')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('sale_id', 'asc')
            ->get()
            ->getResultArray();

        foreach ($sales as $row) {
            $saleId = (int) $row['sale_id'];
            $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
            if ($sale === null) {
                continue;
            }

            $rows[] = [
                'sale_id'        => $saleId,
                'date'           => $sale['sale_time'],
                'invoice_number' => (string) ($sale['invoice_number'] ?? ''),
                'original_total' => $this->moneyRound((float) $this->accountLib->computeSaleTotal($saleId)),
                'current_balance' => $this->moneyRound((float) $this->accountLib->getSaleOutstanding($saleId)),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoiceSummary(int $consolidatedInvoiceId): array
    {
        $financials = $this->computeInvoiceFinancials($consolidatedInvoiceId);

        return [
            'original_sales_total'     => $financials['original_sales_total'],
            'returns_total'            => $financials['returns_total'],
            'net_invoice_amount'       => $financials['net_invoice_amount'],
            'previously_paid_on_sales' => $financials['previously_paid_on_sales'],
            'payments_applied_to_ci'   => $financials['payments_applied_to_ci'],
            'payments_applied'         => $financials['payments_applied_to_ci'],
            'balance_due'              => $financials['balance_due'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(int $customerId): array
    {
        return $this->listForCustomerPaginated($customerId, 1, PHP_INT_MAX)['rows'];
    }

    /**
     * Count open (non-cancelled, non-paid) consolidated invoices for summary headers.
     */
    public function countOpenForCustomer(int $customerId): int
    {
        return $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->whereNotIn('status', [CI_STATUS_CANCELLED, CI_STATUS_PAID])
            ->countAllResults();
    }

    /**
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function listForCustomerPaginated(
        int $customerId,
        int $page = 1,
        int $perPage = Customer_account_lib::DEFAULT_PAGE_SIZE,
        array $filters = []
    ): array {
        $builder = $this->customerInvoiceFilterBuilder($customerId, $filters);
        $total = $builder->countAllResults();

        if ($perPage === PHP_INT_MAX || $perPage <= 0) {
            $perPage = max(1, $total ?: 1);
            $page = 1;
            $offset = 0;
            $totalPages = 1;
        } else {
            $page = max(1, $page);
            $perPage = max(1, $perPage);
            $totalPages = max(1, (int) ceil(($total ?: 1) / $perPage));
            if ($total === 0) {
                $totalPages = 1;
            }
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
        }

        $builder = $this->customerInvoiceFilterBuilder($customerId, $filters)
            ->orderBy('invoice_date', 'desc')
            ->orderBy('consolidated_invoice_id', 'desc');

        if ($perPage !== PHP_INT_MAX) {
            $builder->limit($perPage, $offset);
        }

        $rows = $builder->get()->getResultArray();

        foreach ($rows as &$row) {
            $id = (int) $row['consolidated_invoice_id'];
            $financials = $this->computeInvoiceFinancials($id);
            // Display document totals from live sale arithmetic, not the snapshot at create time.
            $row['total_amount'] = $financials['net_invoice_amount'];
            $row['paid'] = $this->moneyRound(
                (float) $financials['previously_paid_on_sales'] + (float) $financials['payments_applied_to_ci']
            );
            $row['balance'] = $financials['balance_due'];
            $row['status'] = $this->refreshStatus($id);
            $row['original_sales_total'] = $financials['original_sales_total'];
            $row['returns_total'] = $financials['returns_total'];
            $row['previously_paid_on_sales'] = $financials['previously_paid_on_sales'];
            $row['payments_applied_to_ci'] = $financials['payments_applied_to_ci'];
        }
        unset($row);

        return [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private function nextInvoiceNumber(): string
    {
        $config = model(Appconfig::class);
        $last = (int) $config->get_value('last_used_consolidated_invoice_number', '0');
        $next = $last + 1;
        $config->batch_save(['last_used_consolidated_invoice_number' => (string) $next]);

        return sprintf('CI-%06d', $next);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function customerInvoiceFilterBuilder(int $customerId, array $filters)
    {
        $builder = $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $builder->like('invoice_number', $search);
        }

        $from = $this->accountLib->sanitizeFilterDate($filters['from'] ?? null);
        $to = $this->accountLib->sanitizeFilterDate($filters['to'] ?? null);
        if ($from !== null) {
            $builder->where('invoice_date >=', $from);
        }
        if ($to !== null) {
            $builder->where('invoice_date <=', $to);
        }

        $status = (string) ($filters['status'] ?? '');
        $statusMap = [
            'paid'           => [CI_STATUS_PAID],
            'partially_paid' => [CI_STATUS_PARTIALLY_PAID],
            'unpaid'         => [CI_STATUS_OPEN, CI_STATUS_DRAFT],
        ];
        if (isset($statusMap[$status])) {
            $builder->whereIn('status', $statusMap[$status]);
        }

        $balance = (string) ($filters['balance'] ?? '');
        if ($balance === 'with_balance') {
            $builder->whereNotIn('status', [CI_STATUS_PAID, CI_STATUS_CANCELLED]);
        } elseif ($balance === 'fully_paid') {
            $builder->where('status', CI_STATUS_PAID);
        }

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    private function getInvoiceOrFail(int $consolidatedInvoiceId): array
    {
        $invoice = $this->db->table('consolidated_invoices')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->get()
            ->getRowArray();

        if ($invoice === null) {
            throw new RuntimeException(lang('Accounts.consolidated_invoice') . ' not found');
        }

        return $invoice;
    }

    private function moneyRound(float $amount): float
    {
        return round($amount, totals_decimals(), PHP_ROUND_HALF_UP);
    }
}
