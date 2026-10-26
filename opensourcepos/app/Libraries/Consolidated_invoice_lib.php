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

    /**
     * Legacy entry point — voids the consolidated invoice document.
     * Payments and sale balances are never reversed by cancelling/voiding a CI.
     */
    public function cancel(int $consolidatedInvoiceId, ?string $reason = null, int $employeeId = 0, ?int $expectedCustomerId = null): void
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return;
        }

        $this->voidInvoice(
            $consolidatedInvoiceId,
            $employeeId > 0 ? $employeeId : (int) $invoice['employee_id'],
            $reason !== null && trim($reason) !== '' ? $reason : lang('Accounts.void_invoice_default_reason'),
            $expectedCustomerId
        );
    }

    /**
     * Voids (cancels) a consolidated invoice document without reversing payments,
     * allocations, or sale outstanding. Linked sales remain historically attached
     * but are freed for a replacement open consolidated invoice.
     *
     * @throws RuntimeException when the invoice cannot be voided
     */
    /**
     * @return array{
     *   consolidated_invoice_id:int,
     *   customer_id:int,
     *   invoice_number:string,
     *   customer_outstanding_before:float,
     *   customer_outstanding_after:float,
     *   sale_outstanding_after:list<array{sale_id:int, outstanding:float}>,
     *   active_payment_total:float,
     *   payments_preserved:bool
     * }
     */
    public function voidInvoice(
        int $consolidatedInvoiceId,
        int $employeeId,
        string $reason,
        ?int $expectedCustomerId = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(lang('Accounts.error_void_reason_required'));
        }

        $this->db->transStart();

        try {
            $invoice = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('consolidated_invoices')
                . ' WHERE consolidated_invoice_id = ? FOR UPDATE',
                [$consolidatedInvoiceId]
            )->getRowArray();

            if ($invoice === null) {
                throw new RuntimeException(lang('Accounts.error_invoice_not_found'));
            }

            $customerId = (int) $invoice['customer_id'];
            if ($expectedCustomerId !== null && $customerId !== $expectedCustomerId) {
                throw new RuntimeException(lang('Accounts.error_different_customer'));
            }

            if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
                throw new RuntimeException(lang('Accounts.error_invoice_already_voided'));
            }

            $saleRows = $this->db->table('consolidated_invoice_sales')
                ->select('sale_id, amount')
                ->where('consolidated_invoice_id', $consolidatedInvoiceId)
                ->get()
                ->getResultArray();
            $saleIds = array_map(static fn (array $row): int => (int) $row['sale_id'], $saleRows);
            sort($saleIds);
            $this->accountLib->lockSalesForUpdatePublic($saleIds);

            $paidBefore = $this->moneyRound((float) $this->db->table('customer_account_payments')
                ->select('COALESCE(SUM(payment_amount), 0) AS paid', false)
                ->where('consolidated_invoice_id', $consolidatedInvoiceId)
                ->where('status', CA_STATUS_ACTIVE)
                ->get()
                ->getRowArray()['paid'] ?? 0);
            $saleOutstandingBefore = [];
            foreach ($saleIds as $saleId) {
                $saleOutstandingBefore[] = [
                    'sale_id'     => $saleId,
                    'outstanding' => $this->accountLib->getSaleOutstanding($saleId),
                ];
            }
            $customerOutstandingBefore = $this->accountLib->getCustomerOutstanding($customerId);

            $voidedAt = date('Y-m-d H:i:s');
            $update = [
                'status' => CI_STATUS_CANCELLED,
            ];
            if ($this->db->fieldExists('voided_at', 'consolidated_invoices')) {
                $update['voided_at'] = $voidedAt;
                $update['voided_by'] = $employeeId;
                $update['void_reason'] = mb_substr($reason, 0, 255);
            }

            $this->db->table('consolidated_invoices')
                ->where('consolidated_invoice_id', $consolidatedInvoiceId)
                ->where('status !=', CI_STATUS_CANCELLED)
                ->update($update);

            if ($this->db->affectedRows() < 1) {
                throw new RuntimeException(lang('Accounts.error_invoice_already_voided'));
            }

            // Keep consolidated_invoice_sales rows for history. Active CI membership
            // is determined by invoice status != CANCELLED (see getActiveConsolidatedInvoiceId).
            // Do not reverse payments or allocations — that is Void Payment.

            $saleOutstandingAfter = [];
            foreach ($saleIds as $saleId) {
                $saleOutstandingAfter[] = [
                    'sale_id'     => $saleId,
                    'outstanding' => $this->accountLib->getSaleOutstanding($saleId),
                ];
            }
            $customerOutstandingAfter = $this->accountLib->getCustomerOutstanding($customerId);

            $this->accountLib->recordAuditEvent(
                $customerId,
                'invoice_voided',
                'consolidated_invoice',
                $consolidatedInvoiceId,
                [
                    'reason'                       => $reason,
                    'invoice_number'               => (string) $invoice['invoice_number'],
                    'invoice_total'                => (float) $invoice['total_amount'],
                    'active_payment_total'         => $paidBefore,
                    'sale_outstanding_before'      => $saleOutstandingBefore,
                    'sale_outstanding_after'       => $saleOutstandingAfter,
                    'customer_outstanding_before'  => $customerOutstandingBefore,
                    'customer_outstanding_after'   => $customerOutstandingAfter,
                    'sale_ids'                     => $saleIds,
                    'payments_preserved'           => true,
                    'voided_at'                    => $voidedAt,
                ],
                $employeeId
            );

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException(lang('Accounts.error_void_invoice_failed'));
            }

            return [
                'consolidated_invoice_id'      => $consolidatedInvoiceId,
                'customer_id'                  => $customerId,
                'invoice_number'               => (string) $invoice['invoice_number'],
                'customer_outstanding_before'  => $customerOutstandingBefore,
                'customer_outstanding_after'   => $customerOutstandingAfter,
                'sale_outstanding_after'       => $saleOutstandingAfter,
                'active_payment_total'         => $paidBefore,
                'payments_preserved'           => true,
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Details for the Void Consolidated Invoice confirmation dialog.
     *
     * @return array<string, mixed>
     */
    public function getInvoiceVoidDetails(int $consolidatedInvoiceId): array
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        $financials = $this->computeInvoiceFinancials($consolidatedInvoiceId);
        $total = $this->moneyRound((float) $invoice['total_amount']);
        // Document "total paid" is only active payments posted against this CI.
        $paid = $this->moneyRound((float) $financials['payments_applied_to_ci']);
        $balance = $this->getBalance($consolidatedInvoiceId);

        $sales = $this->db->table('consolidated_invoice_sales AS cis')
            ->select('cis.sale_id, cis.amount AS attached_amount, sales.invoice_number')
            ->join('sales', 'sales.sale_id = cis.sale_id')
            ->where('cis.consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('cis.sale_id', 'asc')
            ->get()
            ->getResultArray();

        $saleRows = [];
        $saleOutstandingTotal = 0.0;
        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $outstanding = $this->accountLib->getSaleOutstanding($saleId);
            $saleOutstandingTotal += $outstanding;
            $saleRows[] = [
                'sale_id'                   => $saleId,
                'invoice_number'            => $sale['invoice_number'] ?? null,
                'attached_amount'           => $this->moneyRound((float) $sale['attached_amount']),
                'attached_amount_formatted' => to_currency($this->moneyRound((float) $sale['attached_amount'])),
                'outstanding'               => $outstanding,
                'outstanding_formatted'     => to_currency($outstanding),
            ];
        }
        $saleOutstandingTotal = $this->moneyRound($saleOutstandingTotal);
        $activePaymentCount = (int) $this->db->table('customer_account_payments')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('status', CA_STATUS_ACTIVE)
            ->countAllResults();

        return [
            'consolidated_invoice_id'      => (int) $invoice['consolidated_invoice_id'],
            'customer_id'                  => (int) $invoice['customer_id'],
            'invoice_number'               => (string) $invoice['invoice_number'],
            'invoice_date'                 => (string) $invoice['invoice_date'],
            'invoice_date_formatted'       => to_date(strtotime((string) $invoice['invoice_date'])),
            'total_amount'                 => $total,
            'total_amount_formatted'       => to_currency($total),
            'total_paid'                   => $paid,
            'total_paid_formatted'         => to_currency($paid),
            'balance'                      => $balance,
            'balance_formatted'            => to_currency($balance),
            'previously_paid_on_sales'     => $this->moneyRound((float) $financials['previously_paid_on_sales']),
            'sale_outstanding_total'       => $saleOutstandingTotal,
            'sale_outstanding_total_formatted' => to_currency($saleOutstandingTotal),
            'status'                       => (int) $invoice['status'],
            'voided'                       => (int) $invoice['status'] === CI_STATUS_CANCELLED,
            'void_reason'                  => $invoice['void_reason'] ?? null,
            'voided_at'                    => $invoice['voided_at'] ?? null,
            'voided_by'                    => isset($invoice['voided_by']) && $invoice['voided_by'] !== null
                ? (int) $invoice['voided_by']
                : null,
            'sales'                        => $saleRows,
            'active_payment_count'         => $activePaymentCount,
            'payments_will_remain_active'  => $activePaymentCount > 0,
            // Voiding the document does not change sale balances; only Void Payment does.
            'receivables_after_void'       => $saleOutstandingTotal,
            'receivables_after_void_formatted' => to_currency($saleOutstandingTotal),
        ];
    }

    public function getBalance(int $consolidatedInvoiceId): float
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);

        // Voided/cancelled documents are not receivable — balance is always zero.
        // Active CI balance tracks live sale outstanding on attached sales so
        // returns/credits/direct payments stay in sync with customer AR.
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return 0.0;
        }

        return $this->getAttachedSaleOutstandingTotal($consolidatedInvoiceId);
    }

    /**
     * Sum of current outstanding balances on sales attached to this CI.
     */
    public function getAttachedSaleOutstandingTotal(int $consolidatedInvoiceId): float
    {
        $saleIds = $this->db->table('consolidated_invoice_sales')
            ->select('sale_id')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->get()
            ->getResultArray();

        $total = 0.0;
        foreach ($saleIds as $row) {
            $outstanding = $this->accountLib->getSaleOutstanding((int) $row['sale_id']);
            if ($outstanding > 0) {
                $total += $outstanding;
            }
        }

        return $this->moneyRound($total);
    }

    public function getPaidAmount(int $consolidatedInvoiceId): float
    {
        $this->getInvoiceOrFail($consolidatedInvoiceId);
        $paidRow = $this->db->table('customer_account_payments')
            ->select('COALESCE(SUM(payment_amount), 0) AS paid', false)
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($paidRow['paid'] ?? 0));
    }

    public function refreshStatus(int $consolidatedInvoiceId): int
    {
        $invoice = $this->getInvoiceOrFail($consolidatedInvoiceId);
        if ((int) $invoice['status'] === CI_STATUS_CANCELLED) {
            return CI_STATUS_CANCELLED;
        }

        $balance = $this->getBalance($consolidatedInvoiceId);
        $total = $this->moneyRound((float) $invoice['total_amount']);
        $status = CI_STATUS_OPEN;

        if ($balance <= 0 && $total > 0) {
            $status = CI_STATUS_PAID;
        } elseif ($balance + 0.00001 >= $total) {
            $status = CI_STATUS_OPEN;
        } else {
            $status = CI_STATUS_PARTIALLY_PAID;
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
        $sales = $this->db->table('consolidated_invoice_sales AS cis')
            ->select('cis.sale_id, cis.amount AS attached_amount, sales.sale_time, sales.invoice_number, sales.sale_type, sales.customer_id')
            ->join('sales', 'sales.sale_id = cis.sale_id')
            ->where('cis.consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('sales.sale_time', 'asc')
            ->get()
            ->getResultArray();

        $saleIds = array_map(static fn (array $sale): int => (int) $sale['sale_id'], $sales);
        $summaryMap = $this->accountLib->getSalesFinancialSummaryMap($saleIds);

        $ciPaymentIds = [];
        $activeCiPaymentIds = [];
        // Include voided payments in history; only active ones affect paid totals.
        $payments = $this->db->table('customer_account_payments')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->orderBy('payment_time', 'asc')
            ->orderBy('payment_id', 'asc')
            ->get()
            ->getResultArray();

        $paymentRows = [];
        $paymentsAppliedToCi = 0.0;
        foreach ($payments as $payment) {
            $paymentId = (int) $payment['payment_id'];
            $isActive = (int) $payment['status'] === CA_STATUS_ACTIVE;
            $amount = $this->moneyRound((float) $payment['payment_amount']);
            $ciPaymentIds[] = $paymentId;
            if ($isActive) {
                $activeCiPaymentIds[] = $paymentId;
                $paymentsAppliedToCi += $amount;
            }
            $paymentRows[] = [
                'payment_id'     => $paymentId,
                'payment_type'   => (string) $payment['payment_type'],
                'payment_amount' => $amount,
                'payment_time'   => $payment['payment_time'],
                'reference_code' => (string) ($payment['reference_code'] ?? ''),
                'comment'        => $payment['comment'] ?? '',
                'status'         => (int) $payment['status'],
                'voided'         => !$isActive,
                'void_reason'    => $payment['void_reason'] ?? null,
                'voided_at'      => $payment['voided_at'] ?? null,
                'allocations'    => $this->accountLib->getPaymentAllocations($paymentId, $isActive),
            ];
        }
        $paymentsAppliedToCi = $this->moneyRound($paymentsAppliedToCi);

        $ciAllocatedBySale = [];
        if ($activeCiPaymentIds !== [] && $saleIds !== []) {
            $ciAllocRows = $this->db->table('customer_payment_allocations')
                ->select('sale_id, COALESCE(SUM(amount), 0) AS amount', false)
                ->whereIn('customer_account_payment_id', $activeCiPaymentIds)
                ->where('status', CA_STATUS_ACTIVE)
                ->whereIn('sale_id', $saleIds)
                ->groupBy('sale_id')
                ->get()
                ->getResultArray();
            foreach ($ciAllocRows as $row) {
                $ciAllocatedBySale[(int) $row['sale_id']] = $this->moneyRound((float) $row['amount']);
            }
        }

        $saleRows = [];
        $previouslyPaidOnSales = 0.0;
        $creditsAppliedToSales = 0.0;
        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $fin = $summaryMap[$saleId] ?? null;
            $originalTotal = $fin !== null
                ? $this->moneyRound((float) $fin['sale_total'])
                : $this->moneyRound($this->accountLib->computeSaleTotal($saleId));
            $paidOnSale = $fin !== null
                ? $this->moneyRound((float) $fin['pos_payments'] + (float) $fin['account_allocations'])
                : 0.0;
            $creditAppsOnSale = $fin !== null
                ? $this->moneyRound((float) ($fin['credit_applications'] ?? 0))
                : 0.0;
            $returnCreditsOnSale = $fin !== null
                ? $this->moneyRound((float) $fin['credits_returns'])
                : 0.0;
            $returnsOnSale = $this->moneyRound(-$returnCreditsOnSale);
            $currentBalance = $fin !== null
                ? $this->moneyRound((float) $fin['balance'])
                : $this->accountLib->getSaleOutstanding($saleId);
            $ciAllocated = $ciAllocatedBySale[$saleId] ?? 0.0;
            $priorPaid = $this->moneyRound(max(0, $paidOnSale - $ciAllocated));
            $previouslyPaidOnSales += $priorPaid;
            // Customer credit applied to attached sales (return credits stay in Returns section).
            $creditsAppliedToSales += $creditAppsOnSale;

            $saleRows[] = [
                'sale_id'              => $saleId,
                'sale_time'            => $sale['sale_time'],
                'invoice_number'       => $sale['invoice_number'],
                'sale_type'            => (int) $sale['sale_type'],
                'attached_amount'      => $this->moneyRound((float) $sale['attached_amount']),
                'original_total'       => $originalTotal,
                'paid_on_sale'         => $priorPaid,
                'credits_applied'      => $creditAppsOnSale,
                'returns'              => $returnsOnSale,
                'current_balance'      => $currentBalance,
                'outstanding'          => $currentBalance,
                'items'                => $this->getSaleItemRows($saleId),
            ];
        }

        $returnRows = $this->getReturnAdjustmentRows($saleIds);

        $originalSalesTotal = $this->moneyRound(array_sum(array_map(static fn (array $sale): float => (float) $sale['original_total'], $saleRows)));
        // Display-only return line items (may fund customer credit that is applied elsewhere).
        $returnsTotal = $this->moneyRound(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $returnRows)));
        $attachedTotal = $this->moneyRound((float) $invoice['total_amount']);
        $previouslyPaidOnSales = $this->moneyRound($previouslyPaidOnSales);
        $creditsAppliedToSales = $this->moneyRound($creditsAppliedToSales);
        // Document cash paid = active CI-linked payments only.
        $paid = $paymentsAppliedToCi;
        // Settled toward this CI document (cash on CI + credits on attached sales).
        $settledTotal = $this->moneyRound($paymentsAppliedToCi + $creditsAppliedToSales);
        // Live receivable on underlying sales (source of truth for document balance).
        $saleOutstandingTotal = $this->moneyRound(array_sum(array_map(
            static fn (array $sale): float => (float) $sale['current_balance'],
            $saleRows
        )));
        // Cancelled CI balance is always 0; otherwise balance = attached sale AR.
        $documentBalance = (int) $invoice['status'] === CI_STATUS_CANCELLED
            ? 0.0
            : $saleOutstandingTotal;

        $summary = [
            'original_sales_total'       => $originalSalesTotal,
            'returns_total'              => $returnsTotal,
            'consolidated_invoice_total' => $attachedTotal,
            'net_invoice_amount'         => $attachedTotal,
            'previously_paid_on_sales'   => $previouslyPaidOnSales,
            'credits_applied_to_sales'   => $creditsAppliedToSales,
            'payments_applied_to_ci'     => $paymentsAppliedToCi,
            'payments_applied'           => $paymentsAppliedToCi,
            'settled_total'              => $settledTotal,
            'balance_due'                => $documentBalance,
            'sale_outstanding_total'     => $saleOutstandingTotal,
        ];

        return [
            'invoice'         => $invoice,
            'sales'           => $saleRows,
            'returns'         => $returnRows,
            'items'           => $this->getGroupedSaleItemRows($saleRows),
            'payments'        => $paymentRows,
            'paid'            => $paid,
            'credits_applied' => $creditsAppliedToSales,
            'settled'         => $settledTotal,
            'balance'         => $documentBalance,
            'summary'         => $summary,
            'payment_summary' => $summary,
        ];
    }

    /**
     * Authoritative consolidated-invoice financial breakdown for reports/tests.
     *
     * @return array<string, float>
     */
    public function computeInvoiceFinancials(int $consolidatedInvoiceId): array
    {
        $details = $this->getInvoiceDetails($consolidatedInvoiceId);
        $summary = $details['summary'];

        return [
            'original_sales_total'     => (float) $summary['original_sales_total'],
            'returns_total'            => (float) $summary['returns_total'],
            'net_invoice_amount'       => (float) $summary['net_invoice_amount'],
            'previously_paid_on_sales' => (float) $summary['previously_paid_on_sales'],
            'credits_applied_to_sales' => (float) $summary['credits_applied_to_sales'],
            'payments_applied_to_ci'   => (float) $summary['payments_applied_to_ci'],
            'settled_total'            => (float) $summary['settled_total'],
            'balance_due'              => (float) $summary['balance_due'],
        ];
    }

    /**
     * Customer credit applications on sales attached to this CI.
     */
    public function getAttachedSaleCreditsTotal(int $consolidatedInvoiceId): float
    {
        $saleIds = $this->db->table('consolidated_invoice_sales')
            ->select('sale_id')
            ->where('consolidated_invoice_id', $consolidatedInvoiceId)
            ->get()
            ->getResultArray();
        if ($saleIds === []) {
            return 0.0;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['sale_id'], $saleIds);
        $row = $this->db->table('customer_credit_applications')
            ->select('COALESCE(SUM(amount), 0) AS credits', false)
            ->whereIn('sale_id', $ids)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['credits'] ?? 0));
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

        $saleModel = model(Sale::class);
        $builder = $this->db->table('sales');
        $returns = $builder
            ->whereIn('return_of_sale_id', $saleIds)
            ->where('sale_type', SALE_TYPE_RETURN)
            ->where('sale_status', COMPLETED)
            ->orderBy('sale_time', 'asc')
            ->orderBy('sale_id', 'asc')
            ->get()
            ->getResultArray();

        $returnRows = [];
        foreach ($returns as $returnSale) {
            $returnSaleId = (int) $returnSale['sale_id'];
            $originalSaleId = (int) ($returnSale['return_of_sale_id'] ?? 0);
            $relatedSale = $this->db->table('sales')
                ->select('invoice_number, sale_time')
                ->where('sale_id', $originalSaleId)
                ->get()
                ->getRowArray();

            foreach ($saleModel->get_sale_items_ordered($returnSaleId)->getResultArray() as $row) {
                $description = trim((string) ($row['name'] ?? $row['description'] ?? ''));
                if ($description === '') {
                    $description = 'Returned Item #' . ((int) ($row['item_id'] ?? 0));
                }

                $qty = abs((float) ($row['quantity_purchased'] ?? 0));
                $unitPrice = abs((float) ($row['item_unit_price'] ?? 0));
                $lineTotal = $this->calculateLineAmount($row);
                $amount = $this->moneyRound($lineTotal > 0 ? -$lineTotal : $lineTotal);

                $returnRows[] = [
                    'return_sale_id'    => $returnSaleId,
                    'return_number'     => (string) ($returnSale['invoice_number'] ?? 'Return #' . $returnSaleId),
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
                    'return_total'     => $this->moneyRound(abs((float) ($this->accountLib->computeSaleTotal($returnSaleId)))),
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
        $details = $this->getInvoiceDetails($consolidatedInvoiceId);
        $summary = $details['summary'];

        return [
            'original_sales_total' => $summary['original_sales_total'],
            'returns_total'        => $summary['returns_total'],
            'net_invoice_amount'   => $summary['net_invoice_amount'],
            'payments_applied'     => $summary['payments_applied'],
            'balance_due'          => $summary['balance_due'],
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
     * Refreshes status from live sale balances first so returns/credits are reflected.
     */
    public function countOpenForCustomer(int $customerId): int
    {
        $rows = $this->db->table('consolidated_invoices')
            ->select('consolidated_invoice_id')
            ->where('customer_id', $customerId)
            ->where('status !=', CI_STATUS_CANCELLED)
            ->get()
            ->getResultArray();

        $count = 0;
        foreach ($rows as $row) {
            $id = (int) $row['consolidated_invoice_id'];
            $this->refreshStatus($id);
            // Live balance is authoritative (paid/settled-by-return ⇒ 0).
            if ($this->getBalance($id) > 0.00001) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sum of open CI balances for a customer (matches attached sale AR on those invoices).
     */
    public function getOpenBalanceTotalForCustomer(int $customerId): float
    {
        $total = 0.0;
        foreach ($this->listOpenForCustomer($customerId) as $invoice) {
            $total += (float) $invoice['balance'];
        }

        return $this->moneyRound($total);
    }

    /**
     * Open consolidated invoices with a positive balance (for Add Payment targets).
     *
     * @return list<array<string, mixed>>
     */
    public function listOpenForCustomer(int $customerId): array
    {
        $rows = $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->where('status !=', CI_STATUS_CANCELLED)
            ->orderBy('invoice_date', 'asc')
            ->orderBy('consolidated_invoice_id', 'asc')
            ->get()
            ->getResultArray();

        $open = [];
        foreach ($rows as $row) {
            $id = (int) $row['consolidated_invoice_id'];
            $status = $this->refreshStatus($id);
            $balance = $this->getBalance($id);
            if ($balance <= 0.00001 || $status === CI_STATUS_PAID || $status === CI_STATUS_CANCELLED) {
                continue;
            }
            $row = $this->getInvoiceOrFail($id);
            $row['paid'] = $this->getPaidAmount($id);
            $row['balance'] = $balance;
            $row['status'] = $status;
            $open[] = $row;
        }

        return $open;
    }

    /**
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function listForCustomerPaginated(int $customerId, int $page = 1, int $perPage = Customer_account_lib::DEFAULT_PAGE_SIZE): array
    {
        $total = $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->countAllResults();

        if ($perPage === PHP_INT_MAX || $perPage <= 0) {
            $perPage = max(1, $total ?: 1);
            $page = 1;
            $offset = 0;
            $totalPages = 1;
        } else {
            $page = max(1, $page);
            $perPage = max(1, $perPage);
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
        }

        $builder = $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->orderBy('invoice_date', 'desc')
            ->orderBy('consolidated_invoice_id', 'desc');

        if ($perPage !== PHP_INT_MAX) {
            $builder->limit($perPage, $offset);
        }

        $rows = $builder->get()->getResultArray();

        foreach ($rows as &$row) {
            $id = (int) $row['consolidated_invoice_id'];
            $ciPayments = $this->getPaidAmount($id);
            $creditsApplied = $this->getAttachedSaleCreditsTotal($id);
            // List "Total Paid" includes CI cash payments + customer credit applied to attached sales
            // so Invoice Total − Total Paid aligns with Balance when credits settle part of the CI.
            $row['ci_payments'] = $ciPayments;
            $row['credits_applied'] = $creditsApplied;
            $row['paid'] = $this->moneyRound($ciPayments + $creditsApplied);
            $row['balance'] = $this->getBalance($id);
            $row['status'] = $this->refreshStatus($id);
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
