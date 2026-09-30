<?php

namespace App\Controllers;

use App\Libraries\Barcode_lib;
use App\Libraries\Email_lib;
use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use App\Libraries\Token_lib;
use App\Models\Customer;
use App\Models\Customer_rewards;
use App\Models\Dinner_table;
use App\Models\Employee;
use App\Models\Giftcard;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Sale;
use App\Models\Stock_location;
use App\Models\Tokens\Token_invoice_count;
use App\Models\Tokens\Token_customer;
use App\Models\Tokens\Token_invoice_sequence;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;
use Config\OSPOS;
use ReflectionException;
use stdClass;

class Sales extends Secure_Controller
{
    protected $helpers = ['file'];
    private Barcode_lib $barcode_lib;
    private Email_lib $email_lib;
    private Sale_lib $sale_lib;
    private Tax_lib $tax_lib;
    private Token_lib $token_lib;
    private Customer $customer;
    private Customer_rewards $customer_rewards;
    private Dinner_table $dinner_table;
    protected Employee $employee;
    private Item $item;
    private Item_kit $item_kit;
    private Sale $sale;
    private Stock_location $stock_location;
    private array $config;

    public function __construct()
    {
        parent::__construct('sales');

        $this->session = session();
        $this->barcode_lib = new Barcode_lib();
        $this->email_lib = new Email_lib();
        $this->sale_lib = new Sale_lib();
        $this->tax_lib = new Tax_lib();
        $this->token_lib = new Token_lib();
        $this->config = config(OSPOS::class)->settings;

        $this->customer = model(Customer::class);
        $this->sale = model(Sale::class);
        $this->item = model(Item::class);
        $this->item_kit = model(Item_kit::class);
        $this->stock_location = model(Stock_location::class);
        $this->customer_rewards = model(Customer_rewards::class);
        $this->dinner_table = model(Dinner_table::class);
        $this->employee = model(Employee::class);
    }

    public function getIndex(): ResponseInterface|string
    {
        $this->session->set('allow_temp_items', 1);
        return $this->reload();
    }

    /**
     * Load the sale edit modal. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function getManage(): ResponseInterface|string
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        } else {
            $data['table_headers'] = get_sales_manage_table_headers();

            $data['filters'] = [
                'only_cash'         => lang('Sales.cash_filter'),
                'only_due'          => lang('Sales.due_filter'),
                'only_check'        => lang('Sales.check_filter'),
                'only_creditcard'   => lang('Sales.credit_filter'),
                'only_debit'        => lang('Sales.debit'),
                'only_bank_transfer'=> lang('Sales.bank_transfer'),
                'only_wallet'       => lang('Sales.wallet'),
                'only_invoices'     => lang('Sales.invoice_filter'),
                'selected_customer' => lang('Sales.selected_customer')
            ];

            if ($this->sale_lib->get_customer() != -1) {
                $selectedFilters = ['selected_customer'];
                $data['customer_selected'] = true;
            } else {
                $data['customer_selected'] = false;
                $selectedFilters = [];
            }

            // Restore filters from URL query string
            $filters = restoreTableFilters($this->request);
            if (!empty($filters['selected_filters'])) {
                $selectedFilters = array_merge($selectedFilters, $filters['selected_filters']);
            }
            if (isset($filters['start_date'])) {
                $data['start_date'] = $filters['start_date'];
            }
            if (isset($filters['end_date'])) {
                $data['end_date'] = $filters['end_date'];
            }
            $data['selected_filters'] = $selectedFilters;

            $data['payment_status_options'] = [
                'all'                        => lang('Sales.no_filter'),
                SALE_PAY_STATUS_UNPAID       => lang('Accounts.open'),
                SALE_PAY_STATUS_PARTIAL      => lang('Accounts.status_partially_paid'),
                SALE_PAY_STATUS_PAID         => lang('Accounts.status_paid'),
                SALE_PAY_STATUS_CANCELLED    => lang('Accounts.status_cancelled'),
            ];
            $data['selected_payment_status'] = $this->request->getGet('payment_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'all';

            return view('sales/manage', $data);
        }
    }

    /**
     * @param int $rowId
     * @return ResponseInterface
     */
    public function getRow(int $rowId): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleInfo = $this->sale->get_info($rowId)->getRow();
        $dataRow = getSaleDataRow($saleInfo);

        return $this->response->setJSON($dataRow);
    }

    /**
     * @return ResponseInterface
     */
    public function getSearch(): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $search = $this->request->getGet('search', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $limit = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort = $this->sanitizeSortColumn(salesHeaders(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'sale_id');
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $filters = [
            'sale_type'         => 'all',
            'location_id'       => 'all',
            'start_date'        => $this->request->getGet('start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'end_date'          => $this->request->getGet('end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'only_cash'         => false,
            'only_due'          => false,
            'only_check'        => false,
            'selected_customer' => false,
            'only_creditcard'   => false,
            'only_debit'        => false,
            'only_bank_transfer'=> false,
            'only_wallet'       => false,
            'only_invoices'     => $this->config['invoice_enable'] && $this->request->getGet('only_invoices', FILTER_SANITIZE_NUMBER_INT),
            'is_valid_receipt'  => $this->sale->isValidReceipt($search)
        ];

        // Check if any filter is set in the multiselect dropdown
        $requestFilters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $requestFilters);

        $sales = $this->sale->search($search, $filters, $limit, $offset, $sort, $order);
        $totalRows = $this->sale->get_found_rows($search, $filters);
        $payments = $this->sale->getPaymentsSummary($search, $filters);

        $saleRows = $sales->getResult();
        $saleIds = array_map(static fn ($sale) => (int) $sale->sale_id, $saleRows);
        $accountLib = new \App\Libraries\Customer_account_lib();
        $summaryMap = $accountLib->getSalesFinancialSummaryMap($saleIds);

        $statusLabels = [
            SALE_PAY_STATUS_CANCELLED => lang('Accounts.status_cancelled'),
            SALE_PAY_STATUS_PAID      => lang('Accounts.status_paid'),
            SALE_PAY_STATUS_UNPAID    => lang('Accounts.open'),
            SALE_PAY_STATUS_PARTIAL   => lang('Accounts.status_partially_paid'),
        ];

        $paymentStatusFilter = $this->request->getGet('payment_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $filteredRows = [];
        $pageOutstanding = 0.0;
        foreach ($saleRows as $sale) {
            $summary = $summaryMap[(int) $sale->sale_id] ?? null;
            if ($summary === null) {
                continue;
            }
            if ($paymentStatusFilter && $paymentStatusFilter !== 'all' && $summary['status'] !== $paymentStatusFilter) {
                continue;
            }
            $sale->sale_total = $summary['sale_total'];
            $sale->payments_applied = $summary['payments_applied'];
            $sale->credits_returns = $summary['credits_returns'];
            $sale->balance = $summary['balance'];
            $sale->balance_due = $summary['balance'];
            $sale->pay_status = $summary['status'];
            $sale->pay_status_label = $statusLabels[$summary['status']] ?? $summary['status'];
            $pageOutstanding += (float) $summary['balance'];
            $filteredRows[] = $sale;
        }

        $paymentSummary = getSalesManagePaymentsSummary($payments, $pageOutstanding);

        $dataRows = [];
        foreach ($filteredRows as $sale) {
            $dataRows[] = getSaleDataRow($sale);
        }

        if ($filteredRows !== []) {
            $sum_sale_total = 0.0;
            $sum_payments = 0.0;
            $sum_credits = 0.0;
            $sum_balance = 0.0;
            foreach ($filteredRows as $sale) {
                $sum_sale_total += (float) $sale->sale_total;
                $sum_payments += (float) $sale->payments_applied;
                $sum_credits += (float) $sale->credits_returns;
                $sum_balance += (float) $sale->balance;
            }
            $dataRows[] = [
                'sale_id'         => '-',
                'sale_time'       => lang('Sales.total') . ' (page)',
                'customer_name'   => '',
                'sale_total'      => to_currency($sum_sale_total),
                'payments'        => to_currency($sum_payments),
                'credits_returns' => to_currency($sum_credits),
                'balance'         => to_currency($sum_balance),
                'pay_status'      => '',
                'invoice_number'  => '',
                'invoice'         => '',
                'receipt'         => '',
                'start_return'    => '',
            ];
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $dataRows, 'payment_summary' => $paymentSummary]);
    }

    /**
     * Gets search suggestions for an item or item kit. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getItemSearch(): ResponseInterface
    {
        $suggestions = [];
        $search = $this->request->getGet('term') != ''
            ? $this->request->getGet('term')
            : null;
        $receipt = $search;

        if ($this->sale_lib->get_mode() == 'return' && $receipt !== null && $this->sale->isValidReceipt($receipt)) {
            // Normalized to POS #N; also offer the typed invoice/POS token for clarity
            $suggestions[] = $receipt;
            if (is_string($search) && $search !== $receipt) {
                array_unshift($suggestions, $search);
            }
        }
        $suggestions = array_merge($suggestions, $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true));
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     */
    public function suggest_search(): ResponseInterface
    {
        $search = $this->request->getPost('term') != ''
            ? $this->request->getPost('term')
            : null;

        $suggestions = $this->sale->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Set a given customer. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSelectCustomer(): ResponseInterface|string
    {
        $customer_id = (int)$this->request->getPost('customer', FILTER_SANITIZE_NUMBER_INT);
        if ($this->customer->exists($customer_id)) {
            $this->sale_lib->set_customer($customer_id);
            $discount = $this->customer->get_info($customer_id)->discount;
            $discount_type = $this->customer->get_info($customer_id)->discount_type;

            // Apply customer default discount to items that have 0 discount
            if ($discount != '') {
                $this->sale_lib->apply_customer_discount($discount, $discount_type);
            }
        }

        return $this->reload();
    }

    /**
     * Changes the sale mode in the register to carry out different types of sales
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postChangeMode(): ResponseInterface|string
    {
        $mode = $this->request->getPost('mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->sale_lib->set_mode($mode);

        if ($mode == 'sale') {
            $this->sale_lib->set_sale_type(SALE_TYPE_POS);
        } elseif ($mode == 'sale_quote') {
            $this->sale_lib->set_sale_type(SALE_TYPE_QUOTE);
        } elseif ($mode == 'sale_work_order') {
            $this->sale_lib->set_sale_type(SALE_TYPE_WORK_ORDER);
        } elseif ($mode == 'sale_invoice') {
            $this->sale_lib->set_sale_type(SALE_TYPE_INVOICE);
        } else {
            $this->sale_lib->set_sale_type(SALE_TYPE_RETURN);
        }

        if ($mode !== 'return') {
            $this->sale_lib->clear_return_of_sale_id();
            $this->sale_lib->clear_return_settlement();
        }

        if ($this->config['dinner_table_enable']) {
            $occupied_dinner_table = $this->request->getPost('dinner_table', FILTER_SANITIZE_NUMBER_INT);
            $released_dinner_table = $this->sale_lib->get_dinner_table();
            $occupied = $this->dinner_table->is_occupied($released_dinner_table);

            if ($occupied && ($occupied_dinner_table != $released_dinner_table)) {
                $this->dinner_table->swap_tables($released_dinner_table, $occupied_dinner_table);
            }

            $this->sale_lib->set_dinner_table($occupied_dinner_table);
        }

        $stock_location = $this->request->getPost('stock_location', FILTER_SANITIZE_NUMBER_INT);

        if (!$stock_location || $stock_location == $this->sale_lib->get_sale_location()) {
            // TODO: The code below was removed in 2017 by @steveireland. We either need to reinstate some of it or remove this entire if block but we can't leave an empty if block
            //            $dinner_table = $this->request->getPost('dinner_table');
            //            $this->sale_lib->set_dinner_table($dinner_table);
        } elseif ($this->stock_location->is_allowed_location($stock_location, 'sales')) {
            $this->sale_lib->set_sale_location($stock_location);
        }

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * @param int $sale_type
     * @return ResponseInterface|string
     */
    public function change_register_mode(int $sale_type): ResponseInterface|string
    {
        $mode = match ($sale_type) {
            SALE_TYPE_QUOTE => 'sale_quote',
            SALE_TYPE_WORK_ORDER => 'sale_work_order',
            SALE_TYPE_INVOICE => 'sale_invoice',
            SALE_TYPE_RETURN => 'return',
            default => 'sale' // SALE_TYPE_POS
        };

        $this->sale_lib->set_mode($mode);
        return $this->reload();
    }


    /**
     * Sets the sales comment. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetComment(): ResponseInterface
    {
        $this->sale_lib->set_comment($this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the invoice number. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetInvoiceNumber(): ResponseInterface|string
    {
        // Keep alphanumeric invoice formats (e.g. INVC-{ISEQ}). NUMBER_INT strips letters
        // and turns "INVC-10" into "-10".
        $this->sale_lib->set_invoice_number($this->request->getPost('sales_invoice_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * @return ResponseInterface
     */
    public function postSetPaymentType(): ResponseInterface|string    // TODO: This function does not appear to be called anywhere in the code.
    {
        $this->sale_lib->set_payment_type($this->request->getPost('selected_payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->reload();
    }

    /**
     * Sets PrintAfterSale flag. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSetPrintAfterSale(): ResponseInterface
    {
        $this->sale_lib->set_print_after_sale($this->request->getPost('sales_print_after_sale') != 'false');
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the flag to include prices in the work order. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetPriceWorkOrders(): ResponseInterface
    {
        $price_work_orders = parse_decimals($this->request->getPost('price_work_orders'));
        $this->sale_lib->set_price_work_orders($price_work_orders);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the flag to email receipt to the customer. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetEmailReceipt(): ResponseInterface
    {
        $this->sale_lib->set_email_receipt($this->request->getPost('email_receipt', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Add a payment to the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postAddPayment(): ResponseInterface|string
    {
        $data = [];
        $giftcard = model(Giftcard::class);
        $paymentType = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!is_string($paymentType) || $paymentType === '') {
            $data['error'] = lang('Sales.must_enter_numeric');

            return $this->reload($data);
        }

        if (
            $paymentType !== lang('Sales.giftcard')
            && $paymentType !== lang('Sales.rewards')
            && (str_contains($paymentType, lang('Sales.giftcard')) || str_contains($paymentType, lang('Sales.rewards')))
        ) {
            $data['error'] = lang('Sales.must_enter_numeric');

            return $this->reload($data);
        }

        if ($paymentType === lang('Sales.giftcard')) {
            $rules    = ['amount_tendered' => 'trim|required|integer']; //For giftcards, amount_tendered becomes the giftcard number which must be an integer
            $messages = ['amount_tendered' => lang('Sales.must_enter_numeric_giftcard')];
        } elseif (in_array($paymentType, get_reference_code_payment_types())) {
            $min      = (int)($this->config['payment_reference_code_min'] ?? 3);
            $max      = (int)($this->config['payment_reference_code_max'] ?? 20);
            // Returns use negative tendered amounts (cash refund / reverse payment).
            $amountRule = $this->sale_lib->is_return_mode()
                ? 'trim|required|decimal_locale'
                : 'trim|required|decimal_locale|nonNegativeDecimal';
            $rules    = [
                'amount_tendered' => $amountRule,
                'reference_code'  => "trim|required|alpha_numeric|min_length[$min]|max_length[$max]",
            ];
            $messages = [
                'amount_tendered' => [
                    'required'           => lang('Sales.must_enter_numeric'),
                    'decimal_locale'     => lang('Sales.must_enter_numeric'),
                    'nonNegativeDecimal' => lang('Sales.negative_amount_invalid'),
                ],
                'reference_code'  => [
                    'required'      => lang('Sales.must_enter_reference_code'),
                    'alpha_numeric' => lang('Sales.reference_code_invalid_characters'),
                    'min_length'    => lang('Sales.reference_code_length_error'),
                    'max_length'    => lang('Sales.reference_code_length_error'),
                ],
            ];
        } else {
            $amountRule = $this->sale_lib->is_return_mode()
                ? 'trim|required|decimal_locale'
                : 'trim|required|decimal_locale|nonNegativeDecimal';
            $rules    = ['amount_tendered' => $amountRule];
            $messages = [
                'amount_tendered' => [
                    'required'           => lang('Sales.must_enter_numeric'),
                    'decimal_locale'     => lang('Sales.must_enter_numeric'),
                    'nonNegativeDecimal' => lang('Sales.negative_amount_invalid'),
                ],
            ];
        }

        if (!$this->validate($rules, $messages)) {
            $errors = $this->validator->getErrors();
            $data['error'] = $errors ? reset($errors) : lang('Sales.must_enter_numeric');
        } else {
            if ($paymentType === lang('Sales.giftcard')) {
                // For giftcard payments, the register input amount_tendered becomes the giftcard number
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $giftcardNumber = $amountTendered;

                $payments = $this->sale_lib->getPayments();
                $paymentType = $paymentType . ':' . $giftcardNumber;
                $currentPaymentsWithGiftcard = isset($payments[$paymentType]) ? $payments[$paymentType]['payment_amount'] : 0;
                $currentGiftcardValue = $giftcard->get_giftcard_value($giftcardNumber);
                $currentGiftcardCustomer = $giftcard->get_giftcard_customer($giftcardNumber);
                $customerId = $this->sale_lib->get_customer();

                if (isset($currentGiftcardCustomer) && $currentGiftcardCustomer != $customerId && $currentGiftcardCustomer != null) {
                    $data['error'] = lang('Giftcards.cannot_use', [$giftcardNumber]);
                } elseif (($currentGiftcardValue - $currentPaymentsWithGiftcard) <= 0 && $this->sale_lib->get_mode() === 'sale') {
                    $data['error'] = lang('Giftcards.remaining_balance', [$giftcardNumber, $currentGiftcardValue]);
                } else {
                    $newGiftcardValue = $giftcard->get_giftcard_value($giftcardNumber) - $this->sale_lib->get_amount_due();
                    $newGiftcardValue = max($newGiftcardValue, 0);
                    $this->sale_lib->set_giftcard_remainder($newGiftcardValue);
                    $newGiftcardValue = to_currency($newGiftcardValue);
                    $data['warning'] = lang('Giftcards.remaining_balance', [$giftcardNumber, $newGiftcardValue]);
                    $amountTendered = min($this->sale_lib->get_amount_due(), $giftcard->get_giftcard_value($giftcardNumber));

                    $this->sale_lib->addPayment($paymentType, $amountTendered);
                }
            } elseif ($paymentType === lang('Sales.rewards')) {
                $customerId = $this->sale_lib->get_customer();
                $packageId = $this->customer->get_info($customerId)->package_id;
                if (!empty($packageId)) {
                    $points = $this->customer->get_info($customerId)->points;
                    $points = ($points == null ? 0 : $points);

                    $payments = $this->sale_lib->getPayments();
                    $currentPaymentsWithRewards = isset($payments[$paymentType]) ? $payments[$paymentType]['payment_amount'] : 0;
                    $curRewardsValue = $points;

                    if (($curRewardsValue - $currentPaymentsWithRewards) <= 0) {
                        $data['error'] = lang('Sales.rewards_remaining_balance') . to_currency($curRewardsValue);
                    } else {
                        $newRewardValue = $points - $this->sale_lib->get_amount_due();
                        $newRewardValue = max($newRewardValue, 0);
                        $this->sale_lib->set_rewards_remainder($newRewardValue);
                        $newRewardValue = str_replace('$', '\$', to_currency($newRewardValue));
                        $data['warning'] = lang('Sales.rewards_remaining_balance') . $newRewardValue;
                        $amountTendered = min($this->sale_lib->get_amount_due(), $points);

                        $this->sale_lib->addPayment($paymentType, $amountTendered);
                    }
                }
            } elseif ($paymentType === lang('Sales.cash')) {
                $amountDue = $this->sale_lib->get_total();
                $salesTotal = $this->sale_lib->get_total(false);
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $this->sale_lib->addPayment($paymentType, $amountTendered);
                $cashAdjustmentAmount = $amountDue - $salesTotal;
                if ($cashAdjustmentAmount <> 0) {
                    $this->session->set('cash_mode', CASH_MODE_TRUE);
                    $this->sale_lib->addPayment(lang('Sales.cash_adjustment'), $cashAdjustmentAmount, null, CASH_ADJUSTMENT_TRUE);
                }
            } else {
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $referenceCode = $this->request->getPost('reference_code');
                $this->sale_lib->addPayment($paymentType, $amountTendered, $referenceCode);
            }
        }

        return $this->reload($data);
    }

    /**
     * Multiple Payments. Used in app/Views/sales/register.php
     *
     * @param string $payment_id
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getDeletePayment(string $payment_id): ResponseInterface|string
    {
        helper('url');

        $this->sale_lib->delete_payment(base64url_decode($payment_id));

        return $this->reload();
    }

    /**
     * Add an item to the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postAdd(): ResponseInterface|string
    {
        $data = [];

        $discount = $this->config['default_sales_discount'];
        $discount_type = $this->config['default_sales_discount_type'];

        // Check if any discount is assigned to the selected customer
        $customer_id = $this->sale_lib->get_customer();
        if ($customer_id != NEW_ENTRY) {
            // Load the customer discount if any
            $customer_discount = $this->customer->get_info($customer_id)->discount;
            $customer_discount_type = $this->customer->get_info($customer_id)->discount_type;
            if ($customer_discount != '') {
                $discount = $customer_discount;
                $discount_type = $customer_discount_type;
            }
        }

        $item_id_or_number_or_item_kit_or_receipt = $this->request->getPost('item', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->token_lib->parse_barcode($quantity, $price, $item_id_or_number_or_item_kit_or_receipt);
        $mode = $this->sale_lib->get_mode();
        $quantity = ($mode == 'return') ? -$quantity : $quantity;
        $item_location = $this->sale_lib->get_sale_location();

        if ($mode == 'return' && $this->sale->isValidReceipt($item_id_or_number_or_item_kit_or_receipt)) {
            try {
                $this->sale_lib->return_entire_sale($item_id_or_number_or_item_kit_or_receipt);
            } catch (\RuntimeException $e) {
                $data['error'] = $e->getMessage();
            }
        } elseif ($this->item_kit->is_valid_item_kit($item_id_or_number_or_item_kit_or_receipt)) {
            // Add kit item to order if one is assigned
            $pieces = explode(' ', $item_id_or_number_or_item_kit_or_receipt);

            $item_kit_id = (count($pieces) > 1) ? $pieces[1] : $item_id_or_number_or_item_kit_or_receipt;
            $item_kit_info = $this->item_kit->get_info($item_kit_id);
            $kit_item_id = $item_kit_info->kit_item_id;
            $kit_price_option = $item_kit_info->price_option;
            $kit_print_option = $item_kit_info->print_option; // 0-all, 1-priced, 2-kit-only

            if ($discount_type == $item_kit_info->kit_discount_type) {
                if ($item_kit_info->kit_discount > $discount) {
                    $discount = $item_kit_info->kit_discount;
                }
            } else {
                $discount = $item_kit_info->kit_discount;
                $discount_type = $item_kit_info->kit_discount_type;
            }

            $print_option = PRINT_ALL; // Always include in list of items on invoice // TODO: This variable is never used in the code

            if (!empty($kit_item_id)) {
                if (!$this->sale_lib->add_item($kit_item_id, $item_location, $quantity, $discount, $discount_type, PRICE_MODE_KIT, $kit_price_option, $kit_print_option, $price)) {
                    $data['error'] = lang('Sales.unable_to_add_item');
                } else {
                    $data['warning'] = $this->sale_lib->out_of_stock($item_kit_id, $item_location);
                }
            }

            // Add item kit items to order
            $stock_warning = null;
            if (!$this->sale_lib->add_item_kit($item_id_or_number_or_item_kit_or_receipt, $item_location, $discount, $discount_type, $kit_price_option, $kit_print_option, $stock_warning)) {
                $data['error'] = lang('Sales.unable_to_add_item');
            } elseif ($stock_warning != null) {
                $data['warning'] = $stock_warning;
            }
        } else {
            if ($item_id_or_number_or_item_kit_or_receipt == '' || !$this->sale_lib->add_item($item_id_or_number_or_item_kit_or_receipt, $item_location, $quantity, $discount, $discount_type, PRICE_MODE_STANDARD, null, null, $price)) {
                $data['error'] = lang('Sales.unable_to_add_item');
            } else {
                $data['warning'] = $this->sale_lib->out_of_stock($item_id_or_number_or_item_kit_or_receipt, $item_location);
            }
        }

        return $this->reload($data);
    }

    /**
     * Edit an item in the sale. Used in app/Views/sales/register.php
     *
     * @param string $line
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postEditItem(string $line): ResponseInterface|string
    {
        $data = [];

        $rules = [
            'price'    => 'trim|required|decimal_locale|nonNegativeDecimal',
            'quantity' => 'trim|required|decimal_locale',
            'discount' => 'trim|permit_empty|decimal_locale|nonNegativeDecimal',
        ];

        $messages = [
            'price' => [
                'nonNegativeDecimal' => lang('Sales.negative_price_invalid'),
            ],
            'discount' => [
                'nonNegativeDecimal' => lang('Sales.negative_discount_invalid'),
            ],
        ];

        if ($this->validate($rules, $messages)) {
            $description = $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $serialnumber = $this->request->getPost('serialnumber', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $price = parse_decimals($this->request->getPost('price'));
            $price = $price !== false ? number_format((float) $price, totals_decimals(), '.', '') : $price;
            $quantity = parse_decimals($this->request->getPost('quantity'));
            $discount_type = $this->request->getPost('discount_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $discount = $discount_type
                ? parse_quantity($this->request->getPost('discount'))
                : parse_decimals($this->request->getPost('discount'));
            $discount = $discount ?: 0;

            // Return mode legitimately uses negative quantities for refunds
            if ($this->sale_lib->get_mode() != 'return' && $quantity < 0) {
                $data['error'] = lang('Sales.negative_quantity_invalid');
                return $this->reload($data);
            }

            // Business logic: discount bounds depend on discount_type and item values
            if ($discount_type == PERCENT && $discount > 100) {
                $data['error'] = lang('Sales.discount_percent_exceeds_100');
                return $this->reload($data);
            }

            $precision = totals_decimals();
            if ($discount_type == FIXED && bccomp((string)$discount, bcmul((string)abs($quantity), (string)$price, $precision), $precision) > 0) {
                $data['error'] = lang('Sales.discount_exceeds_item_total');
                return $this->reload($data);
            }

            // sales_change_price grant is enforced server-side here; the UI-only "change_price" flag must not be trusted
            $current_price = $this->sale_lib->get_cart()[$line]['price'] ?? null;
            $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
            if ($current_price !== null && bccomp((string)$price, (string)$current_price, $precision) != 0 && !$this->employee->has_grant('sales_change_price', $employee_id)) {
                $data['error'] = lang('Sales.not_authorized');
                return $this->reload($data);
            }

            $item_location = $this->request->getPost('location', FILTER_SANITIZE_NUMBER_INT);
            $discounted_total = $this->request->getPost('discounted_total') != ''
                ? parse_decimals($this->request->getPost('discounted_total') ?? '')
                : null;

            $this->sale_lib->edit_item($line, $description, $serialnumber, $quantity, $discount, $discount_type, $price, $discounted_total);

            $this->sale_lib->empty_payments();

            $data['warning'] = $this->sale_lib->out_of_stock($this->sale_lib->get_item_id($line), $item_location);
        } else {
            $errors = $this->validator->getErrors();
            $data['error'] = $errors ? reset($errors) : lang('Sales.error_editing_item');
        }

        return $this->reload($data);
    }

    /**
     * Deletes an item specified in the parameter from the shopping cart. Used in app/Views/sales/register.php
     *
     * @param int $item_id
     * @return ResponseInterface
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function getDeleteItem(int $item_id): ResponseInterface|string
    {
        $this->sale_lib->delete_item($item_id);

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * Remove the current customer from the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getRemoveCustomer(): ResponseInterface|string
    {
        $this->sale_lib->clear_giftcard_remainder();
        $this->sale_lib->clear_rewards_remainder();
        $this->sale_lib->delete_payment(lang('Sales.rewards'));
        $this->sale_lib->clear_invoice_number();
        $this->sale_lib->clear_quote_number();
        $this->sale_lib->remove_customer();

        return $this->reload();
    }

    /**
     * Complete and finalize a sale. Used in app/Views/sales/register.php
     *
     * @return string
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postComplete(): string    // TODO: this function is huge.  Probably should be refactored.
    {
        $saleId = $this->sale_lib->get_sale_id();
        $data = [];
        $data['dinner_table'] = $this->sale_lib->get_dinner_table();

        $data['cart'] = $this->sale_lib->get_cart();

        $data['include_hsn'] = (bool)$this->config['include_hsn'];
        $time = time();
        $data['transaction_time'] = to_datetime($time);
        $data['transaction_date'] = to_date($time);
        $data['show_stock_locations'] = $this->stock_location->show_locations('sales');
        $data['comments'] = $this->sale_lib->get_comment();
        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $employeeInfo = $this->employee->get_info($employeeId);
        $data['employee'] = $employeeInfo->first_name . ' ' . mb_substr($employeeInfo->last_name, 0, 1);

        $data['company_info'] = implode("\n", [$this->config['address'], $this->config['phone']]);

        if ($this->config['account_number']) {
            $data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->config['account_number'];
        }

        if ($this->config['tax_id'] != '') {
            $data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->config['tax_id'];
        }

        $data['invoice_number_enabled'] = $this->sale_lib->is_invoice_mode();
        $data['cur_giftcard_value'] = $this->sale_lib->get_giftcard_remainder();
        $data['cur_rewards_value'] = $this->sale_lib->get_rewards_remainder();
        $data['print_after_sale'] = $this->session->get('sales_print_after_sale');
        $data['price_work_orders'] = $this->sale_lib->is_price_work_orders();
        $data['email_receipt'] = $this->sale_lib->is_email_receipt();
        $customerId = $this->sale_lib->get_customer();
        $invoiceNumber = $this->sale_lib->get_invoice_number();
        $data["invoice_number"] = $invoiceNumber;
        $workOrderNumber = $this->sale_lib->get_work_order_number();
        $data["work_order_number"] = $workOrderNumber;
        $quoteNumber = $this->sale_lib->get_quote_number();
        $data["quote_number"] = $quoteNumber;
        $customerInfo = $this->_load_customer_data($customerId, $data);

        if ($customerInfo != null) {
            $data["customer_comments"] = $customerInfo->comments;
            $data['tax_id'] = $customerInfo->tax_id;
        }
        $taxDetails = $this->tax_lib->get_taxes($data['cart']);    // TODO: Duplicated code
        $data['taxes'] = $taxDetails[0];
        $data['discount'] = $this->sale_lib->get_discount();
        $data['payments'] = $this->sale_lib->getPayments();

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($taxDetails[0]);
        $data['subtotal'] = $totals['subtotal'];
        $data['total'] = $totals['total'];
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total'];
        $data['cash_rounding'] = $this->session->get('cash_rounding');
        $data['cash_mode'] = $this->session->get('cash_mode');    // TODO: Duplicated code
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        // Prevent negative total sales (fraud/theft vector) - returns can have negative totals for legitimate refunds
        if ($this->sale_lib->get_mode() != 'return' && bccomp($totals['total'], '0') < 0) {
            $data['error'] = lang('Sales.negative_total_invalid');
            return $this->reload($data);
        }

        if (!$totals['payments_cover_total']
            && !$this->sale_lib->is_invoice_mode()
            && !$this->sale_lib->is_quote_mode()
            && !$this->sale_lib->return_settlement_covers_total()
        ) {
            $data['error'] = lang('Sales.amount_due_not_covered');
            return $this->reload($data);
        }

        // AR return settlement: no cash tender required — clear accidental payments so cashup stays clean.
        if ($this->sale_lib->return_settlement_covers_total()) {
            $this->sale_lib->empty_payments();
            $data['payments'] = [];
            $data['payments_total'] = 0;
            $data['payments_cover_total'] = true;
            $data['amount_due'] = 0;
            $data['amount_change'] = 0;
        } elseif ($data['cash_mode']) {    // TODO: Convert this to ternary notation
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['amount_due'] = $totals['amount_due'];
        }

        if (!$this->sale_lib->return_settlement_covers_total()) {
            $data['amount_change'] = $data['amount_due'] * -1;
        }

        if ($data['amount_change'] > 0) {
            // Save cash refund to the cash payment transaction if found, if not then add as new Cash transaction

            if (array_key_exists(lang('Sales.cash'), $data['payments'])) {
                $data['payments'][lang('Sales.cash')]['cash_refund'] = $data['amount_change'];
            } else {
                $payment = [
                    lang('Sales.cash') => [
                        'payment_type'   => lang('Sales.cash'),
                        'payment_amount' => 0,
                        'cash_refund'    => $data['amount_change']
                    ]
                ];

                $data['payments'] += $payment;
            }
        }

        $data['print_price_info'] = true;

        if ($this->sale_lib->is_invoice_mode()) {
            $invoiceFormat = $this->config['sales_invoice_format'];

            // Generate final invoice number (if using the invoice in sales by receipt mode then the invoice number can be manually entered or altered in some way
            if (!empty($invoiceFormat) && $invoiceNumber == null) {
                // The user can retain the default encoded format or can manually override it.  It still passes through the rendering step.
                $invoiceNumber = $this->token_lib->render($invoiceFormat);
            }


            if ($saleId == NEW_ENTRY && $this->sale->check_invoice_number_exists($invoiceNumber)) {
                $data['error'] = lang('Sales.invoice_number_duplicate', [$invoiceNumber]);
                return $this->reload($data);
            } else {
                $data['invoice_number'] = $invoiceNumber;
                $data['sale_status'] = COMPLETED;
                $saleType = SALE_TYPE_INVOICE;

                $invoiceType = $this->config['invoice_type'];
                if (!Sale_lib::isValidInvoiceType($invoiceType)) {
                    $invoiceType = 'invoice';
                }
                $invoiceView = $invoiceType;

                // Save the data to the sales table
                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails);
                $data['sale_id'] = 'POS ' . $data['sale_id_num'];

                // Resort and filter cart lines for printing
                $data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                } else {
                    $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
                    $data['page_title'] = $this->buildSaleDocumentTitle('INV', (string) ($invoiceNumber ?: $data['sale_id_num']));
                    $data['print_filename'] = $data['page_title'];
                    $this->sale_lib->clear_all();
                    return view('sales/' . $invoiceView, $data);
                }
            }
        } elseif ($this->sale_lib->is_work_order_mode()) {

            if (!($data['price_work_orders'] == 1)) {
                $data['print_price_info'] = false;
            }

            $data['sales_work_order'] = lang('Sales.work_order');
            $data['work_order_number_label'] = lang('Sales.work_order_number');

            if ($workOrderNumber == null) {
                // Generate work order number
                $workOrderFormat = $this->config['work_order_format'];
                $workOrderNumber = $this->token_lib->render($workOrderFormat);
            }

            if ($saleId == NEW_ENTRY && $this->sale->check_work_order_number_exists($workOrderNumber)) {
                $data['error'] = lang('Sales.work_order_number_duplicate');
                return $this->reload($data);
            } else {
                $data['work_order_number'] = $workOrderNumber;
                $data['sale_status'] = SUSPENDED;
                $saleType = SALE_TYPE_WORK_ORDER;

                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails);

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                }

                $this->sale_lib->set_suspended_id($data['sale_id_num']);

                $data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

                $data['barcode'] = null;

                $this->sale_lib->clear_all();
                return view('sales/work_order', $data);
            }
        } elseif ($this->sale_lib->is_quote_mode()) {
            $data['sales_quote'] = lang('Sales.quote');
            $data['quote_number_label'] = lang('Sales.quote_number');

            if ($quoteNumber == null) {
                // Generate quote number
                $quoteFormat = $this->config['sales_quote_format'];
                $quoteNumber = $this->token_lib->render($quoteFormat);
            }

            if ($saleId == NEW_ENTRY && $this->sale->check_quote_number_exists($quoteNumber)) {
                $data['error'] = lang('Sales.quote_number_duplicate');
                return $this->reload($data);
            } else {
                $data['quote_number'] = $quoteNumber;
                $data['sale_status'] = SUSPENDED;
                $saleType = SALE_TYPE_QUOTE;

                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails);

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                }

                $this->sale_lib->set_suspended_id($data['sale_id_num']);

                $data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);
                $data['barcode'] = null;

                $this->sale_lib->clear_all();
                return view('sales/quote', $data);
            }
        } else {
            // Save the data to the sales table
            $data['sale_status'] = COMPLETED;
            if ($this->sale_lib->is_return_mode()) {
                $saleType = SALE_TYPE_RETURN;
            } else {
                $saleType = SALE_TYPE_POS;
            }

            // Enforce cumulative returnable quantities before persisting.
            if ($saleType === SALE_TYPE_RETURN) {
                log_message('error', 'RETURN DEBUG postComplete cart: ' . json_encode($data['cart']));
                log_message('error', 'RETURN DEBUG totals: ' . json_encode($totals));
                log_message('error', 'RETURN DEBUG sale_id: ' . $saleId);
                log_message('error', 'RETURN DEBUG return_of_sale_id: ' . $this->sale_lib->get_return_of_sale_id());
                log_message('error', 'RETURN DEBUG settlement: ' . $this->sale_lib->get_return_settlement());

                if ($data['cart'] === [] || abs((float) $totals['total']) < 0.00001) {
                    $data['error'] = lang('Sales.return_empty_cart');

                    return $this->reload($data);
                }
                foreach ($data['cart'] as $line) {
                    if ((float) ($line['quantity'] ?? 0) >= 0) {
                        $data['error'] = lang('Sales.return_quantity_must_be_negative');

                        return $this->reload($data);
                    }
                }
                $returnOfSaleId = $this->sale_lib->get_return_of_sale_id();
                $accountLib = new \App\Libraries\Customer_account_lib();
                if ($returnOfSaleId !== null) {
                    try {
                        $this->sale_lib->assertReturnCustomer($returnOfSaleId);
                        if (!$accountLib->saleHasReturnableQuantity($returnOfSaleId)) {
                            $data['error'] = lang('Sales.return_nothing_left');

                            return $this->reload($data);
                        }
                        $accountLib->assertReturnCartWithinRemaining(
                            $returnOfSaleId,
                            $data['cart']
                        );
                        $settlementPreview = $this->sale_lib->get_return_settlement();
                        if ($settlementPreview === 'outstanding') {
                            $accountLib->assertReturnOutstandingAmount(
                                $returnOfSaleId,
                                abs((float) $totals['total'])
                            );
                        }
                        if ($settlementPreview === 'cash') {
                            $accountLib->assertCashRefundEligible(
                                $returnOfSaleId,
                                abs((float) $totals['total'])
                            );
                        }
                    } catch (\RuntimeException $e) {
                        $data['error'] = $e->getMessage();

                        return $this->reload($data);
                    }
                }
            }

            // Settlement covers tender: clear payments; cash mode records cash_refund for cashup.
            if ($saleType === SALE_TYPE_RETURN && $this->sale_lib->return_settlement_covers_total()) {
                $this->sale_lib->empty_payments();
                $data['payments'] = [];
                if ($this->sale_lib->get_return_settlement() === 'cash') {
                    $refundAmt = abs((float) $totals['total']);
                    $cashLabel = lang('Sales.cash');
                    $data['payments'][$cashLabel] = [
                        'payment_type'    => $cashLabel,
                        'payment_amount'  => 0,
                        'cash_refund'     => $refundAmt,
                        'cash_adjustment' => 0,
                        'reference_code'  => '',
                    ];
                }
            }

            $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails);

            /**

             * RETURN DEBUG: Verify that return items were actually persisted

             * to sales_items after save_value() creates the return sale.

             */

            if ($saleType === SALE_TYPE_RETURN && $data['sale_id_num'] > 0) {

                $savedReturnItems = $this->sale->db

                    ->table('sales_items')

                    ->where('sale_id', $data['sale_id_num'])

                    ->get()

                    ->getResultArray();

                log_message('error', 'RETURN DEBUG persisted sales_items: ' . json_encode([

                        'sale_id' => $data['sale_id_num'],

                        'count' => count($savedReturnItems),

                        'items' => $savedReturnItems,

                    ]));

            }

            $data['sale_id'] = 'POS ' . $data['sale_id_num'];

            $data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

            if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                return $this->reload($data);
            } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                $data['error_message'] = lang('Sales.insufficient_reward_points');
                return $this->reload($data);
            } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                $data['error_message'] = lang('Sales.transaction_failed');
                return $this->reload($data);
            } else {
                if ($saleType === SALE_TYPE_RETURN && $data['sale_id_num'] > 0) {
                    $returnOfSaleId = $this->sale_lib->get_return_of_sale_id();
                    if ($returnOfSaleId !== null) {
                        $this->sale->db->table('sales')
                            ->where('sale_id', $data['sale_id_num'])
                            ->update(['return_of_sale_id' => $returnOfSaleId]);
                    }
                    $settlementMode = $this->sale_lib->get_return_settlement();
                    try {
                        (new \App\Libraries\Customer_account_lib())->onReturnSaleCompleted(
                            (int) $data['sale_id_num'],
                            (int) $employeeId,
                            $settlementMode
                        );
                    } catch (\Throwable $e) {
                        log_message('error', 'Return credit processing failed: ' . $e->getMessage());
                        // Fail closed: reverse the orphan return document + stock.
                        try {
                            $this->sale->delete_list(
                                [(int) $data['sale_id_num']],
                                (int) $employeeId,
                                false
                            );
                        } catch (\Throwable $delEx) {
                            log_message('error', 'Return rollback delete failed: ' . $delEx->getMessage());
                        }
                        $data['error'] = $e instanceof \RuntimeException
                            ? $e->getMessage()
                            : lang('Sales.return_accounting_failed');

                        return $this->reload($data);
                    }
                    $this->sale_lib->clear_return_of_sale_id();
                    $this->sale_lib->clear_return_settlement();
                }

                $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
                $data['page_title'] = $this->buildSaleDocumentTitle('SR', (string) $data['sale_id_num']);
                $data['print_filename'] = $data['page_title'];

                // Validate receipt template to prevent path traversal
                $receiptTemplate = $this->config['receipt_template'] ?? '';
                if (!Sale_lib::isValidReceiptTemplate($receiptTemplate)) {
                    $receiptTemplate = 'receipt_default';
                }
                $data['receipt_template_view'] = $receiptTemplate;

                $this->sale_lib->clear_all();
                return view('sales/receipt', $data);
            }
        }
    }

    /**
     * Email PDF invoice to customer. Used in app/Views/sales/form.php, invoice.php, quote.php, tax_invoice.php and work_order.php
     *
     * @param int $saleId
     * @param string $type
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSendPdf(int $saleId, string $type = 'invoice'): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleData = $this->_load_sale_data($saleId);

        $result = false;
        $message = lang('Sales.invoice_no_email');

        if (!empty($saleData['customer_email'])) {
            $to = $saleData['customer_email'];
            $number = array_key_exists($type . "_number", $saleData) ?  $saleData[$type . "_number"] : "";
            $subject = lang('Sales.' . $type) . ' ' . $number;

            $text = $this->config['invoice_email_message'];
            $tokens = [
                new Token_invoice_sequence($number),
                new Token_invoice_count('POS ' . $saleData['sale_id']),
                new Token_customer((array)$saleData)
            ];
            $text = $this->token_lib->render($text, $tokens);
            $saleData['mimetype'] = $this->email_lib->getLogoMimeType();

            // Build img_tag for email views that need it (receipt_email.php)
            $saleData['img_tag'] = $this->email_lib->buildLogoImgTag();

            // Generate email attachment: invoice in PDF format
            $view = Services::renderer();
            $html = $view->setData($saleData)->render("sales/$type" . '_email', $saleData);

            // Load PDF helper
            helper(['dompdf', 'file']);
            $prefix = $type === 'receipt' ? 'SR' : 'INV';
            if (in_array($type, ['quote', 'work_order'], true)) {
                $prefix = strtoupper($type === 'work_order' ? 'WO' : 'Q');
            }
            $docTitle = $this->buildSaleDocumentTitle($prefix, $number !== '' ? (string) $number : (string) $saleId);
            $filename = sys_get_temp_dir() . '/' . $docTitle . '.pdf';
            if (file_put_contents($filename, create_pdf($html)) !== false) {
                $result = $this->email_lib->sendEmail($to, $subject, $text, $filename);
            }

            $message = lang($result ? "Sales." . $type . "_sent" : "Sales." . $type . "_unsent") . ' ' . $to;
        }

        $this->sale_lib->clear_all();

        return $this->response->setJSON(['success' => $result, 'message' => $message, 'id' => $saleId]);
    }

    /**
     * Emails sales receipt to customer. Used in app/Views/sales/receipt.php
     *
     * @param int $saleId
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSendReceipt(int $saleId): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleData = $this->_load_sale_data($saleId);

        $result = false;
        $message = lang('Sales.receipt_no_email');

        if (!empty($saleData['customer_email'])) {
            $saleData['barcode'] = $this->barcode_lib->generate_receipt_barcode($saleData['sale_id']);
            $saleData['img_tag'] = $this->email_lib->buildLogoImgTag();

            $to = $saleData['customer_email'];
            $subject = lang('Sales.receipt');

            $view = Services::renderer();
            $text = $view->setData($saleData)->render('sales/receipt_email');

            $result = $this->email_lib->sendEmail($to, $subject, $text);

            $message = lang($result ? 'Sales.receipt_sent' : 'Sales.receipt_unsent') . ' ' . $to;
        }

        $this->sale_lib->clear_all();

        return $this->response->setJSON(['success' => $result, 'message' => $message, 'id' => $saleId]);
    }

    /**
     * @param int $customer_id
     * @param array $data
     * @param bool $stats
     * @return array|stdClass|string|null
     */
    private function _load_customer_data(int $customer_id, array &$data, bool $stats = false): array|string|stdClass|null    // TODO: Hungarian notation
    {
        $customer_info = '';

        if ($customer_id != NEW_ENTRY) {
            $customer_info = $this->customer->get_info($customer_id);
            $data['customer_id'] = $customer_id;

            if (!empty($customer_info->company_name)) {
                $data['customer'] = $customer_info->company_name;
            } else {
                $data['customer'] = $customer_info->first_name . ' ' . $customer_info->last_name;
            }

            $data['first_name'] = $customer_info->first_name;
            $data['last_name'] = $customer_info->last_name;
            $data['customer_email'] = $customer_info->email;
            $data['customer_address'] = $customer_info->address_1;

            if (!empty($customer_info->zip) || !empty($customer_info->city)) {
                $data['customer_location'] = $customer_info->zip . ' ' . $customer_info->city . "\n" . $customer_info->state;
            } else {
                $data['customer_location'] = '';
            }

            $data['customer_account_number'] = $customer_info->account_number;
            $data['customer_discount'] = $customer_info->discount;
            $data['customer_discount_type'] = $customer_info->discount_type;
            $package_id = $this->customer->get_info($customer_id)->package_id;

            if ($package_id != null) {
                $package_name = $this->customer_rewards->get_name($package_id);
                $points = $this->customer->get_info($customer_id)->points;
                $data['customer_rewards']['package_id'] = $package_id;
                $data['customer_rewards']['points'] = empty($points) ? 0 : $points;
                $data['customer_rewards']['package_name'] = $package_name;
            }

            if ($stats) {
                $cust_stats = $this->customer->get_stats($customer_id);
                $data['customer_total'] = empty($cust_stats) ? 0 : $cust_stats->total;
            }

            $data['customer_info'] = implode("\n", [
                $data['customer'],
                $data['customer_address'],
                $data['customer_location']
            ]);

            if ($data['customer_account_number']) {
                $data['customer_info'] .= "\n" . lang('Sales.account_number') . ": " . $data['customer_account_number'];
            }

            if ($customer_info->tax_id != '') {
                $data['customer_info'] .= "\n" . lang('Sales.tax_id') . ": " . $customer_info->tax_id;
            }
            $data['tax_id'] = $customer_info->tax_id;
        }

        return $customer_info;
    }

    /**
     * @param $sale_id
     * @return array
     */
    private function _load_sale_data($sale_id): array    // TODO: Hungarian notation
    {
        $this->sale_lib->clear_all();
        $cash_rounding = $this->sale_lib->reset_cash_rounding();
        $data['cash_rounding'] = $cash_rounding;

        $sale_info = $this->sale->get_info($sale_id)->getRowArray();
        $this->sale_lib->copy_entire_sale($sale_id);
        $data = [];
        $data['print_filename'] = $sale_id . '_' . date('Y-m-d', strtotime($sale_info['sale_time']));

        $data['cart'] = $this->sale_lib->get_cart();
        $data['payments'] = $this->sale_lib->getPayments();
        $data['selected_payment_type'] = $this->sale_lib->get_payment_type();

        $tax_details = $this->tax_lib->get_taxes($data['cart'], $sale_id);
        $data['taxes'] = $this->sale->get_sales_taxes($sale_id);
        $data['discount'] = $this->sale_lib->get_discount();
        $data['transaction_time'] = to_datetime(strtotime($sale_info['sale_time']));
        $data['transaction_date'] = to_date(strtotime($sale_info['sale_time']));
        $data['show_stock_locations'] = $this->stock_location->show_locations('sales');

        $data['include_hsn'] = (bool)$this->config['include_hsn'];

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($tax_details[0]);
        $this->session->set('cash_adjustment_amount', $totals['cash_adjustment_amount']);
        $data['subtotal'] = $totals['subtotal'];
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total'];
        $data['cash_mode'] = $this->session->get('cash_mode');    // TODO: Duplicated code.
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        if ($data['cash_mode'] && ($data['selected_payment_type'] === lang('Sales.cash') || $data['payments_total'] > 0)) {
            $data['total'] = $totals['cash_total'];
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['total'] = $totals['total'];
            $data['amount_due'] = $totals['amount_due'];
        }

        $data['amount_change'] = $data['amount_due'] * -1;

        $employee_info = $this->employee->get_info($this->sale_lib->get_employee());
        $data['employee'] = $employee_info->first_name . ' ' . mb_substr($employee_info->last_name, 0, 1);
        $this->_load_customer_data($this->sale_lib->get_customer(), $data);

        $data['sale_id_num'] = $sale_id;
        $data['sale_id'] = 'POS ' . $sale_id;
        $data['comments'] = $sale_info['comment'];
        $data['invoice_number'] = $sale_info['invoice_number'];
        $data['quote_number'] = $sale_info['quote_number'];
        $data['sale_status'] = $sale_info['sale_status'];

        $data['company_info'] = implode("\n", [$this->config['address'], $this->config['phone']]);    // TODO: Duplicated code.

        if ($this->config['account_number']) {
            $data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->config['account_number'];
        }
        if ($this->config['tax_id'] != '') {
            $data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->config['tax_id'];
        }

        $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
        $data['print_after_sale'] = false;
        $data['price_work_orders'] = false;

        if ($this->sale_lib->get_mode() == 'sale_invoice') {    // TODO: Duplicated code.
            $data['mode_label'] = lang('Sales.invoice');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_quote') {
            $data['mode_label'] = lang('Sales.quote');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_work_order') {
            $data['mode_label'] = lang('Sales.work_order');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'return') {
            $data['mode_label'] = lang('Sales.return');
            $data['customer_required'] = lang('Sales.customer_optional');
        } else {
            $data['mode_label'] = lang('Sales.receipt');
            $data['customer_required'] = lang('Sales.customer_optional');
        }

        $invoice_type = $this->config['invoice_type'];
        if (!Sale_lib::isValidInvoiceType($invoice_type)) {
            $invoice_type = 'invoice';
        }
        $data['invoice_view'] = $invoice_type;

        // Validate receipt template to prevent path traversal
        $receipt_template = $this->config['receipt_template'] ?? '';
        if (!Sale_lib::isValidReceiptTemplate($receipt_template)) {
            $receipt_template = 'receipt_default';
        }
        $data['receipt_template_view'] = $receipt_template;

        // Authoritative AR balance for invoices/receipts (excludes Due placeholders; includes account allocations / return credits).
        $this->applySaleAccountFinancials((int) $sale_id, $data);

        return $data;
    }

    /**
     * Overlay Customer Account financials onto sale document data.
     *
     * @param array<string, mixed> $data
     */
    private function applySaleAccountFinancials(int $saleId, array &$data): void
    {
        $accountLib = new \App\Libraries\Customer_account_lib();
        $financial = $accountLib->getSaleFinancialSummary($saleId);
        $data['financial_summary'] = $financial;
        $data['current_balance'] = $financial['balance'];

        // Drop Due placeholders from document payment lines.
        $displayPayments = [];
        foreach ($data['payments'] ?? [] as $key => $payment) {
            $type = is_array($payment)
                ? (string) ($payment['payment_type'] ?? $key)
                : (string) ($payment->payment_type ?? $key);
            if ($accountLib->isDuePaymentType($type)) {
                continue;
            }
            $displayPayments[$key] = $payment;
        }

        // Append active customer-account allocations as subsequent payments on the document.
        $allocRows = Database::connect()->table('customer_payment_allocations AS cpa')
            ->select('cpa.amount, cap.payment_type, cap.payment_id')
            ->join('customer_account_payments AS cap', 'cap.payment_id = cpa.customer_account_payment_id')
            ->where('cpa.sale_id', $saleId)
            ->where('cpa.status', CA_STATUS_ACTIVE)
            ->where('cap.status', CA_STATUS_ACTIVE)
            ->get()
            ->getResultArray();

        foreach ($allocRows as $alloc) {
            $label = lang('Accounts.account_payment') . ' / ' . $alloc['payment_type'];
            $displayPayments['account_' . $alloc['payment_id']] = [
                'payment_type'   => $label,
                'payment_amount' => (float) $alloc['amount'],
                'cash_refund'    => 0,
            ];
        }

        $returnAllocs = Database::connect()->table('customer_return_credit_allocations AS rca')
            ->select('rca.amount, rc.return_sale_id')
            ->join('customer_return_credits AS rc', 'rc.return_credit_id = rca.return_credit_id')
            ->where('rca.sale_id', $saleId)
            ->where('rca.status', CA_STATUS_ACTIVE)
            ->where('rc.status', CA_STATUS_ACTIVE)
            ->get()
            ->getResultArray();

        foreach ($returnAllocs as $alloc) {
            $label = lang('Accounts.return_credit') . ' / Return #' . $alloc['return_sale_id'];
            $displayPayments['return_' . $alloc['return_sale_id']] = [
                'payment_type'   => $label,
                'payment_amount' => (float) $alloc['amount'],
                'cash_refund'    => 0,
            ];
        }

        $data['payments'] = $displayPayments;
        $data['amount_due'] = $financial['balance'];
        $data['amount_change'] = $financial['balance'] * -1;
        $data['payments_total'] = $financial['payments_applied'];
    }

    /**
     * @param array $data
     * @return void
     */
    private function reload(array $data = []): ResponseInterface|string
    {
        $sale_id = $this->session->get('sale_id');    // TODO: This variable is never used

        if ($sale_id == '') {
            $sale_id = NEW_ENTRY;
            $this->session->set('sale_id', NEW_ENTRY);
        }
        $cash_rounding = $this->sale_lib->reset_cash_rounding();

        // cash_rounding indicates only that the site is configured for cash rounding
        $data['cash_rounding'] = $cash_rounding;

        $data['cart'] = $this->sale_lib->get_cart();
        $customer_info = $this->_load_customer_data($this->sale_lib->get_customer(), $data, true);

        $data['modes'] = $this->sale_lib->get_register_mode_options();
        $data['mode'] = $this->sale_lib->get_mode();
        $data['selected_table'] = $this->sale_lib->get_dinner_table();
        $data['empty_tables'] = $this->sale_lib->get_empty_tables($data['selected_table']);
        $data['stock_locations'] = $this->stock_location->get_allowed_locations('sales');
        $data['stock_location'] = $this->sale_lib->get_sale_location();
        $data['tax_exclusive_subtotal'] = $this->sale_lib->get_subtotal(true, true);
        $tax_details = $this->tax_lib->get_taxes($data['cart']);    // TODO: Duplicated code.
        $data['taxes'] = $tax_details[0];
        $data['discount'] = $this->sale_lib->get_discount();
        $data['payments'] = $this->sale_lib->getPayments();

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($tax_details[0]);

        $data['item_count'] = $totals['item_count'];
        $data['total_units'] = $totals['total_units'];
        $data['subtotal'] = $totals['subtotal'];
        $data['total'] = $totals['total'];
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total']
            || $this->sale_lib->return_settlement_covers_total();

        // cash_mode indicates whether this sale is going to be processed using cash_rounding
        $cash_mode = $this->session->get('cash_mode');
        $data['cash_mode'] = $cash_mode;
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];    // TODO: Duplicated code.
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        $data['selected_payment_type'] = $this->sale_lib->get_payment_type();

        if ($data['cash_mode'] && ($data['selected_payment_type'] == lang('Sales.cash') || $data['payments_total'] > 0)) {
            $data['total'] = $totals['cash_total'];
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['total'] = $totals['total'];
            $data['amount_due'] = $totals['amount_due'];
        }

        $data['amount_change'] = $data['amount_due'] * -1;

        $data['comment'] = $this->sale_lib->get_comment();
        $data['email_receipt'] = $this->sale_lib->is_email_receipt();

        if ($customer_info && $this->config['customer_reward_enable']) {
            $data['payment_options'] = $this->sale->get_payment_options(true, true);
        } else {
            $data['payment_options'] = $this->sale->get_payment_options();
        }

        $data['reference_code_payment_types'] = get_reference_code_payment_types();

        $data['items_module_allowed'] = $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id);
        $data['change_price'] = $this->employee->has_grant('sales_change_price', $this->employee->get_logged_in_employee_info()->person_id);

        $temp_invoice_number = $this->sale_lib->get_invoice_number();
        $invoice_format = $this->config['sales_invoice_format'];

        if ($temp_invoice_number == null || $temp_invoice_number == '') {
            $temp_invoice_number = $this->token_lib->render($invoice_format, [], false);
        }

        $data['invoice_number'] = $temp_invoice_number;

        $data['print_after_sale'] = $this->sale_lib->is_print_after_sale();
        $data['price_work_orders'] = $this->sale_lib->is_price_work_orders();

        $data['pos_mode'] = $data['mode'] == 'sale' || $data['mode'] == 'return';

        $data['quote_number'] = $this->sale_lib->get_quote_number();
        $data['work_order_number'] = $this->sale_lib->get_work_order_number();
        $data['keyboardShortcuts'] = $this->sale_lib->getKeyShortcuts();

        // TODO: the if/else set below should be converted to a switch
        if ($this->sale_lib->get_mode() == 'sale_invoice') {    // TODO: Duplicated code.
            $data['mode_label'] = lang('Sales.invoice');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_quote') {
            $data['mode_label'] = lang('Sales.quote');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_work_order') {
            $data['mode_label'] = lang('Sales.work_order');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'return') {
            $data['mode_label'] = lang('Sales.return');
            $data['customer_required'] = lang('Sales.customer_optional');
        } else {
            $data['mode_label'] = lang('Sales.receipt');
            $data['customer_required'] = lang('Sales.customer_optional');
        }

        $data['return_of_sale_id'] = null;
        $data['return_of_sale_label'] = null;
        $data['return_settlement'] = 'cash';
        $data['return_has_customer'] = false;
        $data['returnable_items'] = [];
        if ($this->sale_lib->is_return_mode()) {
            $data['return_settlement'] = $this->sale_lib->get_return_settlement();
            $custId = $this->sale_lib->get_customer();
            $data['return_has_customer'] = $custId !== NEW_ENTRY && $custId > 0;
            $returnOfSaleId = $this->sale_lib->get_return_of_sale_id();
            if ($returnOfSaleId !== null) {
                $orig = $this->sale->get_info($returnOfSaleId)->getRowArray();
                $data['return_of_sale_id'] = $returnOfSaleId;
                $invoiceNumber = !empty($orig['invoice_number'] ?? null) ? (string) $orig['invoice_number'] : null;
                // Prefer direct DB lookup for invoice number when get_info omits it
                if ($invoiceNumber === null) {
                    $saleRow = Database::connect()->table('sales')->select('invoice_number')->where('sale_id', $returnOfSaleId)->get()->getRowArray();
                    $invoiceNumber = !empty($saleRow['invoice_number']) ? (string) $saleRow['invoice_number'] : null;
                }
                $data['return_of_sale_label'] = $invoiceNumber !== null
                    ? lang('Sales.return_linked_to_invoice', [$invoiceNumber, (string) $returnOfSaleId])
                    : lang('Sales.return_linked_to_sale', [(string) $returnOfSaleId]);
                $data['returnable_items'] = array_values(
                    (new \App\Libraries\Customer_account_lib())->getSaleReturnableItems($returnOfSaleId)
                );
            }
        }

        return view("sales/register", $data);
    }

    /**
     * Set how a return settles: outstanding due, customer credit, or cash refund.
     *
     * @noinspection PhpUnused
     */
    public function postSetReturnSettlement(): ResponseInterface|string
    {
        $mode = (string) $this->request->getPost('return_settlement', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cart = $this->sale_lib->get_cart();
        $returnOfSaleId = $this->sale_lib->get_return_of_sale_id();
        if ($cart === [] || ($returnOfSaleId !== null && !(new \App\Libraries\Customer_account_lib())->saleHasReturnableQuantity($returnOfSaleId))) {
            return $this->reload(['error' => lang('Sales.return_nothing_left')]);
        }
        $this->sale_lib->set_return_settlement($mode);
        if ($this->sale_lib->return_settlement_covers_total()) {
            $this->sale_lib->empty_payments();
        }

        return $this->reload();
    }

    /**
     * Open the register in Return mode linked to a sale/invoice from Sales History.
     *
     * @noinspection PhpUnused
     */
    public function getStartReturn(int $saleId): ResponseInterface
    {
        $personId = $this->session->get('person_id');
        if (!$this->employee->has_grant('sales', $personId)) {
            return redirect()->to('no_access/sales');
        }

        $sale = Database::connect()->table('sales')
            ->select('sale_id, sale_status, sale_type, customer_id')
            ->where('sale_id', $saleId)
            ->get()
            ->getRowArray();

        if ($sale === null || (int) $sale['sale_status'] !== COMPLETED) {
            return redirect()->to('sales/manage');
        }
        if ((int) $sale['sale_type'] === SALE_TYPE_RETURN) {
            return redirect()->to('sales/manage');
        }

        $accountLib = new \App\Libraries\Customer_account_lib();
        if (!$accountLib->saleHasReturnableQuantity($saleId)) {
            return redirect()->to('sales')->with('error', lang('Sales.return_nothing_left'));
        }

        $this->sale_lib->clear_all();
        $this->sale_lib->set_mode('return');
        $this->sale_lib->set_sale_type(SALE_TYPE_RETURN);
        $originalCustomerId = (int) ($sale['customer_id'] ?? 0);
        if ($originalCustomerId > 0) {
            $this->sale_lib->set_customer($originalCustomerId);
        }
        $this->sale_lib->return_entire_sale('POS ' . $saleId);
        if ($this->sale_lib->get_cart() === []) {
            $this->sale_lib->clear_all();

            return redirect()->to('sales')->with('error', lang('Sales.return_nothing_left'));
        }
        $this->sale_lib->set_return_settlement('outstanding');

        return redirect()->to('sales');
    }

    /**
     * Load the sales receipt for a sale. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @noinspection PhpUnused
     */
    public function getReceipt(int $saleId): string|ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();
        $data['page_title'] = $this->buildSaleDocumentTitle('SR', (string) $saleId);
        $data['print_filename'] = $data['page_title'];

        return view('sales/receipt', $data);
    }

    /**
     * Loads the sales invoice for a sale. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @noinspection PhpUnused
     */
    public function getInvoice(int $saleId): string|ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();
        $invoiceNumber = !empty($data['invoice_number']) ? (string) $data['invoice_number'] : (string) $saleId;
        $data['page_title'] = $this->buildSaleDocumentTitle('INV', $invoiceNumber);
        $data['print_filename'] = $data['page_title'];

        return view('sales/' . $data['invoice_view'], $data);
    }

    /**
     * Edits an existing sale or work order. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @throws ReflectionException
     */
    public function getEdit(int $saleId): string|ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = [];

        $saleInfo = $this->sale->get_info($saleId)->getRowArray();
        $data['selected_customer_id'] = $saleInfo['customer_id'];
        $data['selected_customer_name'] = $saleInfo['customer_name'];
        $employeeInfo = $this->employee->get_info($saleInfo['employee_id']);
        $data['selected_employee_id'] = $saleInfo['employee_id'];
        $data['selected_employee_name'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;
        $data['sale_info'] = $saleInfo;

        $accountLib = new \App\Libraries\Customer_account_lib();
        $financial = $accountLib->getSaleFinancialSummary($saleId);
        $data['financial_summary'] = $financial;
        $data['has_financial_references'] = $accountLib->saleHasFinancialReferences($saleId);
        $balanceDue = $financial['balance'];

        $statusLabels = [
            SALE_PAY_STATUS_CANCELLED => lang('Accounts.status_cancelled'),
            SALE_PAY_STATUS_PAID      => lang('Accounts.status_paid'),
            SALE_PAY_STATUS_UNPAID    => lang('Accounts.open'),
            SALE_PAY_STATUS_PARTIAL   => lang('Accounts.status_partially_paid'),
        ];
        $data['pay_status_label'] = $statusLabels[$financial['status']] ?? $financial['status'];

        $data['payments'] = [];

        foreach ($this->sale->get_sale_payments($saleId)->getResult() as $payment) {
            if ($accountLib->isDuePaymentType((string) $payment->payment_type)) {
                // Due is metadata — show in summary only, not as editable payment
                continue;
            }
            foreach (get_object_vars($payment) as $property => $value) {
                $payment->$property = $value;
            }
            $data['payments'][] = $payment;
        }

        $data['payment_type_new'] = PAYMENT_TYPE_UNASSIGNED;
        $data['payment_amount_new'] = $balanceDue;

        $data['balance_due'] = $balanceDue > 0 && !empty($saleInfo['customer_id']);

        // Don't allow gift card to be a payment option in a sale transaction edit because it's a complex change
        $paymentOptions = $this->sale->get_payment_options(false);
        unset($paymentOptions[lang('Sales.due')], $paymentOptions['Due']);

        if ($this->sale_lib->reset_cash_rounding()) {
            $paymentOptions[lang('Sales.cash_adjustment')] = lang('Sales.cash_adjustment');
        }

        $data['payment_options'] = $paymentOptions;
        $data['reference_code_payment_types'] = get_reference_code_payment_types();

        // Set up a slightly modified list of payment types for new payment entry
        $paymentOptions["--"] = lang('Common.none_selected_text');

        $data['new_payment_options'] = $paymentOptions;

        return view('sales/form', $data);
    }

    /**
     * @param int $sale_id
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function postDelete(int $sale_id = NEW_ENTRY, bool $update_inventory = true): ResponseInterface
    {
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $has_grant = $this->employee->has_grant('sales_delete', $employee_id);

        if (!$has_grant) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        } else {
            $sale_ids = $sale_id == NEW_ENTRY ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$sale_id];

            if ($this->sale->delete_list($sale_ids, $employee_id, $update_inventory)) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Sales.successfully_deleted') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
                    'ids'     => $sale_ids
                ]);
            }

            $accountLib = new \App\Libraries\Customer_account_lib();
            foreach ($sale_ids as $id) {
                if ($accountLib->saleHasFinancialReferences((int) $id)) {
                    return $this->response->setJSON([
                        'success' => false,
                        'message' => lang('Accounts.cannot_delete_sale_with_finance'),
                    ]);
                }
            }

            return $this->response->setJSON(['success' => false, 'message' => lang('Sales.unsuccessfully_deleted')]);
        }
    }

    /**
     * @param int $sale_id
     * @param bool $update_inventory
     * @return ResponseInterface
     */
    public function restore(int $sale_id = NEW_ENTRY, bool $update_inventory = true): ResponseInterface
    {
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $has_grant = $this->employee->has_grant('sales_delete', $employee_id);

        if (!$has_grant) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        } else {
            $sale_ids = $sale_id == NEW_ENTRY ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$sale_id];

            if ($this->sale->restore_list($sale_ids, $employee_id, $update_inventory)) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Sales.successfully_restored') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
                    'ids'     => $sale_ids
                ]);
            } else {
                return $this->response->setJSON(['success' => false, 'message' => lang('Sales.unsuccessfully_restored')]);
            }
        }
    }

    /**
     * This saves the sale from the update sale view (sales/form).
     * It only updates the sales table and payments.
     * @param int $saleId
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function postSave(int $saleId = NEW_ENTRY): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $newdate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $inventory = model(Inventory::class);
        $dateFormatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $newdate);
        $saleTime = $dateFormatter->format('Y-m-d H:i:s');

        $saleData = [
            'sale_time'      => $saleTime,
            'customer_id'    => $this->request->getPost('customer_id') != '' ? $this->request->getPost('customer_id', FILTER_SANITIZE_NUMBER_INT) : null,
            'employee_id'    => $this->request->getPost('employee_id') != '' ? $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT) : null,
            'comment'        => $this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'invoice_number' => $this->request->getPost('invoice_number') != '' ? $this->request->getPost('invoice_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null
        ];

        $existingSale = $this->sale->get_info($saleId)->getRowArray();
        $accountLib = new \App\Libraries\Customer_account_lib();
        if (
            !empty($existingSale['customer_id'])
            && empty($saleData['customer_id'])
            && $accountLib->saleHasFinancialReferences($saleId)
        ) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Accounts.cannot_delete_sale_with_finance'),
                'id'      => $saleId,
            ]);
        }

        // Validate reference_code for the new payment if applicable
        $paymentTypeNewCheck = $this->request->getPost('payment_type_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $paymentAmountNewCheck = $this->request->getPost('payment_amount_new');
        if ($paymentTypeNewCheck != PAYMENT_TYPE_UNASSIGNED && !empty($paymentAmountNewCheck)
            && in_array($paymentTypeNewCheck, get_reference_code_payment_types())) {
            $min = (int)($this->config['payment_reference_code_min'] ?? 3);
            $max = (int)($this->config['payment_reference_code_max'] ?? 40);
            $rules = [
                'reference_code_new' => "trim|required|alpha_numeric|min_length[$min]|max_length[$max]",
            ];
            $messages = [
                'reference_code_new' => [
                    'required'      => lang('Sales.must_enter_reference_code'),
                    'alpha_numeric' => lang('Sales.reference_code_invalid_characters'),
                    'min_length'    => lang('Sales.reference_code_length_error'),
                    'max_length'    => lang('Sales.reference_code_length_error'),
                ],
            ];
            if (!$this->validate($rules, $messages)) {
                $errors = $this->validator->getErrors();
                return $this->response->setJSON(['success' => false, 'message' => reset($errors), 'id' => $saleId]);
            }
        }

        // In order to maintain tradition the only element that can change on prior payments is the payment type
        $amountTendered = 0;
        $numberOfPayments = $this->request->getPost('number_of_payments', FILTER_SANITIZE_NUMBER_INT);
        for ($i = 0; $i < $numberOfPayments; ++$i) {
            $paymentId = $this->request->getPost("payment_id_$i", FILTER_SANITIZE_NUMBER_INT);
            $paymentType = $this->request->getPost("payment_type_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $paymentAmount = parse_decimals($this->request->getPost("payment_amount_$i"));
            $refundType = $this->request->getPost("refund_type_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $cashRefund = parse_decimals($this->request->getPost("refund_amount_$i"));
            $referenceCode = $this->request->getPost("reference_code_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null;

            $cashAdjustment = $paymentType == lang('Sales.cash_adjustment') ? CASH_ADJUSTMENT_TRUE : CASH_ADJUSTMENT_FALSE;

            if (!$cashAdjustment) {
                $amountTendered += $paymentAmount - $cashRefund;
            }

            // Non-cash positive refund amounts
            if (empty(strstr($refundType, lang('Sales.cash'))) && $cashRefund > 0) {    // TODO: This if and the one below can be combined.
                // Change it to be a new negative payment (a "non-cash refund")
                $paymentType = $refundType;
                $paymentAmount = $paymentAmount - $cashRefund;
                $cashRefund = 0.00;
            }

            $saleData['payments'][] = [
                'payment_id'      => $paymentId,
                'payment_type'    => $paymentType,
                'payment_amount'  => $paymentAmount,
                'cash_refund'     => $cashRefund,
                'cash_adjustment' => $cashAdjustment,
                'employee_id'     => $employeeId,
                'reference_code'  => $referenceCode,
            ];
        }

        $paymentId = NEW_ENTRY;
        $paymentAmountNew = $this->request->getPost('payment_amount_new');
        $paymentType = $this->request->getPost('payment_type_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $referenceCodeNew = $this->request->getPost('reference_code_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null;

        if ($paymentType != PAYMENT_TYPE_UNASSIGNED && !empty($paymentAmountNew)) {
            $paymentAmount = parse_decimals($paymentAmountNew);
            $cashRefund = 0;
            if ($paymentType == lang('Sales.cash_adjustment')) {
                $cashAdjustment = CASH_ADJUSTMENT_TRUE;
            } else {
                $cashAdjustment = CASH_ADJUSTMENT_FALSE;
                $amountTendered += $paymentAmount;
                $saleInfo = $this->sale->get_info($saleId)->getRowArray();

                if ($amountTendered > $saleInfo['amount_due']) {
                    $cashRefund = $amountTendered - $saleInfo['amount_due'];
                }
            }

            $saleData['payments'][] = [
                'payment_id'      => $paymentId,
                'payment_type'    => $paymentType,
                'payment_amount'  => $paymentAmount,
                'cash_refund'     => $cashRefund,
                'cash_adjustment' => $cashAdjustment,
                'employee_id'     => $employeeId,
                'reference_code'  => $referenceCodeNew,
            ];
        }

        $inventory->update('POS ' . $saleId, ['trans_date' => $saleTime]);    // TODO: Reflection Exception
        if ($this->sale->update($saleId, $saleData)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Sales.successfully_updated'), 'id' => $saleId]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Sales.unsuccessfully_updated'), 'id' => $saleId]);
        }
    }

    /**
     * This is used to cancel a suspended pos sale, quote.
     * Completed sales (POS Sales or Invoiced Sales) can not be removed from the system
     * Work orders can be canceled but are not physically removed from the sales history.
     * Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postCancel(): ResponseInterface|string
    {
        $sale_id = $this->sale_lib->get_sale_id();
        if ($sale_id != NEW_ENTRY && $sale_id != '') {
            $sale_type = $this->sale_lib->get_sale_type();

            if ($this->config['dinner_table_enable']) {
                $dinner_table = $this->sale_lib->get_dinner_table();
                $this->dinner_table->release($dinner_table);
            }

            if ($sale_type == SALE_TYPE_WORK_ORDER) {
                $this->sale->update_sale_status($sale_id, CANCELED);
            } else {
                $this->sale->delete($sale_id);
                $this->session->set('sale_id', NEW_ENTRY);
            }
        } else {
            $this->sale_lib->remove_temp_items();
        }

        $this->sale_lib->clear_all();
        return $this->reload();
    }

    /**
     * Discards the suspended sale. Used in app/Views/sales/quote.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getDiscardSuspendedSale(): ResponseInterface|string
    {
        $suspended_id = $this->sale_lib->get_suspended_id();
        $this->sale_lib->clear_all();
        $this->sale->delete_suspended_sale($suspended_id);
        return $this->reload();
    }

    /**
     * Suspend the current sale.
     * If the current sale is already suspended then update the existing suspended sale otherwise create
     * it as a new suspended sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSuspend(): ResponseInterface|string
    {
        $sale_id = $this->sale_lib->get_sale_id();
        $dinner_table = $this->sale_lib->get_dinner_table();
        $cart = $this->sale_lib->get_cart();
        $payments = $this->sale_lib->getPayments();
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $customer_id = $this->sale_lib->get_customer();
        $invoice_number = $this->sale_lib->get_invoice_number();
        $work_order_number = $this->sale_lib->get_work_order_number();
        $quote_number = $this->sale_lib->get_quote_number();
        $sale_type = $this->sale_lib->get_sale_type();

        if ($sale_type == '') {
            $sale_type = SALE_TYPE_POS;
        }

        $comment = $this->sale_lib->get_comment();
        $sale_status = SUSPENDED;

        $data = [];
        $sales_taxes = [[], []];

        $saleIdNum = $this->sale->save_value($sale_id, $sale_status, $cart, $customer_id, $employee_id, $comment, $invoice_number, $work_order_number, $quote_number, $sale_type, $payments, $dinner_table, $sales_taxes);

        if ($saleIdNum === INSUFFICIENT_GIFTCARD_BALANCE) {
            $data['error'] = lang('Sales.insufficient_giftcard_balance');
        } elseif ($saleIdNum === INSUFFICIENT_REWARD_POINTS) {
            $data['error'] = lang('Sales.insufficient_reward_points');
        } elseif ($saleIdNum === NEW_ENTRY) {
            $data['error'] = lang('Sales.unsuccessfully_suspended_sale');
            $this->sale_lib->clear_all();
        } else {
            $data['success'] = lang('Sales.successfully_suspended_sale');
            $this->sale_lib->clear_all();
        }

        return $this->reload($data);
    }

    /**
     * List suspended sales
     * @return string
     */
    public function getSuspended(): string
    {
        $data = [];
        $customer_id = $this->sale_lib->get_customer();
        $data['suspended_sales'] = $this->sale->get_all_suspended($customer_id);
        return view('sales/suspended', $data);
    }

    /**
     * Unsuspended sales are now left in the tables and are only removed
     * when they are intentionally cancelled. Used in app/Views/sales/suspended.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postUnsuspend(): ResponseInterface|string
    {
        $sale_id = $this->request->getPost('suspended_sale_id', FILTER_SANITIZE_NUMBER_INT);
        $this->sale_lib->clear_all();

        if ($sale_id > 0) {
            $this->sale_lib->copy_entire_sale($sale_id);
        }

        // Set current register mode to reflect that of unsuspended order type
        $this->change_register_mode($this->sale_lib->get_sale_type());

        return $this->reload();
    }

    /**
     * Show Keyboard shortcut modal. Used in app/Views/sales/register.php
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getSalesKeyboardHelp(): string
    {
        return view('sales/help', [
            'keyboardShortcuts' => $this->sale_lib->getKeyShortcuts()
        ]);
    }

    /**
     * Check the validity of an invoice number. Used in app/Views/sales/form.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCheckInvoiceNumber(): ResponseInterface
    {
        $sale_id = $this->request->getPost('sale_id', FILTER_SANITIZE_NUMBER_INT);
        $invoice_number = $this->request->getPost('invoice_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $exists = !empty($invoice_number) && $this->sale->check_invoice_number_exists($invoice_number, $sale_id);
        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * @param array $cart
     * @return array
     */
    public function get_filtered(array $cart): array
    {
        $filtered_cart = [];
        foreach ($cart as $id => $item) {
            if ($item['print_option'] == PRINT_ALL) // Always include
            {
                $filtered_cart[$id] = $item;
            } elseif ($item['print_option'] == PRINT_PRICED && $item['price'] != 0)  // Include only if the price is not zero
            {
                $filtered_cart[$id] = $item;
            }
            // print_option 2 is never included
        }

        return $filtered_cart;
    }

    /**
     * Update the item number in the register. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemNumber(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $item_number = $this->request->getPost('item_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->item->update_item_number($item_id, $item_number);
        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);
        if ($x !== null) {
            $cart[$x]['item_number'] = $item_number;
        }
        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Change a given item name. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemName(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $name = $this->request->getPost('item_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->item->update_item_name($item_id, $name);

        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);

        if ($x !== null) {
            $cart[$x]['name'] = $name;
        }

        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Update the given item description.  Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemDescription(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $description = $this->request->getPost('item_description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->item->update_item_description($item_id, $description);

        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);

        if ($x !== null) {
            $cart[$x]['description'] = $description;
        }

        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * @param int $search_item_id
     * @param array $shopping_cart
     * @return int|string|null
     */
    public function search_cart_for_item_id(int $search_item_id, array $shopping_cart): int|string|null
    {
        foreach ($shopping_cart as $key => $val) {
            if ($val['item_id'] === $search_item_id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Browser print/Save-as-PDF uses the document title as the filename.
     * Prefer INV-{number} for invoices and SR-{id} for receipts.
     */
    private function buildSaleDocumentTitle(string $prefix, string $number): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9]+/', '-', trim($number)) ?? '';
        $cleaned = trim($cleaned, '-');
        $prefixUpper = strtoupper($prefix);

        if ($cleaned === '') {
            return $prefixUpper;
        }

        // Avoid INV-INV-0001 when the configured invoice format already includes INV
        if (stripos($cleaned, $prefixUpper . '-') === 0) {
            return strtoupper($cleaned);
        }
        if (strcasecmp($cleaned, $prefixUpper) === 0) {
            return $prefixUpper;
        }

        return $prefixUpper . '-' . $cleaned;
    }
}
