<?php

namespace App\Libraries;

use App\Models\Customer_account_payment;
use App\Models\Customer_credit_application;
use App\Models\Customer_credit_refund;
use App\Models\Customer_payment_allocation;
use App\Models\Customer_return_credit;
use App\Models\Customer_return_credit_allocation;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Authoritative customer receivables engine.
 *
 * Outstanding = sale total − non-Due POS payments − active allocations − active return credits.
 * Due placeholders are never cash. Account payments soft-void only. Customer credit via returns only.
 */
class Customer_account_lib
{
    public const DEFAULT_PAGE_SIZE = 25;

    /** @var list<int> */
    private const RECEIVABLE_SALE_TYPES = [SALE_TYPE_POS, SALE_TYPE_INVOICE];

    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
        helper(['locale', 'tabular']);
    }

    /**
     * @return list<string>
     */
    public function getDueLabels(): array
    {
        return array_values(array_unique([
            'Due',
            lang('Sales.due'),
        ]));
    }

    public function isDuePaymentType(string $paymentType): bool
    {
        $normalized = trim($paymentType);
        foreach ($this->getDueLabels() as $label) {
            if (strcasecmp($normalized, trim((string) $label)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getAccountPaymentOptions(): array
    {
        $options = get_payment_options();
        $exclude = array_unique([
            lang('Sales.due'),
            'Due',
            lang('Sales.cash_adjustment'),
        ]);
        $giftcardPrefix = lang('Sales.giftcard');

        $filtered = [];
        foreach ($options as $key => $label) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            if (str_starts_with((string) $key, $giftcardPrefix)) {
                continue;
            }
            $filtered[$key] = $label;
        }

        return $filtered;
    }

    public function isValidAccountPaymentType(string $paymentType): bool
    {
        return array_key_exists($paymentType, $this->getAccountPaymentOptions());
    }

    public function requiresReferenceCode(string $paymentType): bool
    {
        return in_array($paymentType, get_reference_code_payment_types(), true);
    }

    public function getSaleOutstanding(int $saleId): float
    {
        $summary = $this->getSaleFinancialSummary($saleId);

        return $summary['balance'];
    }

    /**
     * Validate a return that will reduce a specific sale's outstanding balance.
     *
     * @throws RuntimeException
     */
    public function assertReturnOutstandingAmount(int $saleId, float $returnAmount): void
    {
        $returnAmount = $this->moneyRound(abs($returnAmount));

        if ($returnAmount <= 0) {
            throw new RuntimeException(lang('Accounts.error_return_not_outstanding'));
        }

        // Return may exceed this sale's outstanding: allocation applies what it can
        // against the linked sale and other receivables; any remainder becomes credit.
        // Do not require returnAmount <= getSaleOutstanding($saleId).
    }

    /**
     * Non-Due cash + active account allocations (excludes return credits).
     */
    public function getSalePaid(int $saleId): float
    {
        $summary = $this->getSaleFinancialSummary($saleId);

        return $summary['payments_applied'];
    }

    public function getCustomerOutstanding(int $customerId): float
    {
        $total = 0.0;

        foreach ($this->getCustomerCompletedSaleIds($customerId) as $saleId) {
            $outstanding = $this->getSaleOutstanding($saleId);

            // Outstanding can never be negative.
            $total += $this->moneyMaxZero($outstanding);
        }

        return $this->moneyRound($total);
    }

    /**
     * Customer spending statistics.
     *
     * IMPORTANT:
     * - Only completed POS/invoice sales are included.
     * - SALE_TYPE_RETURN is never included as a standalone sale.
     * - Canceled sales are never included.
     * - ALL completed returns reduce historical spending, regardless of
     *   whether the return was settled as cash or customer credit.
     * - Customer credit is a separate balance and must not be deducted
     *   again when that credit is later applied to a sale.
     * - Returned quantities are deducted from the original sale quantities.
     *
     * @return array{
     *     total:float,
     *     min:float,
     *     max:float,
     *     average:float,
     *     quantity:float,
     *     avg_discount:float
     * }
     */
    public function getCustomerStats(int $customerId): array
    {
        /*
         * Get all completed receivable sales for this customer.
         *
         * Returns are excluded because they are handled separately below
         * against their original sale.
         */
        $sales = $this->db->table('sales')
            ->select('sale_id')
            ->where('customer_id', $customerId)
            ->where('sale_status', COMPLETED)
            ->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES)
            ->orderBy('sale_id', 'asc')
            ->get()
            ->getResultArray();

        $saleTotals = [];

        /*
         * Track net quantity across the customer's purchases.
         */
        $totalQuantity = 0.0;

        /*
         * Track discount using the original completed sale items.
         *
         * This preserves the existing behavior for average discount.
         */
        $discountValues = [];

        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];

            /*
             * Original sale amount.
             */
            $saleTotal = $this->moneyRound(
                $this->computeSaleTotal($saleId)
            );

            /*
             * Find all completed returns made against this sale.
             *
             * IMPORTANT:
             * Do NOT check customer_return_credits here.
             *
             * A return reduces historical spending regardless of whether
             * the customer received cash or customer credit.
             */
            $returnSales = $this->db->table('sales')
                ->select('sale_id')
                ->where('customer_id', $customerId)
                ->where('return_of_sale_id', $saleId)
                ->where('sale_type', SALE_TYPE_RETURN)
                ->where('sale_status', COMPLETED)
                ->orderBy('sale_id', 'asc')
                ->get()
                ->getResultArray();

            $totalReturnAmount = 0.0;

            foreach ($returnSales as $returnSale) {
                $returnSaleId = (int) $returnSale['sale_id'];

                /*
                 * Return sales do not have a reliable standalone `total`
                 * column. Use the same authoritative calculation used
                 * elsewhere in the accounting system.
                 */
                $returnAmount = $this->moneyRound(
                    abs($this->computeSaleTotal($returnSaleId))
                );

                $totalReturnAmount = $this->moneyRound(
                    $totalReturnAmount + $returnAmount
                );
            }

            /*
             * Historical spending is:
             *
             *     Original Sale
             *     - ALL Returns
             *
             * Settlement method does not matter.
             */
            $netSpent = $this->moneyRound(
                $saleTotal - $totalReturnAmount
            );

            /*
             * Never allow returns to make a sale produce negative
             * spending statistics.
             */
            $netSpent = $this->moneyMaxZero($netSpent);

            $saleTotals[] = $netSpent;

            /*
             * Calculate original sale quantity.
             */
            $saleQuantityRow = $this->db->table('sales_items AS si')
                ->select(
                    'COALESCE(SUM(si.quantity_purchased), 0) AS quantity',
                    false
                )
                ->where('si.sale_id', $saleId)
                ->get()
                ->getRowArray();

            $saleQuantity = (float) (
                $saleQuantityRow['quantity'] ?? 0
            );

            /*
             * Calculate quantities returned from this sale.
             *
             * Because returns are linked through return_of_sale_id,
             * subtract the quantity of all completed returns.
             */
            $returnQuantityRow = $this->db->table('sales_items AS rsi')
                ->select(
                    'COALESCE(SUM(rsi.quantity_purchased), 0) AS quantity',
                    false
                )
                ->join(
                    'sales AS rs',
                    'rs.sale_id = rsi.sale_id'
                )
                ->where('rs.customer_id', $customerId)
                ->where('rs.return_of_sale_id', $saleId)
                ->where('rs.sale_type', SALE_TYPE_RETURN)
                ->where('rs.sale_status', COMPLETED)
                ->get()
                ->getRowArray();

            $returnedQuantity = (float) (
                $returnQuantityRow['quantity'] ?? 0
            );

            /*
             * Return quantities are normally stored as positive quantities,
             * so subtract them from the original purchased quantity.
             */
            $netQuantity = max(
                0.0,
                $saleQuantity - abs($returnedQuantity)
            );

            $totalQuantity += $netQuantity;

            /*
             * Collect discounts from the original sale items.
             */
            $saleDiscountRows = $this->db->table('sales_items')
                ->select('discount')
                ->where('sale_id', $saleId)
                ->get()
                ->getResultArray();

            foreach ($saleDiscountRows as $discountRow) {
                $discountValues[] = (float) ($discountRow['discount'] ?? 0);
            }
        }

        $count = count($saleTotals);

        if ($count === 0) {
            return [
                'total'        => 0.0,
                'min'          => 0.0,
                'max'          => 0.0,
                'average'      => 0.0,
                'quantity'     => 0.0,
                'avg_discount' => 0.0,
            ];
        }

        /*
         * Spending statistics are based on NET spending per original sale.
         */
        $total = $this->moneyRound(
            array_sum($saleTotals)
        );

        $min = $this->moneyRound(
            min($saleTotals)
        );

        $max = $this->moneyRound(
            max($saleTotals)
        );

        $average = $this->moneyRound(
            $total / $count
        );

        $quantity = round(
            $totalQuantity,
            quantity_decimals()
        );

        /*
         * Preserve the existing average-discount behavior:
         * average of discount values on original sale items.
         */
        $avgDiscount = $this->moneyRound(
            !empty($discountValues)
                ? array_sum($discountValues) / count($discountValues)
                : 0.0
        );

        return [
            'total'        => $total,
            'min'          => $min,
            'max'          => $max,
            'average'      => $average,
            'quantity'     => $quantity,
            'avg_discount' => $avgDiscount,
        ];
    }

    /**
     * @param list<int> $saleIds
     * @return array<int, float>
     */
    public function getSalesOutstandingMap(array $saleIds): array
    {
        $map = [];
        foreach ($this->getSalesFinancialSummaryMap($saleIds) as $saleId => $summary) {
            $map[$saleId] = $summary['balance'];
        }

        return $map;
    }

    /**
     * Authoritative Sales History / Edit Sale DTO.
     *
     * @return array{
     *   sale_id:int, sale_total:float, payments_applied:float, credits_returns:float,
     *   balance:float, status:string, amount_tendered:?float, change:?float,
     *   pos_payments:float, account_allocations:float
     * }
     */
    public function getSaleFinancialSummary(int $saleId): array
    {
        $map = $this->getSalesFinancialSummaryMap([$saleId]);

        return $map[$saleId] ?? [
            'sale_id'              => $saleId,
            'sale_total'           => 0.0,
            'payments_applied'     => 0.0,
            'credits_returns'      => 0.0,
            'balance'              => 0.0,
            'status'               => SALE_PAY_STATUS_PAID,
            'amount_tendered'      => null,
            'change'               => null,
            'pos_payments'         => 0.0,
            'account_allocations'  => 0.0,
        ];
    }

    /**
     * @param list<int> $saleIds
     * @return array<int, array<string, mixed>>
     */
    public function getSalesFinancialSummaryMap(array $saleIds): array
    {
        $result = [];
        foreach (array_unique(array_map('intval', $saleIds)) as $saleId) {
            if ($saleId <= 0) {
                continue;
            }

            $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
            if ($sale === null) {
                continue;
            }

            $saleStatus = (int) $sale['sale_status'];
            $saleType = (int) $sale['sale_type'];
            $saleTotal = $this->computeSaleTotal($saleId);
            $posPayments = $this->getSalePosPayments($saleId);
            $accountAlloc = $this->getSaleAllocated($saleId);
            $returnCredits = $this->getSaleReturnCredits($saleId);
            $creditApps = $this->getSaleCreditApplications($saleId);

            $paymentsApplied = $this->moneyRound($posPayments + $accountAlloc + $creditApps);
            $creditsReturns = $this->moneyRound($returnCredits);
            $balance = 0.0;
            $status = SALE_PAY_STATUS_PAID;

            if ($saleStatus === CANCELED) {
                $status = SALE_PAY_STATUS_CANCELLED;
                $balance = 0.0;
            } elseif (
                $saleStatus === COMPLETED
                && in_array($saleType, self::RECEIVABLE_SALE_TYPES, true)
            ) {
                $balance = $this->moneyMaxZero($saleTotal - $paymentsApplied - $creditsReturns);
                if ($balance <= 0) {
                    $status = SALE_PAY_STATUS_PAID;
                } elseif ($paymentsApplied <= 0 && $creditsReturns <= 0) {
                    $status = SALE_PAY_STATUS_UNPAID;
                } else {
                    $status = SALE_PAY_STATUS_PARTIAL;
                }
            } else {
                $balance = 0.0;
                $status = SALE_PAY_STATUS_PAID;
            }

            $tendered = $this->getSaleAmountTenderedIncludingDue($saleId);
            $change = $this->moneyRound($tendered - $saleTotal);

            $result[$saleId] = [
                'sale_id'             => $saleId,
                'sale_total'          => $this->moneyRound($saleTotal),
                'payments_applied'    => $paymentsApplied,
                'credits_returns'     => $creditsReturns,
                'balance'             => $balance,
                'status'              => $status,
                'amount_tendered'     => $this->moneyRound($tendered),
                'change'              => $change > 0 ? $change : 0.0,
                'pos_payments'        => $posPayments,
                'account_allocations' => $accountAlloc,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function getOutstandingSales(int $customerId, bool $forConsolidation = false, array $filters = []): array
    {
        $builder = $this->db->table('sales');
        $builder->select('sale_id, sale_time, invoice_number, sale_type, comment');
        $builder->where('customer_id', $customerId);
        $builder->where('sale_status', COMPLETED);
        $builder->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES);
        if (!$forConsolidation) {
            $this->applySaleDisplayFilters($builder, $filters);
        }
        $builder->orderBy('sale_time', 'asc');
        $sales = $builder->get()->getResultArray();

        $statusFilter = (string) ($filters['status'] ?? '');
        $rows = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $summary = $this->getSaleFinancialSummary($saleId);
            $outstanding = $summary['balance'];
            if ($outstanding <= 0) {
                continue;
            }

            $onActiveCi = $this->isSaleOnActiveConsolidatedInvoice($saleId);
            if ($forConsolidation && $onActiveCi) {
                continue;
            }

            if (!$forConsolidation && $statusFilter === 'unpaid' && $summary['status'] !== SALE_PAY_STATUS_UNPAID) {
                continue;
            }
            if (!$forConsolidation && $statusFilter === 'partially_paid' && $summary['status'] !== SALE_PAY_STATUS_PARTIAL) {
                continue;
            }

            $rows[] = [
                'sale_id'                 => $saleId,
                'sale_time'               => $sale['sale_time'],
                'invoice_number'          => $sale['invoice_number'],
                'sale_type'               => (int) $sale['sale_type'],
                'comment'                 => $sale['comment'],
                'total'                   => $summary['sale_total'],
                'paid'                    => $summary['payments_applied'],
                'credits'                 => $summary['credits_returns'],
                'outstanding'             => $outstanding,
                'on_consolidated_invoice' => $onActiveCi,
                'consolidated_invoice_id' => $onActiveCi ? $this->getActiveConsolidatedInvoiceId($saleId) : null,
                'pay_status'              => $summary['status'],
            ];
        }

        return $rows;
    }

    public function isSaleOnActiveConsolidatedInvoice(int $saleId): bool
    {
        return $this->getActiveConsolidatedInvoiceId($saleId) !== null;
    }

    public function getActiveConsolidatedInvoiceId(int $saleId): ?int
    {
        $row = $this->db->table('consolidated_invoice_sales AS cis')
            ->select('cis.consolidated_invoice_id')
            ->join('consolidated_invoices AS ci', 'ci.consolidated_invoice_id = cis.consolidated_invoice_id')
            ->where('cis.sale_id', $saleId)
            ->where('ci.status !=', CI_STATUS_CANCELLED)
            ->get()
            ->getRowArray();

        return $row ? (int) $row['consolidated_invoice_id'] : null;
    }

    public function saleHasFinancialReferences(int $saleId): bool
    {
        $allocCount = $this->db->table('customer_payment_allocations')
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->countAllResults();
        if ($allocCount > 0) {
            return true;
        }

        $returnAlloc = $this->db->table('customer_return_credit_allocations')
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->countAllResults();
        if ($returnAlloc > 0) {
            return true;
        }

        $creditApp = $this->db->table('customer_credit_applications')
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->countAllResults();
        if ($creditApp > 0) {
            return true;
        }

        $asOriginal = $this->db->table('customer_return_credits')
            ->where('original_sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->countAllResults();
        if ($asOriginal > 0) {
            return true;
        }

        return $this->isSaleOnActiveConsolidatedInvoice($saleId);
    }

    /**
     * Data for Change Allocation UI: payment total + sales with room (including amounts currently held by this payment).
     *
     * @return array{
     *   payment_id:int,
     *   payment_amount:float,
     *   payment_type:string,
     *   allocations:list<array{sale_id:int, amount:float}>,
     *   sales:list<array{sale_id:int, outstanding:float, current_amount:float, max_amount:float}>
     * }
     */
    public function getReallocateFormData(int $paymentId): array
    {
        $payment = $this->db->table('customer_account_payments')
            ->where('payment_id', $paymentId)
            ->get()
            ->getRowArray();

        if ($payment === null) {
            throw new RuntimeException(lang('Accounts.error_payment_not_found'));
        }
        if ((int) $payment['status'] !== CA_STATUS_ACTIVE) {
            throw new RuntimeException(lang('Accounts.error_payment_voided'));
        }

        $customerId = (int) $payment['customer_id'];
        $paymentAmount = $this->moneyRound((float) $payment['payment_amount']);
        $currentAllocs = $this->getPaymentAllocations($paymentId);
        $currentBySale = [];
        foreach ($currentAllocs as $alloc) {
            $currentBySale[$alloc['sale_id']] = $this->moneyRound((float) $alloc['amount']);
        }

        $sales = [];
        foreach ($this->getOutstandingSales($customerId, false) as $sale) {
            $saleId = (int) $sale['sale_id'];
            $current = $currentBySale[$saleId] ?? 0.0;
            // Soft-void of this payment's allocs frees current back into outstanding before re-apply.
            $maxAmount = $this->moneyRound((float) $sale['outstanding'] + $current);
            if ($maxAmount <= 0 && $current <= 0) {
                continue;
            }
            $sales[] = [
                'sale_id'         => $saleId,
                'outstanding'     => $this->moneyRound((float) $sale['outstanding']),
                'current_amount'  => $current,
                'max_amount'      => $maxAmount,
            ];
            unset($currentBySale[$saleId]);
        }

        // Include sales that currently hold this payment but are otherwise fully paid.
        foreach ($currentBySale as $saleId => $current) {
            if ($current <= 0) {
                continue;
            }
            $sales[] = [
                'sale_id'        => (int) $saleId,
                'outstanding'    => 0.0,
                'current_amount' => $current,
                'max_amount'     => $current,
            ];
        }

        usort($sales, static fn (array $a, array $b): int => $a['sale_id'] <=> $b['sale_id']);

        return [
            'payment_id'     => $paymentId,
            'payment_amount' => $paymentAmount,
            'payment_type'   => (string) $payment['payment_type'],
            'allocations'    => $currentAllocs,
            'sales'          => $sales,
        ];
    }

    /**
     * @return list<array{sale_id:int, amount:float}>
     */
    public function getPaymentAllocations(int $paymentId, bool $activeOnly = true): array
    {
        $builder = $this->db->table('customer_payment_allocations AS cpa')
            ->select('cpa.sale_id, cpa.amount')
            ->join('customer_account_payments AS cap', 'cap.payment_id = cpa.customer_account_payment_id')
            ->where('cpa.customer_account_payment_id', $paymentId);
        if ($activeOnly) {
            $builder->where('cpa.status', CA_STATUS_ACTIVE)
                ->where('cap.status', CA_STATUS_ACTIVE);
        }

        $rows = $builder->orderBy('cpa.allocation_id', 'asc')->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'sale_id' => (int) $row['sale_id'],
            'amount'  => (float) $row['amount'],
        ], $rows);
    }

    /**
     * @param list<array{sale_id:int, amount:float}>|null $allocations
     * @return array{payment_id:int, allocations:list<array{sale_id:int, amount:float}>, idempotent?:bool}
     */
    public function recordPayment(
        int $customerId,
        float $amount,
        string $paymentType,
        int $employeeId,
        ?string $referenceCode = null,
        ?string $comment = null,
        ?string $paymentTime = null,
        ?int $consolidatedInvoiceId = null,
        ?array $allocations = null,
        ?string $idempotencyKey = null
    ): array {
        $amount = $this->moneyRound($amount);
        if ($amount <= 0) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        if (!$this->isValidAccountPaymentType($paymentType)) {
            throw new RuntimeException(lang('Accounts.error_invalid_payment_type'));
        }

        if ($this->requiresReferenceCode($paymentType) && ($referenceCode === null || trim($referenceCode) === '')) {
            throw new RuntimeException(lang('Accounts.error_reference_required'));
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->db->table('customer_account_payments')
                ->where('customer_id', $customerId)
                ->where('idempotency_key', trim($idempotencyKey))
                ->get()
                ->getRowArray();
            if ($existing !== null) {
                $paymentId = (int) $existing['payment_id'];

                return [
                    'payment_id'  => $paymentId,
                    'allocations' => $this->getPaymentAllocations($paymentId),
                    'idempotent'  => true,
                ];
            }
        }

        if ($consolidatedInvoiceId !== null) {
            $ci = $this->db->table('consolidated_invoices')
                ->where('consolidated_invoice_id', $consolidatedInvoiceId)
                ->get()
                ->getRowArray();
            if ($ci === null || (int) $ci['customer_id'] !== $customerId) {
                throw new RuntimeException(lang('Accounts.error_different_customer'));
            }
            if ((int) $ci['status'] === CI_STATUS_CANCELLED) {
                throw new RuntimeException(lang('Accounts.status_cancelled'));
            }
        }

        $this->db->transStart();

        try {
            if ($allocations === null) {
                if ($consolidatedInvoiceId === null) {
                    throw new RuntimeException(lang('Accounts.error_allocation_required'));
                }
                $allocations = $this->buildFifoAllocations($customerId, $amount, $consolidatedInvoiceId);
            }

            $allocations = $this->mergeAllocationsBySaleId($allocations);
            if ($allocations === []) {
                throw new RuntimeException(lang('Accounts.error_allocation_required'));
            }

            $saleIds = array_map(static fn (array $a): int => (int) $a['sale_id'], $allocations);
            sort($saleIds);
            $this->lockSalesForUpdate($saleIds);

            $allocTotal = 0.0;
            foreach ($allocations as $allocation) {
                $allocAmount = $this->moneyRound((float) $allocation['amount']);
                $saleId = (int) $allocation['sale_id'];
                if ($allocAmount <= 0) {
                    throw new RuntimeException(lang('Accounts.cannot_overpay'));
                }

                $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
                if ($sale === null || (int) $sale['customer_id'] !== $customerId) {
                    throw new RuntimeException(lang('Accounts.error_different_customer'));
                }
                if ((int) $sale['sale_status'] !== COMPLETED) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }
                if (!in_array((int) $sale['sale_type'], self::RECEIVABLE_SALE_TYPES, true)) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }

                $activeCiId = $this->getActiveConsolidatedInvoiceId($saleId);
                if ($activeCiId !== null) {
                    if ($consolidatedInvoiceId === null || $activeCiId !== $consolidatedInvoiceId) {
                        throw new RuntimeException(lang('Accounts.error_sale_on_consolidated_invoice'));
                    }
                } elseif ($consolidatedInvoiceId !== null) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_on_invoice'));
                }

                $outstanding = $this->getSaleOutstanding($saleId);
                if ($allocAmount - $outstanding > 0.00001) {
                    throw new RuntimeException(lang('Accounts.cannot_overpay'));
                }

                $allocTotal += $allocAmount;
            }

            $allocTotal = $this->moneyRound($allocTotal);
            if (abs($allocTotal - $amount) > 0.00001) {
                throw new RuntimeException(lang('Accounts.cannot_overpay'));
            }

            $paymentModel = model(Customer_account_payment::class);
            $paymentModel->insert([
                'customer_id'             => $customerId,
                'consolidated_invoice_id' => $consolidatedInvoiceId,
                'payment_type'            => $paymentType,
                'payment_amount'          => $amount,
                'payment_time'            => $paymentTime ?? date('Y-m-d H:i:s'),
                'reference_code'          => $referenceCode ?? '',
                'comment'                 => $comment,
                'employee_id'             => $employeeId,
                'status'                  => CA_STATUS_ACTIVE,
                'idempotency_key'         => ($idempotencyKey !== null && trim($idempotencyKey) !== '')
                    ? trim($idempotencyKey)
                    : null,
            ]);
            $paymentId = (int) $paymentModel->getInsertID();

            $allocationModel = model(Customer_payment_allocation::class);
            $savedAllocations = [];
            foreach ($allocations as $allocation) {
                $allocAmount = $this->moneyRound((float) $allocation['amount']);
                $saleId = (int) $allocation['sale_id'];
                $allocationModel->insert([
                    'customer_account_payment_id' => $paymentId,
                    'sale_id'                     => $saleId,
                    'amount'                      => $allocAmount,
                    'status'                      => CA_STATUS_ACTIVE,
                ]);
                $savedAllocations[] = ['sale_id' => $saleId, 'amount' => $allocAmount];
            }

            $this->writeAuditEvent($customerId, 'payment_recorded', 'payment', $paymentId, [
                'amount'      => $amount,
                'allocations' => $savedAllocations,
            ], $employeeId);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Payment transaction failed.');
            }

            return ['payment_id' => $paymentId, 'allocations' => $savedAllocations];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function voidPayment(int $paymentId, int $employeeId, ?string $reason = null): void
    {
        $this->db->transStart();

        try {
            $payment = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('customer_account_payments') . ' WHERE payment_id = ? FOR UPDATE',
                [$paymentId]
            )->getRowArray();

            if ($payment === null) {
                throw new RuntimeException(lang('Accounts.error_payment_not_found'));
            }
            if ((int) $payment['status'] === CA_STATUS_VOIDED) {
                if ($payment['consolidated_invoice_id'] !== null) {
                    (new Consolidated_invoice_lib($this))->refreshStatus((int) $payment['consolidated_invoice_id']);
                }
                $this->db->transComplete();

                return;
            }

            $allocs = $this->db->table('customer_payment_allocations')
                ->where('customer_account_payment_id', $paymentId)
                ->where('status', CA_STATUS_ACTIVE)
                ->get()
                ->getResultArray();

            $saleIds = array_map(static fn (array $a): int => (int) $a['sale_id'], $allocs);
            sort($saleIds);
            $this->lockSalesForUpdate($saleIds);

            $this->db->table('customer_payment_allocations')
                ->where('customer_account_payment_id', $paymentId)
                ->where('status', CA_STATUS_ACTIVE)
                ->update(['status' => CA_STATUS_VOIDED]);

            $this->db->table('customer_account_payments')
                ->where('payment_id', $paymentId)
                ->update([
                    'status'      => CA_STATUS_VOIDED,
                    'voided_at'   => date('Y-m-d H:i:s'),
                    'voided_by'   => $employeeId,
                    'void_reason' => $reason,
                ]);

            $this->writeAuditEvent((int) $payment['customer_id'], 'payment_voided', 'payment', $paymentId, [
                'reason' => $reason,
            ], $employeeId);

            if ($payment['consolidated_invoice_id'] !== null) {
                (new Consolidated_invoice_lib($this))->refreshStatus((int) $payment['consolidated_invoice_id']);
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Void payment failed.');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @param list<array{sale_id:int, amount:float}> $allocations
     */
    public function reallocatePayment(int $paymentId, array $allocations, int $employeeId): array
    {
        $this->db->transStart();

        try {
            $payment = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('customer_account_payments') . ' WHERE payment_id = ? FOR UPDATE',
                [$paymentId]
            )->getRowArray();

            if ($payment === null) {
                throw new RuntimeException(lang('Accounts.error_payment_not_found'));
            }
            if ((int) $payment['status'] !== CA_STATUS_ACTIVE) {
                throw new RuntimeException(lang('Accounts.error_payment_voided'));
            }

            $customerId = (int) $payment['customer_id'];
            $amount = $this->moneyRound((float) $payment['payment_amount']);
            $consolidatedInvoiceId = $payment['consolidated_invoice_id'] !== null
                ? (int) $payment['consolidated_invoice_id']
                : null;

            $allocations = $this->mergeAllocationsBySaleId($allocations);
            if ($allocations === []) {
                throw new RuntimeException(lang('Accounts.error_allocation_required'));
            }

            // Soft-void existing allocations first so outstanding recalculates correctly
            $this->db->table('customer_payment_allocations')
                ->where('customer_account_payment_id', $paymentId)
                ->where('status', CA_STATUS_ACTIVE)
                ->update(['status' => CA_STATUS_VOIDED]);

            $saleIds = array_map(static fn (array $a): int => (int) $a['sale_id'], $allocations);
            sort($saleIds);
            $this->lockSalesForUpdate($saleIds);

            $allocTotal = 0.0;
            $saved = [];
            $allocationModel = model(Customer_payment_allocation::class);

            foreach ($allocations as $allocation) {
                $allocAmount = $this->moneyRound((float) $allocation['amount']);
                $saleId = (int) $allocation['sale_id'];
                if ($allocAmount <= 0) {
                    throw new RuntimeException(lang('Accounts.cannot_overpay'));
                }

                $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
                if ($sale === null || (int) $sale['customer_id'] !== $customerId) {
                    throw new RuntimeException(lang('Accounts.error_different_customer'));
                }
                if ((int) $sale['sale_status'] !== COMPLETED
                    || !in_array((int) $sale['sale_type'], self::RECEIVABLE_SALE_TYPES, true)
                ) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
                }

                $activeCiId = $this->getActiveConsolidatedInvoiceId($saleId);
                if ($activeCiId !== null) {
                    if ($consolidatedInvoiceId === null || $activeCiId !== $consolidatedInvoiceId) {
                        throw new RuntimeException(lang('Accounts.error_sale_on_consolidated_invoice'));
                    }
                } elseif ($consolidatedInvoiceId !== null) {
                    throw new RuntimeException(lang('Accounts.error_sale_not_on_invoice'));
                }

                $outstanding = $this->getSaleOutstanding($saleId);
                if ($allocAmount - $outstanding > 0.00001) {
                    throw new RuntimeException(lang('Accounts.cannot_overpay'));
                }

                $allocationModel->insert([
                    'customer_account_payment_id' => $paymentId,
                    'sale_id'                     => $saleId,
                    'amount'                      => $allocAmount,
                    'status'                      => CA_STATUS_ACTIVE,
                ]);
                $saved[] = ['sale_id' => $saleId, 'amount' => $allocAmount];
                $allocTotal += $allocAmount;
            }

            if (abs($this->moneyRound($allocTotal) - $amount) > 0.00001) {
                throw new RuntimeException(lang('Accounts.cannot_overpay'));
            }

            $this->writeAuditEvent($customerId, 'payment_reallocated', 'payment', $paymentId, [
                'allocations' => $saved,
            ], $employeeId);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Reallocate payment failed.');
            }

            return ['payment_id' => $paymentId, 'allocations' => $saved];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Per-item returnable quantities for an original sale (completed returns only).
     *
     * @return array<int, array{item_id:int, item_number:?string, name:string, sold:float, returned:float, remaining:float, unit_price:float}>
     */
    public function getSaleReturnableItems(int $originalSaleId, ?int $excludeReturnSaleId = null): array
    {
        $soldRows = $this->db->table('sales_items AS si')
            ->select('si.item_id, si.item_unit_price, SUM(si.quantity_purchased) AS sold_qty, MAX(i.name) AS name, MAX(i.item_number) AS item_number')
            ->join('items AS i', 'i.item_id = si.item_id', 'left')
            ->where('si.sale_id', $originalSaleId)
            ->groupBy('si.item_id, si.item_unit_price')
            ->get()
            ->getResultArray();

        $builder = $this->db->table('sales_items AS si')
            ->select('si.item_id, SUM(ABS(si.quantity_purchased)) AS returned_qty')
            ->join('sales AS s', 's.sale_id = si.sale_id')
            ->where('s.return_of_sale_id', $originalSaleId)
            ->where('s.sale_status', COMPLETED)
            ->where('s.sale_type', SALE_TYPE_RETURN);
        if ($excludeReturnSaleId !== null && $excludeReturnSaleId > 0) {
            $builder->where('s.sale_id !=', $excludeReturnSaleId);
        }
        $returnedRows = $builder->groupBy('si.item_id')->get()->getResultArray();

        $returnedByItem = [];
        foreach ($returnedRows as $row) {
            $returnedByItem[(int) $row['item_id']] = (float) $row['returned_qty'];
        }

        $result = [];
        foreach ($soldRows as $row) {
            $itemId = (int) $row['item_id'];
            $sold = (float) $row['sold_qty'];
            if ($sold <= 0) {
                continue;
            }
            $returned = $returnedByItem[$itemId] ?? 0.0;
            $remaining = max(0.0, $sold - $returned);
            $result[$itemId] = [
                'item_id'     => $itemId,
                'item_number' => $row['item_number'] ?? null,
                'name'        => (string) ($row['name'] ?? ''),
                'sold'        => $this->moneyRound($sold),
                'returned'    => $this->moneyRound($returned),
                'remaining'   => $this->moneyRound($remaining),
                'unit_price'  => (float) $row['item_unit_price'],
            ];
        }

        return $result;
    }

    /**
     * Determine whether a completed sale has any remaining quantity
     * that can be returned.
     */
    public function saleHasReturnableQuantity(int $originalSaleId): bool
    {
        $returnableItems = $this->getSaleReturnableItems($originalSaleId);

        foreach ($returnableItems as $item) {
            if ((float) $item['remaining'] > 0.00001) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate cart return lines against remaining returnable quantities.
     *
     * @param list<array<string,mixed>> $cartItems
     * @throws RuntimeException
     */
    public function assertReturnCartWithinRemaining(int $originalSaleId, array $cartItems, ?int $excludeReturnSaleId = null): void
    {
        $returnable = $this->getSaleReturnableItems($originalSaleId, $excludeReturnSaleId);
        $requested = [];
        foreach ($cartItems as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = abs((float) ($line['quantity'] ?? $line['quantity_purchased'] ?? 0));
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $requested[$itemId] = ($requested[$itemId] ?? 0.0) + $qty;
        }

        foreach ($requested as $itemId => $qty) {
            if (!isset($returnable[$itemId])) {
                throw new RuntimeException(lang('Sales.return_item_not_on_sale'));
            }
            if ($qty - $returnable[$itemId]['remaining'] > 0.00001) {
                throw new RuntimeException(lang('Sales.return_quantity_exceeds_remaining', [
                    $returnable[$itemId]['name'] !== '' ? $returnable[$itemId]['name'] : ('#' . $itemId),
                    (string) $returnable[$itemId]['remaining'],
                ]));
            }
        }
    }

    /**
     * Soft-void return credits / refunds that violate cumulative return quantity rules.
     * Preserves audit trail via void status + audit_events.
     *
     * @return array{voided_return_credits:list<int>, voided_refunds:list<int>}
     */
    public function reconcileOverReturnsForCustomer(int $customerId, int $employeeId = 1): array
    {
        $voidedCredits = [];
        $voidedRefunds = [];

        $originals = $this->db->table('sales')
            ->select('sale_id')
            ->where('customer_id', $customerId)
            ->where('sale_status', COMPLETED)
            ->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES)
            ->get()
            ->getResultArray();

        foreach ($originals as $orig) {
            $originalSaleId = (int) $orig['sale_id'];
            $soldRows = $this->db->table('sales_items')
                ->select('item_id, SUM(quantity_purchased) AS sold_qty')
                ->where('sale_id', $originalSaleId)
                ->groupBy('item_id')
                ->get()
                ->getResultArray();
            $remaining = [];
            foreach ($soldRows as $row) {
                if ((float) $row['sold_qty'] > 0) {
                    $remaining[(int) $row['item_id']] = (float) $row['sold_qty'];
                }
            }

            $returnCredits = $this->db->table('customer_return_credits')
                ->where('customer_id', $customerId)
                ->where('original_sale_id', $originalSaleId)
                ->where('status', CA_STATUS_ACTIVE)
                ->orderBy('created_at', 'asc')
                ->orderBy('return_credit_id', 'asc')
                ->get()
                ->getResultArray();

            foreach ($returnCredits as $credit) {
                $returnSaleId = (int) $credit['return_sale_id'];
                $items = $this->db->table('sales_items')
                    ->select('item_id, SUM(ABS(quantity_purchased)) AS qty')
                    ->where('sale_id', $returnSaleId)
                    ->groupBy('item_id')
                    ->get()
                    ->getResultArray();

                $over = false;
                foreach ($items as $item) {
                    $itemId = (int) $item['item_id'];
                    $qty = (float) $item['qty'];
                    if ($qty - ($remaining[$itemId] ?? 0.0) > 0.00001) {
                        $over = true;
                        break;
                    }
                }

                if ($over) {
                    try {
                        $this->voidReturnCredit((int) $credit['return_credit_id'], $employeeId, 'Reconcile: return quantity exceeded remaining');
                        // Cancel the over-return sale so quantity tracking no longer counts it.
                        $this->db->table('sales')
                            ->where('sale_id', $returnSaleId)
                            ->where('sale_type', SALE_TYPE_RETURN)
                            ->update(['sale_status' => CANCELED]);
                        $voidedCredits[] = (int) $credit['return_credit_id'];
                    } catch (\Throwable $e) {
                        log_message('error', 'reconcile void return credit failed: ' . $e->getMessage());
                    }
                    continue;
                }

                foreach ($items as $item) {
                    $itemId = (int) $item['item_id'];
                    $remaining[$itemId] = max(0.0, ($remaining[$itemId] ?? 0.0) - (float) $item['qty']);
                }
            }
        }

        // Soft-void newest refunds that exceed remaining customer credit after return cleanup.
        $credit = $this->getCustomerCreditBalance($customerId);
        // getCustomerCreditBalance already subtracted refunds — recompute gross return remainder without refunds
        $returnCredits = $this->db->table('customer_return_credits')
            ->select('return_credit_id, return_amount')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getResultArray();
        $gross = 0.0;
        foreach ($returnCredits as $row) {
            $gross += (float) $row['return_amount'] - $this->getReturnCreditAllocated((int) $row['return_credit_id']);
        }
        $applications = $this->db->table('customer_credit_applications')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();
        $gross -= (float) ($applications['total'] ?? 0);
        $gross = $this->moneyMaxZero($gross);

        $refunds = $this->db->table('customer_credit_refunds')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('refund_time', 'desc')
            ->orderBy('refund_id', 'desc')
            ->get()
            ->getResultArray();

        $refundTotal = 0.0;
        foreach ($refunds as $refund) {
            $refundTotal += (float) $refund['amount'];
        }

        $excess = $this->moneyRound($refundTotal - $gross);
        foreach ($refunds as $refund) {
            if ($excess <= 0) {
                break;
            }
            $amount = (float) $refund['amount'];
            $this->db->table('customer_credit_refunds')
                ->where('refund_id', (int) $refund['refund_id'])
                ->update([
                    'status'     => CA_STATUS_VOIDED,
                    'voided_at'  => date('Y-m-d H:i:s'),
                    'voided_by'  => $employeeId,
                ]);
            $this->writeAuditEvent($customerId, 'credit_refund_voided', 'credit_refund', (int) $refund['refund_id'], [
                'reason' => 'Reconcile: refund exceeded eligible customer credit after return cleanup',
                'amount' => $amount,
            ], $employeeId);
            $voidedRefunds[] = (int) $refund['refund_id'];
            $excess = $this->moneyRound($excess - $amount);
        }

        unset($credit);

        return [
            'voided_return_credits' => $voidedCredits,
            'voided_refunds'        => $voidedRefunds,
        ];
    }

    /**
     * Hook after completing a SALE_TYPE_RETURN sale.
     *
     * @param 'outstanding'|'credit'|'cash' $settlementMode
     * @return array{return_credit_id:int, allocated:float, credit_remainder:float}|null
     */
    public function onReturnSaleCompleted(int $returnSaleId, int $employeeId, string $settlementMode = 'outstanding'): ?array
    {
        $sale = $this->db->table('sales')->where('sale_id', $returnSaleId)->get()->getRowArray();
        if ($sale === null || (int) $sale['sale_type'] !== SALE_TYPE_RETURN) {
            return null;
        }
        if ((int) $sale['sale_status'] !== COMPLETED) {
            return null;
        }

        if (!in_array($settlementMode, ['outstanding', 'credit', 'cash'], true)) {
            $settlementMode = 'outstanding';
        }

        $customerId = $sale['customer_id'] !== null ? (int) $sale['customer_id'] : null;
        if ($customerId === null || $customerId <= 0) {
            return null;
        }

        $existing = $this->db->table('customer_return_credits')
            ->where('return_sale_id', $returnSaleId)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            $result = [
                'return_credit_id'  => (int) $existing['return_credit_id'],
                'allocated'         => $this->getReturnCreditAllocated((int) $existing['return_credit_id']),
                'credit_remainder'  => $this->moneyRound((float) $existing['return_amount'] - $this->getReturnCreditAllocated((int) $existing['return_credit_id'])),
                'settlement_mode'   => (string) ($existing['settlement_mode'] ?? $settlementMode),
            ];
            if (($existing['settlement_mode'] ?? '') === 'cash') {
                $refund = $this->db->table('customer_credit_refunds')
                    ->where('return_sale_id', $returnSaleId)
                    ->where('status', CA_STATUS_ACTIVE)
                    ->get()
                    ->getRowArray();
                if ($refund !== null) {
                    $result['refund_id'] = (int) $refund['refund_id'];
                }
            }

            return $result;
        }

        $returnAmount = abs($this->computeSaleTotal($returnSaleId));
        if ($returnAmount <= 0) {
            return null;
        }

        $originalSaleId = !empty($sale['return_of_sale_id']) ? (int) $sale['return_of_sale_id'] : null;

        if ($originalSaleId !== null) {
            $cartLines = $this->db->table('sales_items')
                ->select('item_id, quantity_purchased AS quantity')
                ->where('sale_id', $returnSaleId)
                ->get()
                ->getResultArray();
            $this->assertReturnCartWithinRemaining($originalSaleId, $cartLines, $returnSaleId);
        }

        $this->db->transStart();

        try {
            $lockIds = [];
            if ($originalSaleId !== null) {
                $lockIds[] = $originalSaleId;
            }
            if ($settlementMode === 'outstanding') {
                foreach ($this->getOutstandingSales($customerId, false) as $row) {
                    $lockIds[] = (int) $row['sale_id'];
                }
            }
            $lockIds = array_values(array_unique(array_filter($lockIds)));
            if ($lockIds !== []) {
                $this->lockSalesForUpdate($lockIds);
            }
            if ($settlementMode === 'outstanding' && $originalSaleId !== null) {
                $this->assertReturnOutstandingAmount($originalSaleId, $returnAmount);
            }
            if ($settlementMode === 'cash') {
                if ($originalSaleId !== null) {
                    $this->assertCashRefundEligible($originalSaleId, $returnAmount);
                } else {
                    throw new RuntimeException(lang('Accounts.error_cash_refund_exceeds_paid'));
                }
            }

            $creditInsert = [
                'return_sale_id'   => $returnSaleId,
                'customer_id'      => $customerId,
                'original_sale_id' => $originalSaleId,
                'return_amount'    => $this->moneyRound($returnAmount),
                'settlement_mode'  => $settlementMode,
                'status'           => CA_STATUS_ACTIVE,
                'employee_id'      => $employeeId,
            ];

            $creditModel = model(Customer_return_credit::class);
            $creditModel->insert($creditInsert);
            $returnCreditId = (int) $creditModel->getInsertID();

            $allocated = 0.0;
            $refundId = null;

            // Cash: linked refund memo only — never reduces outstanding or creates spendable credit.
            if ($settlementMode === 'cash') {
                $paymentType = lang('Sales.cash');
                $refundModel = model(Customer_credit_refund::class);
                $refundModel->insert([
                    'customer_id'     => $customerId,
                    'return_sale_id'  => $returnSaleId,
                    'amount'          => $this->moneyRound($returnAmount),
                    'payment_type'    => $paymentType,
                    'status'          => CA_STATUS_ACTIVE,
                    'employee_id'     => $employeeId,
                    'reference_code'  => 'Return #' . $returnSaleId,
                    'comment'         => 'Cash refund for return sale #' . $returnSaleId,
                    'refund_time'     => date('Y-m-d H:i:s'),
                ]);
                $refundId = (int) $refundModel->getInsertID();

                $this->writeAuditEvent($customerId, 'return_cash_refund', 'credit_refund', $refundId, [
                    'return_sale_id'   => $returnSaleId,
                    'original_sale_id' => $originalSaleId,
                    'return_amount'    => $returnAmount,
                    'settlement_mode'  => 'cash',
                ], $employeeId);
            } elseif ($settlementMode === 'outstanding') {
                // Reduce Outstanding Due allocates against eligible receivables.
                $remaining = $returnAmount;
                $targets = [];
                if ($originalSaleId !== null) {
                    $targets[] = $originalSaleId;
                }
                foreach ($this->getOutstandingSales($customerId, false) as $row) {
                    $sid = (int) $row['sale_id'];
                    if (!in_array($sid, $targets, true)) {
                        $targets[] = $sid;
                    }
                }

                foreach ($targets as $saleId) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $original = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
                    if (
                        $original === null
                        || (int) $original['customer_id'] !== $customerId
                        || (int) $original['sale_status'] !== COMPLETED
                        || !in_array((int) $original['sale_type'], self::RECEIVABLE_SALE_TYPES, true)
                    ) {
                        continue;
                    }
                    $outstanding = $this->getSaleOutstanding($saleId);
                    $apply = min($remaining, $outstanding);
                    if ($apply <= 0) {
                        continue;
                    }
                    model(Customer_return_credit_allocation::class)->insert([
                        'return_credit_id' => $returnCreditId,
                        'sale_id'          => $saleId,
                        'amount'           => $this->moneyRound($apply),
                        'status'           => CA_STATUS_ACTIVE,
                    ]);
                    $allocated = $this->moneyRound($allocated + $apply);
                    $remaining = $this->moneyRound($remaining - $apply);
                }
            }
            // Credit settlement: leave unallocated — becomes customer credit.

            $remainder = $this->moneyRound($returnAmount - $allocated);

            if ($settlementMode !== 'cash') {
                $this->writeAuditEvent($customerId, 'return_credit_created', 'return_credit', $returnCreditId, [
                    'return_sale_id'    => $returnSaleId,
                    'original_sale_id'  => $originalSaleId,
                    'return_amount'     => $returnAmount,
                    'allocated'         => $allocated,
                    'credit_remainder'  => $remainder,
                    'settlement_mode'   => $settlementMode,
                ], $employeeId);
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Return credit failed.');
            }

            $result = [
                'return_credit_id' => $returnCreditId,
                'allocated'        => $allocated,
                'credit_remainder' => $settlementMode === 'cash' ? 0.0 : $remainder,
                'settlement_mode'  => $settlementMode,
            ];
            if ($refundId !== null) {
                $result['refund_id'] = $refundId;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @return array{application_id:int, amount:float, idempotent?:bool}
     */
    public function applyCustomerCredit(
        int $customerId,
        int $saleId,
        float $amount,
        int $employeeId,
        ?string $comment = null,
        ?string $idempotencyKey = null
    ): array {
        $amount = $this->moneyRound($amount);
        if ($amount <= 0) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->db->table('customer_credit_applications')
                ->where('customer_id', $customerId)
                ->where('idempotency_key', trim($idempotencyKey))
                ->get()
                ->getRowArray();
            if ($existing !== null) {
                return [
                    'application_id' => (int) $existing['application_id'],
                    'amount'         => (float) $existing['amount'],
                    'idempotent'     => true,
                ];
            }
        }

        $this->db->transStart();

        try {
            $this->lockSalesForUpdate([$saleId]);

            $available = $this->getCustomerCreditBalance($customerId);
            if ($amount - $available > 0.00001) {
                throw new RuntimeException(lang('Accounts.error_insufficient_credit'));
            }

            $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
            if ($sale === null || (int) $sale['customer_id'] !== $customerId) {
                throw new RuntimeException(lang('Accounts.error_different_customer'));
            }
            if ((int) $sale['sale_status'] !== COMPLETED
                || !in_array((int) $sale['sale_type'], self::RECEIVABLE_SALE_TYPES, true)
            ) {
                throw new RuntimeException(lang('Accounts.error_sale_not_outstanding'));
            }

            $outstanding = $this->getSaleOutstanding($saleId);
            if ($amount - $outstanding > 0.00001) {
                throw new RuntimeException(lang('Accounts.cannot_overpay'));
            }

            $model = model(Customer_credit_application::class);
            $model->insert([
                'customer_id'     => $customerId,
                'sale_id'         => $saleId,
                'amount'          => $amount,
                'status'          => CA_STATUS_ACTIVE,
                'employee_id'     => $employeeId,
                'idempotency_key' => ($idempotencyKey !== null && trim($idempotencyKey) !== '')
                    ? trim($idempotencyKey)
                    : null,
                'comment'         => $comment,
            ]);
            $applicationId = (int) $model->getInsertID();

            $this->writeAuditEvent($customerId, 'credit_applied', 'credit_application', $applicationId, [
                'sale_id' => $saleId,
                'amount'  => $amount,
            ], $employeeId);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Apply credit failed.');
            }

            return ['application_id' => $applicationId, 'amount' => $amount];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @return array{refund_id:int, amount:float, idempotent?:bool}
     */
    public function refundCustomerCredit(
        int $customerId,
        float $amount,
        string $paymentType,
        int $employeeId,
        ?string $referenceCode = null,
        ?string $comment = null,
        ?string $idempotencyKey = null
    ): array {
        $amount = $this->moneyRound($amount);
        if ($amount <= 0) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        if (!$this->isValidAccountPaymentType($paymentType)) {
            throw new RuntimeException(lang('Accounts.error_invalid_payment_type'));
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->db->table('customer_credit_refunds')
                ->where('customer_id', $customerId)
                ->where('idempotency_key', trim($idempotencyKey))
                ->get()
                ->getRowArray();
            if ($existing !== null) {
                return [
                    'refund_id'  => (int) $existing['refund_id'],
                    'amount'     => (float) $existing['amount'],
                    'idempotent' => true,
                ];
            }
        }

        $this->db->transStart();

        try {
            $available = $this->getCustomerCreditBalance($customerId);
            if ($amount - $available > 0.00001) {
                throw new RuntimeException(lang('Accounts.error_insufficient_credit'));
            }

            $model = model(Customer_credit_refund::class);
            $model->insert([
                'customer_id'     => $customerId,
                'amount'          => $amount,
                'payment_type'    => $paymentType,
                'status'          => CA_STATUS_ACTIVE,
                'employee_id'     => $employeeId,
                'reference_code'  => $referenceCode ?? '',
                'comment'         => $comment,
                'idempotency_key' => ($idempotencyKey !== null && trim($idempotencyKey) !== '')
                    ? trim($idempotencyKey)
                    : null,
                'refund_time'     => date('Y-m-d H:i:s'),
            ]);
            $refundId = (int) $model->getInsertID();

            $this->writeAuditEvent($customerId, 'credit_refunded', 'credit_refund', $refundId, [
                'amount'       => $amount,
                'payment_type' => $paymentType,
            ], $employeeId);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Refund credit failed.');
            }

            return ['refund_id' => $refundId, 'amount' => $amount];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function voidReturnCredit(int $returnCreditId, int $employeeId, ?string $reason = null): void
    {
        $this->db->transStart();

        try {
            $credit = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('customer_return_credits') . ' WHERE return_credit_id = ? FOR UPDATE',
                [$returnCreditId]
            )->getRowArray();

            if ($credit === null) {
                throw new RuntimeException(lang('Accounts.error_return_credit_not_found'));
            }
            if ((int) $credit['status'] === CA_STATUS_VOIDED) {
                $this->db->transComplete();

                return;
            }

            $allocs = $this->db->table('customer_return_credit_allocations')
                ->where('return_credit_id', $returnCreditId)
                ->where('status', CA_STATUS_ACTIVE)
                ->get()
                ->getResultArray();
            $saleIds = array_map(static fn (array $a): int => (int) $a['sale_id'], $allocs);
            sort($saleIds);
            $this->lockSalesForUpdate($saleIds);

            $this->db->table('customer_return_credit_allocations')
                ->where('return_credit_id', $returnCreditId)
                ->where('status', CA_STATUS_ACTIVE)
                ->update(['status' => CA_STATUS_VOIDED]);

            $this->db->table('customer_return_credits')
                ->where('return_credit_id', $returnCreditId)
                ->update([
                    'status'      => CA_STATUS_VOIDED,
                    'voided_at'   => date('Y-m-d H:i:s'),
                    'voided_by'   => $employeeId,
                    'void_reason' => $reason,
                ]);

            $this->writeAuditEvent((int) $credit['customer_id'], 'return_voided', 'return_credit', $returnCreditId, [
                'reason' => $reason,
            ], $employeeId);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Void return credit failed.');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Hierarchical ledger: sale parents with POS / account / return children.
     *
     * @return array{
     *   rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int,
     *   summary:array<string,mixed>, account_level:list<array<string,mixed>>
     * }
     */
    public function getLedgerPage(int $customerId, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE, array $filters = []): array
    {
        $builder = $this->db->table('sales')
            ->select('sale_id, sale_time, invoice_number, sale_type, comment')
            ->where('customer_id', $customerId)
            ->where('sale_status', COMPLETED)
            ->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES);
        $this->applySaleDisplayFilters($builder, $filters);
        $sales = $builder
            ->orderBy('sale_time', 'desc')
            ->orderBy('sale_id', 'desc')
            ->get()
            ->getResultArray();

        $statusFilter = (string) ($filters['status'] ?? '');
        $parents = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $summary = $this->getSaleFinancialSummary($saleId);
            if ($statusFilter === 'paid' && $summary['status'] !== SALE_PAY_STATUS_PAID) {
                continue;
            }
            if ($statusFilter === 'partially_paid' && $summary['status'] !== SALE_PAY_STATUS_PARTIAL) {
                continue;
            }
            if ($statusFilter === 'unpaid' && $summary['status'] !== SALE_PAY_STATUS_UNPAID) {
                continue;
            }

            $children = $this->buildSaleChildren($saleId, $customerId);

            $parents[] = [
                'kind'            => 'sale',
                'sale_id'         => $saleId,
                'date'            => $sale['sale_time'],
                'reference'       => 'Sale #' . $saleId,
                'invoice_number'  => $sale['invoice_number'],
                'sale_type'       => (int) $sale['sale_type'],
                'debit'           => $summary['sale_total'],
                'credit'          => 0.0,
                'sale_total'      => $summary['sale_total'],
                'payments'        => $summary['payments_applied'],
                'credits'         => $summary['credits_returns'],
                'balance'         => $summary['balance'],
                'status'          => $summary['status'],
                'children'        => $children,
            ];
        }

        $pageData = $this->paginateRows($parents, $page, $perPage);
        $pageData['summary'] = [
            'outstanding_receivables' => $this->getCustomerOutstanding($customerId),
            'outstanding_sales_count' => count(
                array_filter(
                    $parents,
                    static fn (array $p): bool =>
                        (float) $p['balance'] > 0.00001
                )
            ),
            'customer_credit'         => $this->getCustomerCreditBalance($customerId),
            'open_ci_count'           => $this->countOpenConsolidatedInvoices($customerId),
        ];
        $pageData['account_level'] = $this->buildAccountLevelRows($customerId);

        return $pageData;
    }

    /**
     * Flat chronological statement events (no duplicate POS/account credits).
     *
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int, opening_balance:float, ending_balance:float}
     */
    public function getStatementPage(
        int $customerId,
        int $page = 1,
        int $perPage = self::DEFAULT_PAGE_SIZE,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $events = $this->buildFinancialEvents($customerId, $startDate, $endDate);
        $balance = 0.0;
        $history = [];
        foreach ($events as $event) {
            $balance = $this->moneyRound($balance + (float) $event['debit'] - (float) $event['credit']);
            $history[] = [
                'date'         => $event['date'],
                'type'         => $event['type'],
                'reference'    => $event['reference'],
                'transaction'  => $event['transaction'] ?? $event['type'],
                'debit'        => $this->moneyRound((float) $event['debit']),
                'credit'       => $this->moneyRound((float) $event['credit']),
                'balance'      => $balance,
                'memo'         => $event['memo'] ?? null,
                'allocated_to' => $event['allocated_to'] ?? null,
            ];
        }

        $total = count($history);
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($history, $offset, $perPage);
        $opening = 0.0;
        if ($offset > 0 && isset($history[$offset - 1])) {
            $opening = (float) $history[$offset - 1]['balance'];
        }
        $ending = $pageRows !== []
            ? (float) $pageRows[count($pageRows) - 1]['balance']
            : $opening;

        return [
            'rows'            => $pageRows,
            'total'           => $total,
            'page'            => $page,
            'per_page'        => $perPage,
            'total_pages'     => $totalPages,
            'opening_balance' => $opening,
            'ending_balance'  => $ending,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTransactionHistory(int $customerId): array
    {
        $page = $this->getFinancialTransactions($customerId, 1, PHP_INT_MAX);

        return $page['rows'];
    }

    /**
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function getFinancialTransactions(int $customerId, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE, array $filters = []): array
    {
        $statement = $this->getStatementPage($customerId, 1, PHP_INT_MAX);
        $rows = $this->filterTransactionRows($statement['rows'], $filters);

        return $this->paginateRows($rows, $page, $perPage);
    }

    /**
     * Account Activity / Documents: sales, payments, returns, refunds, credit apps, consolidated invoices.
     *
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function getAccountActivity(int $customerId, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE, array $filters = []): array
    {
        $events = [];

        $sales = $this->db->table('sales')
            ->select('sale_id, sale_time, sale_type, invoice_number, return_of_sale_id, sale_status')
            ->where('customer_id', $customerId)
            ->whereIn('sale_status', [COMPLETED, CANCELED])
            ->orderBy('sale_time', 'asc')
            ->get()
            ->getResultArray();

        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $saleType = (int) $sale['sale_type'];
            $canceled = (int) $sale['sale_status'] === CANCELED;
            if ($saleType === SALE_TYPE_RETURN) {
                $ref = 'Return #' . $saleId;
                if (!empty($sale['return_of_sale_id'])) {
                    $ref .= ' (of Sale #' . $sale['return_of_sale_id'] . ')';
                }
                if ($canceled) {
                    $ref .= ' [voided]';
                }
                $events[] = [
                    'date'      => $sale['sale_time'],
                    'type'      => 'return',
                    'reference' => $ref,
                    'memo'      => to_currency(abs($this->computeSaleTotal($saleId))),
                    'voided'    => $canceled,
                ];
                continue;
            }
            if (!in_array($saleType, self::RECEIVABLE_SALE_TYPES, true)) {
                continue;
            }
            if ($canceled) {
                continue;
            }
            $label = !empty($sale['invoice_number'])
                ? 'Sale #' . $saleId . ' / Inv ' . $sale['invoice_number']
                : 'Sale #' . $saleId;
            $events[] = [
                'date'      => $sale['sale_time'],
                'type'      => 'sale',
                'reference' => $label,
                'memo'      => to_currency($this->computeSaleTotal($saleId)),
                'voided'    => false,
            ];
        }

        $accountPayments = $this->db->table('customer_account_payments')
            ->where('customer_id', $customerId)
            ->orderBy('payment_time', 'asc')
            ->get()
            ->getResultArray();
        foreach ($accountPayments as $payment) {
            $voided = (int) $payment['status'] === CA_STATUS_VOIDED;
            $events[] = [
                'date'      => $payment['payment_time'],
                'type'      => 'payment',
                'reference' => 'Payment #' . $payment['payment_id'] . ' / ' . $payment['payment_type']
                    . ($voided ? ' [voided]' : ''),
                'memo'      => to_currency((float) $payment['payment_amount']),
                'status'    => (int) $payment['status'],
                'voided'    => $voided,
            ];
        }

        $creditApps = $this->db->table('customer_credit_applications')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('created_at', 'asc')
            ->get()
            ->getResultArray();
        foreach ($creditApps as $app) {
            $events[] = [
                'date'      => $app['created_at'],
                'type'      => 'credit_application',
                'reference' => 'Credit → Sale #' . $app['sale_id'],
                'memo'      => to_currency((float) $app['amount']),
                'voided'    => false,
            ];
        }

        $refunds = $this->db->table('customer_credit_refunds')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('refund_time', 'asc')
            ->get()
            ->getResultArray();
        foreach ($refunds as $refund) {
            $events[] = [
                'date'      => $refund['refund_time'],
                'type'      => 'refund',
                'reference' => 'Refund #' . $refund['refund_id'] . ' / ' . $refund['payment_type'],
                'memo'      => to_currency((float) $refund['amount']),
                'voided'    => false,
            ];
        }

        $invoices = $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->orderBy('invoice_date', 'asc')
            ->orderBy('consolidated_invoice_id', 'asc')
            ->get()
            ->getResultArray();

        foreach ($invoices as $invoice) {
            $status = (int) $invoice['status'];
            $memo = $status === CI_STATUS_CANCELLED
                ? lang('Accounts.memo_consolidated_invoice_cancelled')
                : lang('Accounts.memo_consolidated_invoice');
            $events[] = [
                'date'      => $invoice['invoice_date'],
                'type'      => 'consolidated_invoice',
                'reference' => $invoice['invoice_number'],
                'memo'      => $memo,
                'status'    => $status,
                'voided'    => false,
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string) $a['date'], (string) $b['date']);
        });

        $events = $this->filterActivityEvents($events, $filters);

        return $this->paginateRows($events, $page, $perPage);
    }

    /**
     * @return list<array{sale_id:int, amount:float}>
     */
    public function buildFifoAllocations(int $customerId, float $amount, ?int $consolidatedInvoiceId = null): array
    {
        if ($consolidatedInvoiceId === null) {
            throw new RuntimeException(lang('Accounts.error_allocation_required'));
        }

        $amount = $this->moneyRound($amount);
        $remaining = $amount;
        $allocations = [];

        $sales = $this->db->table('consolidated_invoice_sales AS cis')
            ->select('cis.sale_id, sales.sale_time')
            ->join('sales', 'sales.sale_id = cis.sale_id')
            ->where('cis.consolidated_invoice_id', $consolidatedInvoiceId)
            ->where('sales.customer_id', $customerId)
            ->orderBy('sales.sale_time', 'asc')
            ->get()
            ->getResultArray();

        foreach ($sales as $sale) {
            if ($remaining <= 0) {
                break;
            }
            $saleId = (int) $sale['sale_id'];
            $outstanding = $this->getSaleOutstanding($saleId);
            if ($outstanding <= 0) {
                continue;
            }
            $apply = min($remaining, $outstanding);
            $allocations[] = ['sale_id' => $saleId, 'amount' => $this->moneyRound($apply)];
            $remaining = $this->moneyRound($remaining - $apply);
        }

        if ($remaining > 0.00001) {
            throw new RuntimeException(lang('Accounts.cannot_overpay'));
        }

        return $allocations;
    }

    public function computeSaleTotal(int $saleId): float
    {
        $sale = model(Sale::class);
        $sale->create_temp_table(['sale_id' => $saleId]);

        $row = $this->db->table('sales_items_temp')
            ->select('COALESCE(SUM(total), 0) AS sale_total', false)
            ->where('sale_id', $saleId)
            ->get()
            ->getRowArray();

        $this->dropSaleTempTables();

        return $this->moneyRound((float) ($row['sale_total'] ?? 0));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSaleChildren(int $saleId, int $customerId): array
    {
        $children = [];

        $payments = $this->db->table('sales_payments')
            ->where('sale_id', $saleId)
            ->where('cash_adjustment', 0)
            ->orderBy('payment_time', 'asc')
            ->orderBy('payment_id', 'asc')
            ->get()
            ->getResultArray();

        foreach ($payments as $payment) {
            if ($this->isDuePaymentType((string) $payment['payment_type'])) {
                continue;
            }
            $net = (float) $payment['payment_amount'] - (float) $payment['cash_refund'];
            if ($net == 0.0) {
                continue;
            }
            $children[] = [
                'source'         => 'pos',
                'payment_id'     => (int) $payment['payment_id'],
                'date'           => $payment['payment_time'],
                'reference'      => $payment['payment_type'],
                'payment_type'   => $payment['payment_type'],
                'amount'         => $this->moneyRound($net),
                'credit'         => $net > 0 ? $this->moneyRound($net) : 0.0,
                'debit'          => $net < 0 ? $this->moneyRound(abs($net)) : 0.0,
                'can_void'       => false,
                'can_reallocate' => false,
            ];
        }

        $accountAllocs = $this->db->table('customer_payment_allocations AS cpa')
            ->select('cpa.allocation_id, cpa.amount, cpa.status AS alloc_status, cap.payment_id, cap.payment_type, cap.payment_time, cap.status AS payment_status, cap.consolidated_invoice_id')
            ->join('customer_account_payments AS cap', 'cap.payment_id = cpa.customer_account_payment_id')
            ->where('cpa.sale_id', $saleId)
            ->where('cap.customer_id', $customerId)
            ->where('cpa.status', CA_STATUS_ACTIVE)
            ->where('cap.status', CA_STATUS_ACTIVE)
            ->orderBy('cap.payment_time', 'asc')
            ->get()
            ->getResultArray();

        foreach ($accountAllocs as $row) {
            $children[] = [
                'source'         => 'account',
                'payment_id'     => (int) $row['payment_id'],
                'allocation_id'  => (int) $row['allocation_id'],
                'date'           => $row['payment_time'],
                'reference'      => 'Payment #' . $row['payment_id'] . ' / ' . $row['payment_type'],
                'payment_type'   => $row['payment_type'],
                'amount'         => $this->moneyRound((float) $row['amount']),
                'credit'         => $this->moneyRound((float) $row['amount']),
                'debit'          => 0.0,
                'can_void'       => true,
                'can_reallocate' => true,
                'consolidated_invoice_id' => $row['consolidated_invoice_id'],
            ];
        }

        $returnAllocs = $this->db->table('customer_return_credit_allocations AS rca')
            ->select('rca.allocation_id, rca.amount, rc.return_credit_id, rc.return_sale_id, rc.created_at')
            ->join('customer_return_credits AS rc', 'rc.return_credit_id = rca.return_credit_id')
            ->where('rca.sale_id', $saleId)
            ->where('rca.status', CA_STATUS_ACTIVE)
            ->where('rc.status', CA_STATUS_ACTIVE)
            ->orderBy('rc.created_at', 'asc')
            ->get()
            ->getResultArray();

        foreach ($returnAllocs as $row) {
            $children[] = [
                'source'           => 'return',
                'return_credit_id' => (int) $row['return_credit_id'],
                'return_sale_id'   => (int) $row['return_sale_id'],
                'allocation_id'    => (int) $row['allocation_id'],
                'date'             => $row['created_at'],
                'reference'        => 'Return #' . $row['return_sale_id'],
                'amount'           => $this->moneyRound((float) $row['amount']),
                'credit'           => $this->moneyRound((float) $row['amount']),
                'debit'            => 0.0,
                'can_void'         => true,
                'can_reallocate'   => false,
            ];
        }

        $creditApps = $this->db->table('customer_credit_applications')
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('created_at', 'asc')
            ->get()
            ->getResultArray();

        foreach ($creditApps as $row) {
            $children[] = [
                'source'         => 'credit_application',
                'application_id' => (int) $row['application_id'],
                'date'           => $row['created_at'],
                'reference'      => 'Credit apply #' . $row['application_id'],
                'amount'         => $this->moneyRound((float) $row['amount']),
                'credit'         => $this->moneyRound((float) $row['amount']),
                'debit'          => 0.0,
                'can_void'       => false,
                'can_reallocate' => false,
            ];
        }

        // Informational: cash refunds and unallocated credit returns linked to this sale.
        // These do NOT reduce sale balance (credit=0) — AR impact is only via allocations above.
        $linkedReturns = $this->db->table('customer_return_credits')
            ->where('original_sale_id', $saleId)
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('created_at', 'asc')
            ->get()
            ->getResultArray();

        foreach ($linkedReturns as $credit) {
            $mode = (string) ($credit['settlement_mode'] ?? 'outstanding');
            $returnSaleId = (int) $credit['return_sale_id'];
            $returnAmount = $this->moneyRound((float) $credit['return_amount']);
            $allocated = $this->getReturnCreditAllocated((int) $credit['return_credit_id']);

            if ($mode === 'cash') {
                $children[] = [
                    'source'         => 'return_cash',
                    'return_sale_id' => $returnSaleId,
                    'date'           => $credit['created_at'],
                    'reference'      => 'Return #' . $returnSaleId . ' (cash refund)',
                    'amount'         => $returnAmount,
                    'credit'         => 0.0,
                    'debit'          => 0.0,
                    'can_void'       => false,
                    'can_reallocate' => false,
                ];
                continue;
            }

            $remainder = $this->moneyRound($returnAmount - $allocated);
            if ($remainder > 0.00001) {
                $children[] = [
                    'source'         => 'return_credit_issued',
                    'return_sale_id' => $returnSaleId,
                    'date'           => $credit['created_at'],
                    'reference'      => 'Return #' . $returnSaleId . ' (credit)',
                    'amount'         => $remainder,
                    'credit'         => 0.0,
                    'debit'          => 0.0,
                    'can_void'       => false,
                    'can_reallocate' => false,
                ];
            }
        }

        return $children;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAccountLevelRows(int $customerId): array
    {
        $rows = [];
        $credit = $this->getCustomerCreditBalance($customerId);
        $rows[] = [
            'kind'      => 'customer_credit',
            'reference' => lang('Accounts.customer_credit'),
            'amount'    => $credit,
        ];

        $refunds = $this->db->table('customer_credit_refunds')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('refund_time', 'desc')
            ->get()
            ->getResultArray();

        foreach ($refunds as $refund) {
            $rows[] = [
                'kind'         => 'credit_refund',
                'refund_id'    => (int) $refund['refund_id'],
                'date'         => $refund['refund_time'],
                'reference'    => 'Refund #' . $refund['refund_id'] . ' / ' . $refund['payment_type'],
                'amount'       => $this->moneyRound((float) $refund['amount']),
                'payment_type' => $refund['payment_type'],
            ];
        }

        return $rows;
    }

    /**
     * Flat events for statement — each POS payment and each CA payment once.
     *
     * @return list<array<string, mixed>>
     */
    private function buildFinancialEvents(int $customerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $events = [];

        $sales = $this->db->table('sales')
            ->where('customer_id', $customerId)
            ->where('sale_status', COMPLETED)
            ->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES)
            ->orderBy('sale_time', 'asc')
            ->get()
            ->getResultArray();

        foreach ($sales as $sale) {
            $saleId = (int) $sale['sale_id'];
            $saleTotal = $this->computeSaleTotal($saleId);
            $events[] = [
                'sort_time'   => $sale['sale_time'],
                'date'        => $sale['sale_time'],
                'type'        => 'sale',
                'transaction' => 'Sale',
                'reference'   => 'Sale #' . $saleId,
                'debit'       => $saleTotal > 0 ? $saleTotal : 0,
                'credit'      => $saleTotal < 0 ? abs($saleTotal) : 0,
            ];

            $payments = $this->db->table('sales_payments')
                ->where('sale_id', $saleId)
                ->where('cash_adjustment', 0)
                ->orderBy('payment_time', 'asc')
                ->orderBy('payment_id', 'asc')
                ->get()
                ->getResultArray();

            foreach ($payments as $payment) {
                $net = (float) $payment['payment_amount'] - (float) $payment['cash_refund'];
                if ($net == 0.0 || $this->isDuePaymentType((string) $payment['payment_type'])) {
                    continue;
                }
                $events[] = [
                    'sort_time'   => $payment['payment_time'] ?? $sale['sale_time'],
                    'date'        => $payment['payment_time'] ?? $sale['sale_time'],
                    'type'        => 'pos_payment',
                    'transaction' => 'POS Payment',
                    'reference'   => 'Sale #' . $saleId . ' / ' . $payment['payment_type'],
                    'debit'       => $net < 0 ? abs($net) : 0,
                    'credit'      => $net > 0 ? $net : 0,
                ];
            }
        }

        $accountPayments = $this->db->table('customer_account_payments')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('payment_time', 'asc')
            ->orderBy('payment_id', 'asc')
            ->get()
            ->getResultArray();

        foreach ($accountPayments as $payment) {
            $paymentId = (int) $payment['payment_id'];
            $ref = 'Payment #' . $paymentId . ' / ' . $payment['payment_type'];
            if (!empty($payment['consolidated_invoice_id'])) {
                $ci = $this->db->table('consolidated_invoices')
                    ->select('invoice_number')
                    ->where('consolidated_invoice_id', $payment['consolidated_invoice_id'])
                    ->get()
                    ->getRowArray();
                if ($ci) {
                    $ref .= ' / ' . $ci['invoice_number'];
                }
            }

            $allocParts = [];
            foreach ($this->getPaymentAllocations($paymentId) as $alloc) {
                $allocParts[] = 'Sale #' . $alloc['sale_id'] . ' — ' . to_currency($alloc['amount']);
            }

            $events[] = [
                'sort_time'    => $payment['payment_time'],
                'date'         => $payment['payment_time'],
                'type'         => 'account_payment',
                'transaction'  => 'Customer Payment',
                'reference'    => $ref,
                'debit'        => 0,
                'credit'       => (float) $payment['payment_amount'],
                'allocated_to' => $allocParts !== [] ? implode('; ', $allocParts) : null,
                'memo'         => $payment['comment'] ?? null,
            ];
        }

        $returnCredits = $this->db->table('customer_return_credits')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('created_at', 'asc')
            ->get()
            ->getResultArray();

        foreach ($returnCredits as $credit) {
            $returnCreditId = (int) $credit['return_credit_id'];
            $returnAmount = $this->moneyRound((float) $credit['return_amount']);
            $mode = (string) ($credit['settlement_mode'] ?? 'outstanding');

            // Cash settlement: visible once as a zero-AR refund linked to the return/sale.
            if ($mode === 'cash') {
                $ref = 'Return #' . $credit['return_sale_id'];
                if (!empty($credit['original_sale_id'])) {
                    $ref .= ' → Sale #' . $credit['original_sale_id'];
                }
                $ref .= ' (cash refund)';
                $events[] = [
                    'sort_time'   => $credit['created_at'],
                    'date'        => $credit['created_at'],
                    'type'        => 'return_cash',
                    'transaction' => 'Cash Refund',
                    'reference'   => $ref,
                    'debit'       => 0,
                    'credit'      => 0,
                    'memo'        => lang('Accounts.memo_return_cash_refund', [to_currency($returnAmount)]),
                ];
                continue;
            }

            $allocs = $this->db->table('customer_return_credit_allocations')
                ->where('return_credit_id', $returnCreditId)
                ->where('status', CA_STATUS_ACTIVE)
                ->get()
                ->getResultArray();

            $allocated = 0.0;
            foreach ($allocs as $alloc) {
                $allocated = $this->moneyRound($allocated + (float) $alloc['amount']);
                $events[] = [
                    'sort_time'   => $credit['created_at'],
                    'date'        => $credit['created_at'],
                    'type'        => 'return_credit',
                    'transaction' => 'Return Credit',
                    'reference'   => 'Return #' . $credit['return_sale_id'] . ' → Sale #' . $alloc['sale_id'],
                    'debit'       => 0,
                    'credit'      => (float) $alloc['amount'],
                ];
            }

            // Unallocated remainder becomes customer credit — does not change AR balance.
            $remainder = $this->moneyRound($returnAmount - $allocated);
            if ($remainder > 0) {
                $events[] = [
                    'sort_time'   => $credit['created_at'],
                    'date'        => $credit['created_at'],
                    'type'        => 'return_credit_issued',
                    'transaction' => 'Customer Credit',
                    'reference'   => 'Return #' . $credit['return_sale_id'] . ' (credit)',
                    'debit'       => 0,
                    'credit'      => 0,
                    'memo'        => lang('Accounts.memo_customer_credit_issued', [to_currency($remainder)]),
                ];
            }
        }

        $creditApps = $this->db->table('customer_credit_applications')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('created_at', 'asc')
            ->get()
            ->getResultArray();

        foreach ($creditApps as $app) {
            $events[] = [
                'sort_time'   => $app['created_at'],
                'date'        => $app['created_at'],
                'type'        => 'credit_application',
                'transaction' => 'Customer Credit',
                'reference'   => 'Credit → Sale #' . $app['sale_id'],
                'debit'       => 0,
                'credit'      => (float) $app['amount'],
            ];
        }

        $refunds = $this->db->table('customer_credit_refunds')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->orderBy('refund_time', 'asc')
            ->get()
            ->getResultArray();

        foreach ($refunds as $refund) {
            // Return cash settlements already emitted as return_cash above.
            if (!empty($refund['return_sale_id'])) {
                continue;
            }
            // Cash/bank refund pays out customer credit — must NOT increase receivables (no debit).
            $events[] = [
                'sort_time'   => $refund['refund_time'],
                'date'        => $refund['refund_time'],
                'type'        => 'credit_refund',
                'transaction' => 'Refund',
                'reference'   => 'Refund #' . $refund['refund_id'] . ' / ' . $refund['payment_type'],
                'debit'       => 0,
                'credit'      => 0,
                'memo'        => lang('Accounts.memo_credit_refund', [to_currency((float) $refund['amount'])]),
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string) $a['sort_time'], (string) $b['sort_time']);
        });

        if ($startDate !== null || $endDate !== null) {
            $events = array_values(array_filter($events, static function (array $event) use ($startDate, $endDate): bool {
                $date = substr((string) $event['date'], 0, 10);
                if ($startDate !== null && $date < substr($startDate, 0, 10)) {
                    return false;
                }
                if ($endDate !== null && $date > substr($endDate, 0, 10)) {
                    return false;
                }

                return true;
            }));
        }

        return $events;
    }

    /**
     * @param list<int> $saleIds
     */
    private function lockSalesForUpdate(array $saleIds): void
    {
        $saleIds = array_values(array_unique(array_filter($saleIds)));
        sort($saleIds);
        foreach ($saleIds as $saleId) {
            $this->db->query(
                'SELECT sale_id FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE',
                [$saleId]
            );
        }
    }

    /**
     * @param list<array{sale_id:int, amount:float}> $allocations
     * @return list<array{sale_id:int, amount:float}>
     */
    private function mergeAllocationsBySaleId(array $allocations): array
    {
        $merged = [];
        foreach ($allocations as $allocation) {
            $saleId = (int) $allocation['sale_id'];
            $amount = $this->moneyRound((float) $allocation['amount']);
            if ($saleId <= 0 || $amount <= 0) {
                continue;
            }
            if (!isset($merged[$saleId])) {
                $merged[$saleId] = 0.0;
            }
            $merged[$saleId] = $this->moneyRound($merged[$saleId] + $amount);
        }

        $result = [];
        foreach ($merged as $saleId => $amount) {
            $result[] = ['sale_id' => (int) $saleId, 'amount' => $amount];
        }

        return $result;
    }

    private function writeAuditEvent(
        int $customerId,
        string $eventType,
        string $entityType,
        ?int $entityId,
        array $payload,
        int $employeeId
    ): void {
        $this->db->table('customer_account_audit_events')->insert([
            'customer_id' => $customerId,
            'event_type'  => $eventType,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'payload'     => json_encode($payload),
            'employee_id' => $employeeId,
        ]);
    }

    private function countOpenConsolidatedInvoices(int $customerId): int
    {
        return $this->db->table('consolidated_invoices')
            ->where('customer_id', $customerId)
            ->whereIn('status', [CI_STATUS_OPEN, CI_STATUS_PARTIALLY_PAID, CI_STATUS_DRAFT])
            ->countAllResults();
    }

    /**
     * @param \CodeIgniter\Database\BaseBuilder $builder
     * @param array<string, mixed> $filters
     */
    private function applySaleDisplayFilters($builder, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $builder->groupStart();
            $builder->like('invoice_number', $search);
            $saleId = $this->extractNumericId($search);
            if ($saleId !== null) {
                $builder->orWhere('sale_id', $saleId);
            }
            $builder->groupEnd();
        }

        $from = $this->sanitizeFilterDate($filters['from'] ?? null);
        $to = $this->sanitizeFilterDate($filters['to'] ?? null);
        if ($from !== null) {
            $builder->where('sale_time >=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $builder->where('sale_time <=', $to . ' 23:59:59');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function filterTransactionRows(array $rows, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $type = (string) ($filters['type'] ?? '');
        $side = (string) ($filters['side'] ?? '');
        $from = $this->sanitizeFilterDate($filters['from'] ?? null);
        $to = $this->sanitizeFilterDate($filters['to'] ?? null);
        $typeMap = [
            'sale'                  => ['sale'],
            'payment'               => ['pos_payment', 'account_payment'],
            'return'                => ['return_credit'],
            'refund'                => ['return_cash', 'credit_refund'],
            'customer_credit'       => ['return_credit_issued'],
            'credit_allocation'     => ['credit_application'],
            'consolidated_invoice'  => ['consolidated_invoice'],
        ];
        $allowedTypes = $typeMap[$type] ?? [];

        $filtered = [];
        foreach ($rows as $row) {
            $date = substr((string) ($row['date'] ?? ''), 0, 10);
            if ($from !== null && $date < $from) {
                continue;
            }
            if ($to !== null && $date > $to) {
                continue;
            }
            if ($allowedTypes !== [] && !in_array((string) ($row['type'] ?? ''), $allowedTypes, true)) {
                continue;
            }
            if ($side === 'debit' && (float) ($row['debit'] ?? 0) <= 0) {
                continue;
            }
            if ($side === 'credit' && (float) ($row['credit'] ?? 0) <= 0) {
                continue;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(
                    (string) ($row['reference'] ?? '') . ' '
                    . (string) ($row['allocated_to'] ?? '') . ' '
                    . (string) ($row['memo'] ?? '')
                );
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }
            $filtered[] = $row;
        }

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function filterActivityEvents(array $events, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $type = (string) ($filters['type'] ?? '');
        $voided = (string) ($filters['voided'] ?? '');
        $from = $this->sanitizeFilterDate($filters['from'] ?? null);
        $to = $this->sanitizeFilterDate($filters['to'] ?? null);
        $typeMap = [
            'sale'                 => ['sale'],
            'payment'              => ['payment'],
            'return'               => ['return'],
            'refund'               => ['refund'],
            'consolidated_invoice' => ['consolidated_invoice'],
            'customer_credit'      => ['customer_credit'],
            'credit_allocation'    => ['credit_application'],
        ];
        $allowedTypes = $typeMap[$type] ?? [];

        $filtered = [];
        foreach ($events as $event) {
            $date = substr((string) ($event['date'] ?? ''), 0, 10);
            if ($from !== null && $date < $from) {
                continue;
            }
            if ($to !== null && $date > $to) {
                continue;
            }
            if ($allowedTypes !== [] && !in_array((string) ($event['type'] ?? ''), $allowedTypes, true)) {
                continue;
            }
            $isVoided = !empty($event['voided']);
            if ($voided === 'exclude' && $isVoided) {
                continue;
            }
            if ($voided === 'include' && !$isVoided) {
                continue;
            }
            if ($search !== '') {
                $haystack = mb_strtolower((string) ($event['reference'] ?? '') . ' ' . (string) ($event['memo'] ?? ''));
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }
            $filtered[] = $event;
        }

        return $filtered;
    }

    private function extractNumericId(string $search): ?int
    {
        $trimmed = trim($search);
        if (preg_match('/^(?:sale\s*#?\s*)?(\d+)$/i', $trimmed, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function sanitizeFilterDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return ($date instanceof \DateTime && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    private function paginateRows(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        if ($perPage === PHP_INT_MAX || $perPage <= 0) {
            $perPage = max(1, $total ?: 1);
            $page = 1;
        } else {
            $page = max(1, $page);
            $perPage = max(1, $perPage);
        }
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'rows'        => array_slice($rows, $offset, $perPage),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return list<int>
     */
    private function getCustomerCompletedSaleIds(int $customerId): array
    {
        $rows = $this->db->table('sales')
            ->select('sale_id')
            ->where('customer_id', $customerId)
            ->where('sale_status', COMPLETED)
            ->whereIn('sale_type', self::RECEIVABLE_SALE_TYPES)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['sale_id'], $rows);
    }

    private function getSalePosPayments(int $saleId): float
    {
        $builder = $this->db->table('sales_payments');
        $builder->select('COALESCE(SUM(payment_amount - cash_refund), 0) AS cash_received', false);
        $builder->where('sale_id', $saleId);
        $builder->where('cash_adjustment', 0);
        $builder->whereNotIn('payment_type', $this->getDueLabels());
        $row = $builder->get()->getRowArray();

        return $this->moneyRound((float) ($row['cash_received'] ?? 0));
    }

    private function getSaleAmountTenderedIncludingDue(int $saleId): float
    {
        $row = $this->db->table('sales_payments')
            ->select('COALESCE(SUM(payment_amount - cash_refund), 0) AS tendered', false)
            ->where('sale_id', $saleId)
            ->where('cash_adjustment', 0)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['tendered'] ?? 0));
    }

    private function getSaleAllocated(int $saleId): float
    {
        $row = $this->db->table('customer_payment_allocations AS cpa')
            ->select('COALESCE(SUM(cpa.amount), 0) AS allocated', false)
            ->join('customer_account_payments AS cap', 'cap.payment_id = cpa.customer_account_payment_id')
            ->where('cpa.sale_id', $saleId)
            ->where('cpa.status', CA_STATUS_ACTIVE)
            ->where('cap.status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['allocated'] ?? 0));
    }

    private function getSaleReturnCredits(int $saleId): float
    {
        $row = $this->db->table('customer_return_credit_allocations')
            ->select('COALESCE(SUM(amount), 0) AS allocated', false)
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['allocated'] ?? 0));
    }

    private function getSaleCreditApplications(int $saleId): float
    {
        $row = $this->db->table('customer_credit_applications')
            ->select('COALESCE(SUM(amount), 0) AS allocated', false)
            ->where('sale_id', $saleId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['allocated'] ?? 0));
    }

    private function getReturnCreditAllocated(int $returnCreditId): float
    {
        $row = $this->db->table('customer_return_credit_allocations')
            ->select('COALESCE(SUM(amount), 0) AS allocated', false)
            ->where('return_credit_id', $returnCreditId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['allocated'] ?? 0));
    }

    /**
     * Authoritative available customer credit.
     *
     * Customer credit is created only by active return credits.
     *
     * A return credit has two possible destinations:
     *
     * 1. Direct allocation to a receivable sale
     *    via customer_return_credit_allocations.
     *
     * 2. Remaining unallocated amount becomes customer credit.
     *
     * That available customer credit can later be consumed by:
     *
     * - customer_credit_applications
     * - customer_credit_refunds
     *
     * Only active records participate in the balance.
     */
    public function getCustomerCreditBalance(int $customerId): float
    {
        $returnCredits = $this->db->table('customer_return_credits')
            ->select('return_credit_id, return_amount, settlement_mode')
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getResultArray();

        $creditBalance = 0.0;

        foreach ($returnCredits as $credit) {
            // Cash-settlement returns never become spendable customer credit.
            if (($credit['settlement_mode'] ?? '') === 'cash') {
                continue;
            }
            $returnCreditId = (int) $credit['return_credit_id'];
            $returnAmount = $this->moneyRound((float) $credit['return_amount']);
            $allocated = $this->getReturnCreditAllocated($returnCreditId);

            $creditBalance += $this->moneyMaxZero($returnAmount - $allocated);
        }

        // Credit applied to receivables is no longer available.
        $applicationRow = $this->db->table('customer_credit_applications')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        $creditBalance -= (float) ($applicationRow['total'] ?? 0);

        // Standalone credit refunds consume available credit.
        // Return cash-settlement refunds (return_sale_id set) are payout memos only.
        $refundRow = $this->db->table('customer_credit_refunds')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('customer_id', $customerId)
            ->where('status', CA_STATUS_ACTIVE)
            ->groupStart()
                ->where('return_sale_id', null)
                ->orWhere('return_sale_id', 0)
            ->groupEnd()
            ->get()
            ->getRowArray();

        $creditBalance -= (float) ($refundRow['total'] ?? 0);

        return $this->moneyMaxZero($creditBalance);
    }

    private function dropSaleTempTables(): void
    {
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable('sales_items_temp'));
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable('sales_payments_temp'));
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable('sales_items_taxes_temp'));
    }

    private function moneyRound(float $amount): float
    {
        return round($amount, totals_decimals(), PHP_ROUND_HALF_UP);
    }

    private function moneyMaxZero(float $amount): float
    {
        return $this->moneyRound(max(0, $amount));
    }

    public function assertCashRefundEligible(
        int $originalSaleId,
        float $returnAmount
    ): void {
        $returnAmount = $this->moneyRound(abs($returnAmount));

        if ($returnAmount <= 0) {
            throw new RuntimeException(
                lang('Accounts.error_return_not_outstanding')
            );
        }

        $sale = $this->db->table('sales')
            ->where('sale_id', $originalSaleId)
            ->get()
            ->getRowArray();

        if ($sale === null) {
            throw new RuntimeException(
                lang('Accounts.error_sale_not_found')
            );
        }

        if (
            (int) $sale['sale_status'] !== COMPLETED
            || !in_array(
                (int) $sale['sale_type'],
                self::RECEIVABLE_SALE_TYPES,
                true
            )
        ) {
            throw new RuntimeException(
                lang('Accounts.error_sale_not_outstanding')
            );
        }

        $paidAmount = $this->getSalePaid($originalSaleId);
        $priorCashRefunds = $this->getSaleCashRefunded($originalSaleId);
        $eligible = $this->moneyRound($paidAmount - $priorCashRefunds);

        if ($returnAmount - $eligible > 0.00001) {
            throw new RuntimeException(
                lang('Accounts.error_cash_refund_exceeds_paid')
            );
        }
    }

    /**
     * Sum of active cash-settlement return amounts linked to a receivable sale.
     */
    public function getSaleCashRefunded(int $originalSaleId): float
    {
        $row = $this->db->table('customer_return_credits')
            ->select('COALESCE(SUM(return_amount), 0) AS total', false)
            ->where('original_sale_id', $originalSaleId)
            ->where('settlement_mode', 'cash')
            ->where('status', CA_STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        return $this->moneyRound((float) ($row['total'] ?? 0));
    }

    /**
     * Backfill accounting for completed cash returns that never posted to customer accounts.
     *
     * @return list<int> return sale ids repaired
     */
    public function repairMissingCashReturnAccounting(int $employeeId = 1): array
    {
        $repaired = [];
        $returns = $this->db->table('sales')
            ->where('sale_type', SALE_TYPE_RETURN)
            ->where('sale_status', COMPLETED)
            ->orderBy('sale_id', 'asc')
            ->get()
            ->getResultArray();

        foreach ($returns as $sale) {
            $returnSaleId = (int) $sale['sale_id'];
            $existing = $this->db->table('customer_return_credits')
                ->where('return_sale_id', $returnSaleId)
                ->countAllResults();
            if ($existing > 0) {
                continue;
            }

            $customerId = (int) ($sale['customer_id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }

            $cashRefund = $this->db->table('sales_payments')
                ->select('COALESCE(SUM(cash_refund), 0) AS total', false)
                ->where('sale_id', $returnSaleId)
                ->get()
                ->getRowArray();
            $cashTotal = (float) ($cashRefund['total'] ?? 0);
            if ($cashTotal <= 0) {
                continue;
            }

            try {
                $this->onReturnSaleCompleted($returnSaleId, $employeeId, 'cash');
                $repaired[] = $returnSaleId;
            } catch (\Throwable $e) {
                log_message('error', 'repairMissingCashReturnAccounting #' . $returnSaleId . ': ' . $e->getMessage());
            }
        }

        return $repaired;
    }

    /**
     * @return array{
     *   outstanding:float,
     *   credit:float,
     *   statement_end:float,
     *   issues:list<array<string,mixed>>
     * }
     */
    public function reconcileCustomer(int $customerId): array
    {
        $issues = [];
        $returns = $this->db->table('sales')
            ->where('customer_id', $customerId)
            ->where('sale_type', SALE_TYPE_RETURN)
            ->where('sale_status', COMPLETED)
            ->get()
            ->getResultArray();

        foreach ($returns as $sale) {
            $rid = (int) $sale['sale_id'];
            $credit = $this->db->table('customer_return_credits')
                ->where('return_sale_id', $rid)
                ->get()
                ->getRowArray();
            if ($credit === null) {
                $issues[] = ['type' => 'return_without_accounting', 'return_sale_id' => $rid];
            }
        }

        $outstanding = $this->getCustomerOutstanding($customerId);
        $statement = $this->getStatementPage($customerId, 1, PHP_INT_MAX);
        $statementEnd = (float) ($statement['ending_balance'] ?? 0);
        if (abs($outstanding - $statementEnd) > 0.01) {
            $issues[] = [
                'type'            => 'outstanding_vs_statement',
                'outstanding'     => $outstanding,
                'statement_end'   => $statementEnd,
            ];
        }

        return [
            'outstanding'   => $outstanding,
            'credit'        => $this->getCustomerCreditBalance($customerId),
            'statement_end' => $statementEnd,
            'issues'        => $issues,
        ];
    }
}
