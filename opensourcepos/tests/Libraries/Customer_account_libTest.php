<?php

namespace Tests\Libraries;

use App\Libraries\Consolidated_invoice_lib;
use App\Libraries\Customer_account_lib;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use CodeIgniter\Config\Factories;
use RuntimeException;

/**
 * Customer account outstanding / allocation / CI invariants.
 */
class Customer_account_libTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;
    private Customer_account_lib $accountLib;
    private Consolidated_invoice_lib $invoiceLib;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            \CodeIgniter\Database\Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            \CodeIgniter\Database\Config::connect($this->DBGroup)->close();
            self::$doneBootstrap = true;
        }

        parent::setUp();

        $this->ensureVoidReturnsSchema();

        $ospos = new OSPOS();
        $ospos->settings = array_merge($ospos->settings ?? [], [
            'cash_rounding_code'         => '',
            'cash_decimals'              => 2,
            'currency_decimals'          => 2,
            'tax_decimals'               => 2,
            'quantity_decimals'          => 2,
            'payment_options_order'      => 'cashdebitcredit',
            'country_codes'              => 'us',
            'number_locale'              => 'en_US',
            'currency_symbol'            => '$',
            'currency_code'              => 'USD',
            'thousands_separator'        => ',',
            'tax_included'               => '0',
            'default_tax_1_name'         => '',
            'default_tax_1_rate'         => '0',
            'default_tax_2_name'         => '',
            'default_tax_2_rate'         => '0',
            'customer_sales_tax_support' => '0',
            'dateformat'                 => 'm/d/Y',
            'timeformat'                 => 'H:i:s',
        ]);
        Factories::injectMock('config', OSPOS::class, $ospos);

        helper(['locale', 'tabular']);
        $this->accountLib = new Customer_account_lib();
        $this->invoiceLib = new Consolidated_invoice_lib($this->accountLib);
    }

    /**
     * Ensure Phase A schema exists after TestDatabaseBootstrapSeeder + migrate.
     * PHPUnit migrate may not always apply the latest SQL script in this environment.
     */
    private function ensureVoidReturnsSchema(): void
    {
        static $applied = false;
        if ($applied) {
            return;
        }

        $db = Database::connect();
        $forge = \Config\Database::forge();

        $paymentFields = $db->getFieldNames('customer_account_payments');
        if (!in_array('status', $paymentFields, true)) {
            $forge->addColumn('customer_account_payments', [
                'status' => ['type' => 'TINYINT', 'constraint' => 4, 'null' => false, 'default' => 1],
            ]);
        }
        $paymentFields = $db->getFieldNames('customer_account_payments');
        if (!in_array('voided_at', $paymentFields, true)) {
            $forge->addColumn('customer_account_payments', [
                'voided_at' => ['type' => 'TIMESTAMP', 'null' => true],
            ]);
        }
        if (!in_array('voided_by', $db->getFieldNames('customer_account_payments'), true)) {
            $forge->addColumn('customer_account_payments', [
                'voided_by' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            ]);
        }
        if (!in_array('void_reason', $db->getFieldNames('customer_account_payments'), true)) {
            $forge->addColumn('customer_account_payments', [
                'void_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            ]);
        }
        if (!in_array('idempotency_key', $db->getFieldNames('customer_account_payments'), true)) {
            $forge->addColumn('customer_account_payments', [
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            ]);
        }

        $allocFields = $db->getFieldNames('customer_payment_allocations');
        if (!in_array('status', $allocFields, true)) {
            $forge->addColumn('customer_payment_allocations', [
                'status' => ['type' => 'TINYINT', 'constraint' => 4, 'null' => false, 'default' => 1],
            ]);
        }

        $saleFields = $db->getFieldNames('sales');
        if (!in_array('return_of_sale_id', $saleFields, true)) {
            $forge->addColumn('sales', [
                'return_of_sale_id' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            ]);
        }

        $script = '/app/app/Database/Migrations/sqlscripts/20260925120000_customer_account_void_returns.sql';
        if (is_file($script)) {
            $sql = file_get_contents($script);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                if (stripos($statement, 'CREATE TABLE') === false && stripos($statement, 'INSERT INTO') === false) {
                    continue;
                }
                @$db->simpleQuery($statement);
            }
        }

        if ($db->tableExists('customer_return_credits')
            && !in_array('settlement_mode', $db->getFieldNames('customer_return_credits'), true)
        ) {
            try {
                $forge->addColumn('customer_return_credits', [
                    'settlement_mode' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'outstanding'],
                ]);
            } catch (\Throwable $e) {
                // already present
            }
        }
        if ($db->tableExists('customer_credit_refunds')
            && !in_array('return_sale_id', $db->getFieldNames('customer_credit_refunds'), true)
        ) {
            try {
                $forge->addColumn('customer_credit_refunds', [
                    'return_sale_id' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
                ]);
            } catch (\Throwable $e) {
                // already present
            }
        }
        if ($db->tableExists('sales_items')
            && !in_array('source_line', $db->getFieldNames('sales_items'), true)
        ) {
            try {
                $forge->addColumn('sales_items', [
                    'source_line' => ['type' => 'INT', 'constraint' => 3, 'null' => true],
                ]);
            } catch (\Throwable $e) {
                // already present
            }
        }
        if ($db->tableExists('inventory')
            && !in_array('sale_id', $db->getFieldNames('inventory'), true)
        ) {
            try {
                $forge->addColumn('inventory', [
                    'sale_id' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
                ]);
            } catch (\Throwable $e) {
                // already present
            }
        }

        $applied = true;
    }

    public function testAccountPaymentOptionsExcludeDueAndGiftCard(): void
    {
        $options = $this->accountLib->getAccountPaymentOptions();
        $this->assertArrayNotHasKey(lang('Sales.due'), $options);
        $this->assertArrayNotHasKey('Due', $options);
        $this->assertArrayHasKey(lang('Sales.cash'), $options);
    }

    public function testRejectsDuePaymentType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->accountLib->recordPayment(1, 10.0, lang('Sales.due'), 1, null, null, null, null, [
            ['sale_id' => 1, 'amount' => 10.0],
        ]);
    }

    public function testSaleOutstandingCash700Due300(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.cash'), 700.0],
            [lang('Sales.due'), 300.0],
        ]);

        $outstanding = $this->accountLib->getSaleOutstanding($fixture['sale_id']);
        $this->assertEqualsWithDelta(300.0, $outstanding, 0.01);
    }

    public function testSaleOutstandingDebitFullyPaid(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.debit'), 1000.0],
        ]);

        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
    }

    public function testSaleOutstandingInvoiceUnderpayNoDue(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.debit'), 700.0],
        ]);

        $this->assertEqualsWithDelta(300.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
    }

    public function testAccountPaymentRequiresExplicitAllocation(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [
            [lang('Sales.due'), 100.0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            100.0,
            lang('Sales.cash'),
            $fixture['employee_id']
        );
    }

    public function testAccountPaymentAllocatesAndClearsDue(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.cash'), 700.0],
            [lang('Sales.due'), 300.0],
        ]);

        $result = $this->accountLib->recordPayment(
            $fixture['customer_id'],
            300.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 300.0]]
        );

        $this->assertGreaterThan(0, $result['payment_id']);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
    }

    public function testUnallocatedPaymentDoesNotReduceOtherSale(): void
    {
        $first = $this->createSaleWithPayments(8000.0, [
            [lang('Sales.due'), 8000.0],
        ]);
        $second = $this->createSaleWithPayments(800.0, [
            [lang('Sales.due'), 800.0],
        ], $first['customer_id']);

        $this->accountLib->recordPayment(
            $first['customer_id'],
            200.0,
            lang('Sales.cash'),
            $first['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $second['sale_id'], 'amount' => 200.0]]
        );

        $this->assertEqualsWithDelta(8000.0, $this->accountLib->getSaleOutstanding($first['sale_id']), 0.01);
        $this->assertEqualsWithDelta(600.0, $this->accountLib->getSaleOutstanding($second['sale_id']), 0.01);
        $this->assertEqualsWithDelta(8600.0, $this->accountLib->getCustomerOutstanding($first['customer_id']), 0.01);
    }

    public function testAllocatedPaymentReducesTargetSaleOnly(): void
    {
        $fixture = $this->createSaleWithPayments(8000.0, [
            [lang('Sales.due'), 8000.0],
        ]);

        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            200.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 200.0]]
        );

        $this->assertEqualsWithDelta(7800.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->assertEqualsWithDelta(7800.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);

        $history = $this->accountLib->getTransactionHistory($fixture['customer_id']);
        foreach ($history as $row) {
            $this->assertNotSame('due_placeholder', $row['type']);
            $this->assertNotSame('consolidated_invoice', $row['type']);
        }
        $this->assertEqualsWithDelta(7800.0, $history[array_key_last($history)]['balance'], 0.01);
    }

    public function testDebitAccountPaymentRequiresReference(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [
            [lang('Sales.due'), 100.0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            100.0,
            lang('Sales.debit'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 100.0]]
        );
    }

    public function testConsolidatedInvoiceDoesNotChangeOutstandingUntilPaid(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [
            [lang('Sales.due'), 500.0],
        ]);

        $before = $this->accountLib->getCustomerOutstanding($fixture['customer_id']);
        $ci = $this->invoiceLib->create(
            $fixture['customer_id'],
            [$fixture['sale_id']],
            $fixture['employee_id']
        );
        $after = $this->accountLib->getCustomerOutstanding($fixture['customer_id']);

        $this->assertEqualsWithDelta($before, $after, 0.01);
        $this->assertEqualsWithDelta(500.0, $ci['total_amount'], 0.01);
        $this->assertEqualsWithDelta(500.0, $this->invoiceLib->getBalance($ci['consolidated_invoice_id']), 0.01);

        $this->invoiceLib->pay(
            $ci['consolidated_invoice_id'],
            200.0,
            lang('Sales.cash'),
            $fixture['employee_id']
        );

        $this->assertEqualsWithDelta(300.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->assertEqualsWithDelta(300.0, $this->invoiceLib->getBalance($ci['consolidated_invoice_id']), 0.01);
        $this->assertEquals(CI_STATUS_PARTIALLY_PAID, $this->invoiceLib->refreshStatus($ci['consolidated_invoice_id']));
    }

    public function testConsolidatedInvoiceSeparatesPriorPaymentsReturnsAndCiPayments(): void
    {
        // Example: INVC-20 $500 unpaid + INVC-21 $250 with $160 prior payment and -$30 return.
        $sale20 = $this->createSaleWithPayments(500.0, [
            [lang('Sales.due'), 500.0],
        ]);
        $sale21 = $this->createSaleWithPayments(250.0, [
            [lang('Sales.due'), 250.0],
        ], $sale20['customer_id']);

        $this->accountLib->recordPayment(
            $sale21['customer_id'],
            160.0,
            lang('Sales.cash'),
            $sale21['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $sale21['sale_id'], 'amount' => 160.0]]
        );

        $return = $this->createReturnSale(30.0, $sale21['customer_id'], $sale21['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $sale21['employee_id'], 'outstanding');

        $this->assertEqualsWithDelta(500.0, $this->accountLib->getSaleOutstanding($sale20['sale_id']), 0.01);
        $this->assertEqualsWithDelta(60.0, $this->accountLib->getSaleOutstanding($sale21['sale_id']), 0.01);

        $outstandingBefore = $this->accountLib->getCustomerOutstanding($sale20['customer_id']);
        $ci = $this->invoiceLib->create(
            $sale20['customer_id'],
            [$sale20['sale_id'], $sale21['sale_id']],
            $sale20['employee_id']
        );
        $outstandingAfter = $this->accountLib->getCustomerOutstanding($sale20['customer_id']);

        // Creating the CI is grouping-only: no duplicate receivable.
        $this->assertEqualsWithDelta($outstandingBefore, $outstandingAfter, 0.01);
        $this->assertEqualsWithDelta(560.0, $outstandingAfter, 0.01);

        $financials = $this->invoiceLib->computeInvoiceFinancials($ci['consolidated_invoice_id']);
        $this->assertEqualsWithDelta(750.0, $financials['original_sales_total'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $financials['returns_total'], 0.01);
        $this->assertEqualsWithDelta(720.0, $financials['net_invoice_amount'], 0.01);
        $this->assertEqualsWithDelta(160.0, $financials['previously_paid_on_sales'], 0.01);
        $this->assertEqualsWithDelta(0.0, $financials['payments_applied_to_ci'], 0.01);
        $this->assertEqualsWithDelta(560.0, $financials['balance_due'], 0.01);

        $details = $this->invoiceLib->getInvoiceDetails($ci['consolidated_invoice_id']);
        $this->assertSame(CI_STATUS_PARTIALLY_PAID, (int) $details['invoice']['status']);
        $this->assertEqualsWithDelta(750.0, $details['summary']['original_sales_total'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $details['summary']['returns_total'], 0.01);
        $this->assertEqualsWithDelta(720.0, $details['summary']['net_invoice_amount'], 0.01);
        $this->assertEqualsWithDelta(160.0, $details['summary']['previously_paid_on_sales'], 0.01);
        $this->assertEqualsWithDelta(0.0, $details['summary']['payments_applied_to_ci'], 0.01);
        $this->assertEqualsWithDelta(560.0, $details['summary']['balance_due'], 0.01);
        $this->assertNotEmpty($details['returns']);

        $bySale = [];
        foreach ($details['sales'] as $row) {
            $bySale[(int) $row['sale_id']] = $row;
        }
        $this->assertEqualsWithDelta(500.0, $bySale[$sale20['sale_id']]['original_total'], 0.01);
        $this->assertEqualsWithDelta(0.0, $bySale[$sale20['sale_id']]['paid_on_sale'], 0.01);
        $this->assertEqualsWithDelta(0.0, $bySale[$sale20['sale_id']]['returns'], 0.01);
        $this->assertEqualsWithDelta(500.0, $bySale[$sale20['sale_id']]['current_balance'], 0.01);
        $this->assertEqualsWithDelta(250.0, $bySale[$sale21['sale_id']]['original_total'], 0.01);
        $this->assertEqualsWithDelta(160.0, $bySale[$sale21['sale_id']]['paid_on_sale'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $bySale[$sale21['sale_id']]['returns'], 0.01);
        $this->assertEqualsWithDelta(60.0, $bySale[$sale21['sale_id']]['current_balance'], 0.01);
    }

    public function testAccountViewFiltersDoNotChangeBalances(): void
    {
        $saleA = $this->createSaleWithPayments(500.0, [[lang('Sales.due'), 500.0]]);
        $saleB = $this->createSaleWithPayments(250.0, [[lang('Sales.due'), 250.0]], $saleA['customer_id']);
        $this->accountLib->recordPayment(
            $saleB['customer_id'],
            160.0,
            lang('Sales.cash'),
            $saleB['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $saleB['sale_id'], 'amount' => 160.0]]
        );

        $db = Database::connect();
        $db->table('sales')->where('sale_id', $saleA['sale_id'])->update(['invoice_number' => 'INVC-A']);
        $db->table('sales')->where('sale_id', $saleB['sale_id'])->update(['invoice_number' => 'INVC-B']);

        $all = $this->accountLib->getOutstandingSales($saleA['customer_id']);
        $filtered = $this->accountLib->getOutstandingSales($saleA['customer_id'], false, [
            'search' => 'INVC-B',
            'status' => 'partially_paid',
        ]);

        $this->assertCount(2, $all);
        $this->assertCount(1, $filtered);
        $this->assertSame($saleB['sale_id'], $filtered[0]['sale_id']);
        $this->assertEqualsWithDelta(90.0, $filtered[0]['outstanding'], 0.01);
        $this->assertEqualsWithDelta(250.0, $filtered[0]['total'], 0.01);
        $this->assertEqualsWithDelta(160.0, $filtered[0]['paid'], 0.01);

        $ledger = $this->accountLib->getLedgerPage($saleA['customer_id'], 1, 25, ['search' => (string) $saleB['sale_id']]);
        $this->assertSame(1, $ledger['total']);
        $this->assertEqualsWithDelta(90.0, $ledger['rows'][0]['balance'], 0.01);

        $paymentsOnly = $this->accountLib->getFinancialTransactions($saleA['customer_id'], 1, 25, ['type' => 'payment']);
        foreach ($paymentsOnly['rows'] as $row) {
            $this->assertContains($row['type'], ['pos_payment', 'account_payment']);
        }
    }

    public function testDirectPaymentRejectedForSaleOnActiveCi(): void
    {
        $fixture = $this->createSaleWithPayments(150.0, [
            [lang('Sales.due'), 150.0],
        ]);
        $ci = $this->invoiceLib->create(
            $fixture['customer_id'],
            [$fixture['sale_id']],
            $fixture['employee_id']
        );

        $this->expectException(\RuntimeException::class);
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            150.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 150.0]]
        );

        // Silence unused in static analysis if exception not thrown
        $this->assertGreaterThan(0, $ci['consolidated_invoice_id']);
    }

    public function testCancelConsolidatedInvoiceFreesSale(): void
    {
        $fixture = $this->createSaleWithPayments(250.0, [
            [lang('Sales.due'), 250.0],
        ]);

        $ci = $this->invoiceLib->create(
            $fixture['customer_id'],
            [$fixture['sale_id']],
            $fixture['employee_id']
        );

        $this->invoiceLib->cancel($ci['consolidated_invoice_id']);
        $this->assertFalse($this->accountLib->isSaleOnActiveConsolidatedInvoice($fixture['sale_id']));
        $this->assertEqualsWithDelta(250.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);

        $ci2 = $this->invoiceLib->create(
            $fixture['customer_id'],
            [$fixture['sale_id']],
            $fixture['employee_id']
        );
        $this->assertGreaterThan(0, $ci2['consolidated_invoice_id']);
    }

    public function testOverpaymentRejected(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [
            [lang('Sales.due'), 100.0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            150.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 150.0]]
        );
    }

    public function testDeleteSaleBlockedAfterAllocation(): void
    {
        $fixture = $this->createSaleWithPayments(8000.0, [
            [lang('Sales.due'), 8000.0],
        ]);

        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            2000.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 2000.0]]
        );

        $this->assertTrue($this->accountLib->saleHasFinancialReferences($fixture['sale_id']));
        $saleModel = model(Sale::class);
        $this->assertFalse($saleModel->delete($fixture['sale_id'], false, false, $fixture['employee_id']));
        $this->assertEqualsWithDelta(6000.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
    }

    public function testMultiSaleCiPartialPayment(): void
    {
        $a = $this->createSaleWithPayments(100.0, [[lang('Sales.due'), 100.0]]);
        $b = $this->createSaleWithPayments(200.0, [[lang('Sales.due'), 200.0]], $a['customer_id']);
        $c = $this->createSaleWithPayments(300.0, [[lang('Sales.due'), 300.0]], $a['customer_id']);

        $ci = $this->invoiceLib->create(
            $a['customer_id'],
            [$a['sale_id'], $b['sale_id'], $c['sale_id']],
            $a['employee_id']
        );

        $this->invoiceLib->pay($ci['consolidated_invoice_id'], 250.0, lang('Sales.cash'), $a['employee_id']);

        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($a['sale_id']), 0.01);
        $this->assertEqualsWithDelta(50.0, $this->accountLib->getSaleOutstanding($b['sale_id']), 0.01);
        $this->assertEqualsWithDelta(300.0, $this->accountLib->getSaleOutstanding($c['sale_id']), 0.01);
        $this->assertEqualsWithDelta(350.0, $this->invoiceLib->getBalance($ci['consolidated_invoice_id']), 0.01);
        $this->assertEqualsWithDelta(350.0, $this->accountLib->getCustomerOutstanding($a['customer_id']), 0.01);
    }

    public function testFinancialHistoryPaginationAndNoDueRows(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [
            [lang('Sales.due'), 100.0],
        ]);

        $page = $this->accountLib->getFinancialTransactions($fixture['customer_id'], 1, 25);
        $this->assertSame(1, $page['page']);
        $this->assertGreaterThanOrEqual(1, $page['total']);
        foreach ($page['rows'] as $row) {
            $this->assertNotSame('due_placeholder', $row['type']);
        }
    }

    public function testVoidPaymentRestoresOutstanding(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.due'), 1000.0],
        ]);

        $payment = $this->accountLib->recordPayment(
            $fixture['customer_id'],
            400.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 400.0]]
        );

        $this->assertEqualsWithDelta(600.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->accountLib->voidPayment($payment['payment_id'], $fixture['employee_id'], 'test');
        $this->assertEqualsWithDelta(1000.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $summary = $this->accountLib->getSaleFinancialSummary($fixture['sale_id']);
        $this->assertEqualsWithDelta(0.0, $summary['payments_applied'], 0.01);
        $this->assertSame(SALE_PAY_STATUS_UNPAID, $summary['status']);
        $this->assertEqualsWithDelta(1000.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
    }

    public function testVoidingOneOfMultiplePaymentsKeepsOtherPaymentActive(): void
    {
        $fixture = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.due'), 4400.0],
        ]);

        $first = $this->accountLib->recordPayment(
            $fixture['customer_id'],
            1000.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 1000.0]]
        );
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            500.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 500.0]]
        );

        $this->accountLib->voidPayment($first['payment_id'], $fixture['employee_id'], 'test');

        $summary = $this->accountLib->getSaleFinancialSummary($fixture['sale_id']);
        $this->assertEqualsWithDelta(500.0, $summary['payments_applied'], 0.01);
        $this->assertEqualsWithDelta(3900.0, $summary['balance'], 0.01);
        $this->assertSame(SALE_PAY_STATUS_PARTIAL, $summary['status']);
    }

    public function testVoidingConsolidatedInvoicePaymentRefreshesInvoiceAndActivity(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.due'), 1000.0],
        ]);
        $ci = $this->invoiceLib->create(
            $fixture['customer_id'],
            [$fixture['sale_id']],
            $fixture['employee_id']
        );
        $payment = $this->invoiceLib->pay(
            $ci['consolidated_invoice_id'],
            400.0,
            lang('Sales.cash'),
            $fixture['employee_id']
        );

        $this->assertEqualsWithDelta(600.0, $this->invoiceLib->getBalance($ci['consolidated_invoice_id']), 0.01);
        $this->assertEquals(CI_STATUS_PARTIALLY_PAID, $this->invoiceLib->refreshStatus($ci['consolidated_invoice_id']));

        $this->accountLib->voidPayment($payment['payment_id'], $fixture['employee_id'], 'test');

        $this->assertEqualsWithDelta(1000.0, $this->invoiceLib->getBalance($ci['consolidated_invoice_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->invoiceLib->getPaidAmount($ci['consolidated_invoice_id']), 0.01);
        $invoice = $this->invoiceLib->getInvoiceDetails($ci['consolidated_invoice_id']);
        $this->assertSame(CI_STATUS_OPEN, (int) $invoice['invoice']['status']);
        $this->assertEqualsWithDelta(1000.0, $invoice['balance'], 0.01);

        $activity = $this->accountLib->getAccountActivity($fixture['customer_id'], 1, 50);
        $voided = array_filter(
            $activity['rows'],
            static fn (array $row): bool => ($row['type'] ?? '') === 'payment'
                && str_contains((string) ($row['reference'] ?? ''), '[voided]')
        );
        $this->assertCount(1, $voided);
    }

    public function testReallocatePaymentAndIdempotentRecord(): void
    {
        $a = $this->createSaleWithPayments(500.0, [[lang('Sales.due'), 500.0]]);
        $b = $this->createSaleWithPayments(500.0, [[lang('Sales.due'), 500.0]], $a['customer_id']);

        $first = $this->accountLib->recordPayment(
            $a['customer_id'],
            300.0,
            lang('Sales.cash'),
            $a['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $a['sale_id'], 'amount' => 300.0]],
            'idem-realloc-1'
        );
        $second = $this->accountLib->recordPayment(
            $a['customer_id'],
            300.0,
            lang('Sales.cash'),
            $a['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $a['sale_id'], 'amount' => 300.0]],
            'idem-realloc-1'
        );
        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertTrue($second['idempotent'] ?? false);

        $this->accountLib->reallocatePayment($first['payment_id'], [
            ['sale_id' => $b['sale_id'], 'amount' => 300.0],
        ], $a['employee_id']);

        $this->assertEqualsWithDelta(500.0, $this->accountLib->getSaleOutstanding($a['sale_id']), 0.01);
        $this->assertEqualsWithDelta(200.0, $this->accountLib->getSaleOutstanding($b['sale_id']), 0.01);
    }

    public function testReturnCreditReducesOutstandingAndCreatesRemainder(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.cash'), 200.0],
            [lang('Sales.due'), 800.0],
        ]);

        $return = $this->createReturnSale(300.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id']);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(300.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(500.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);

        $summary = $this->accountLib->getSaleFinancialSummary($fixture['sale_id']);
        $this->assertEqualsWithDelta(300.0, $summary['credits_returns'], 0.01);
        $this->assertSame(SALE_PAY_STATUS_PARTIAL, $summary['status']);
    }

    public function testOutstandingReturnOnFullyPaidSaleBecomesCustomerCredit(): void
    {
        $fixture = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.cash'), 4400.0],
        ]);

        $return = $this->createReturnSale(1100.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(0.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(1100.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(1100.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
    }

    public function testOutstandingReturnExceedingBalanceCreatesCreditRemainder(): void
    {
        $fixture = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.cash'), 3500.0],
            [lang('Sales.due'), 900.0],
        ]);

        $this->assertEqualsWithDelta(900.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);

        $return = $this->createReturnSale(1100.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(900.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(200.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->assertEqualsWithDelta(200.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
    }

    public function testOutstandingReturnUsesCurrentSaleBalance(): void
    {
        $fixture = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.cash'), 2200.0],
        ]);

        $this->accountLib->assertReturnOutstandingAmount($fixture['sale_id'], 1100.0);
        $return = $this->createReturnSale(1100.0, $fixture['customer_id'], $fixture['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $this->assertEqualsWithDelta(1100.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
    }

    public function testReturnAfterFullPayCreatesCustomerCredit(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [
            [lang('Sales.cash'), 500.0],
        ]);

        $return = $this->createReturnSale(100.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'credit');

        $this->assertEqualsWithDelta(0.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(100.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
    }

    public function testCreditReturnDoesNotReduceOriginalOutstanding(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.cash'), 600.0],
            [lang('Sales.due'), 400.0],
        ]);

        $return = $this->createReturnSale(700.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted(
            $return['sale_id'],
            $fixture['employee_id'],
            'credit'
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(0.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(700.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(400.0, $this->accountLib->getSaleOutstanding($fixture['sale_id']), 0.01);
        $this->assertEqualsWithDelta(700.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
    }

    public function testApplyAndRefundCustomerCredit(): void
    {
        $paid = $this->createSaleWithPayments(200.0, [[lang('Sales.cash'), 200.0]]);
        $open = $this->createSaleWithPayments(150.0, [[lang('Sales.due'), 150.0]], $paid['customer_id']);

        $return = $this->createReturnSale(80.0, $paid['customer_id'], $paid['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $paid['employee_id'], 'credit');
        $this->assertEqualsWithDelta(80.0, $this->accountLib->getCustomerCreditBalance($paid['customer_id']), 0.01);

        $this->accountLib->applyCustomerCredit($paid['customer_id'], $open['sale_id'], 50.0, $paid['employee_id'], null, 'apply-1');
        $this->assertEqualsWithDelta(30.0, $this->accountLib->getCustomerCreditBalance($paid['customer_id']), 0.01);
        $this->assertEqualsWithDelta(100.0, $this->accountLib->getSaleOutstanding($open['sale_id']), 0.01);

        $this->accountLib->refundCustomerCredit($paid['customer_id'], 30.0, lang('Sales.cash'), $paid['employee_id'], null, null, 'refund-1');
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerCreditBalance($paid['customer_id']), 0.01);
    }

    public function testHierarchicalLedgerNoDuplicatePosCredits(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [
            [lang('Sales.cash'), 400.0],
            [lang('Sales.due'), 600.0],
        ]);
        $this->accountLib->recordPayment(
            $fixture['customer_id'],
            200.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $fixture['sale_id'], 'amount' => 200.0]]
        );

        $ledger = $this->accountLib->getLedgerPage($fixture['customer_id'], 1, 25);
        $this->assertGreaterThanOrEqual(1, count($ledger['rows']));
        $parent = $ledger['rows'][0];
        $posChildren = array_filter($parent['children'], static fn (array $c): bool => ($c['source'] ?? '') === 'pos');
        $acctChildren = array_filter($parent['children'], static fn (array $c): bool => ($c['source'] ?? '') === 'account');
        $this->assertCount(1, $posChildren);
        $this->assertCount(1, $acctChildren);

        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $posEvents = array_filter($statement['rows'], static fn (array $r): bool => ($r['type'] ?? '') === 'pos_payment');
        $acctEvents = array_filter($statement['rows'], static fn (array $r): bool => ($r['type'] ?? '') === 'account_payment');
        $this->assertCount(1, $posEvents);
        $this->assertCount(1, $acctEvents);
        $this->assertEqualsWithDelta(400.0, $statement['ending_balance'], 0.01);
        $this->assertEqualsWithDelta(400.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
    }

    public function testArmaniStyleReconciliationNoDoubleCount(): void
    {
        // Sales $7700, POS $3700, CA $2900 → outstanding $1100
        $s1 = $this->createSaleWithPayments(2400.0, [[lang('Sales.cash'), 2400.0]]);
        $s2 = $this->createSaleWithPayments(300.0, [[lang('Sales.cash'), 300.0]], $s1['customer_id']);
        $s3 = $this->createSaleWithPayments(1000.0, [[lang('Sales.cash'), 1000.0]], $s1['customer_id']);
        $s4 = $this->createSaleWithPayments(4000.0, [[lang('Sales.due'), 4000.0]], $s1['customer_id']);

        $this->accountLib->recordPayment(
            $s1['customer_id'],
            2900.0,
            lang('Sales.cash'),
            $s1['employee_id'],
            null,
            null,
            null,
            null,
            [['sale_id' => $s4['sale_id'], 'amount' => 2900.0]]
        );

        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($s1['sale_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($s2['sale_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($s3['sale_id']), 0.01);
        $this->assertEqualsWithDelta(1100.0, $this->accountLib->getSaleOutstanding($s4['sale_id']), 0.01);

        $outstanding = $this->accountLib->getCustomerOutstanding($s1['customer_id']);
        $this->assertEqualsWithDelta(1100.0, $outstanding, 0.01);

        $statement = $this->accountLib->getStatementPage($s1['customer_id'], 1, PHP_INT_MAX);
        $this->assertEqualsWithDelta(1100.0, $statement['ending_balance'], 0.01);

        // Statement ending receivable matches Balance report for this customer (no double-counted POS credits)
        $posCredits = array_filter($statement['rows'], static fn (array $r): bool => ($r['type'] ?? '') === 'pos_payment');
        $this->assertCount(3, $posCredits);
        $acctCredits = array_filter($statement['rows'], static fn (array $r): bool => ($r['type'] ?? '') === 'account_payment');
        $this->assertCount(1, $acctCredits);

        $report = (new \App\Models\Reports\Account_receivables($this->accountLib))->getAccountStatement($s1['customer_id']);
        $this->assertEqualsWithDelta(1100.0, $report['summary_data']['total'], 0.01);
        $this->assertEqualsWithDelta(1100.0, $report['summary_data']['ending_balance'], 0.01);
    }

    public function testDueExcludedFromPaymentsApplied(): void
    {
        $fixture = $this->createSaleWithPayments(1200.0, [
            [lang('Sales.cash'), 1000.0],
            [lang('Sales.due'), 200.0],
        ]);
        $summary = $this->accountLib->getSaleFinancialSummary($fixture['sale_id']);
        $this->assertEqualsWithDelta(1000.0, $summary['payments_applied'], 0.01);
        $this->assertEqualsWithDelta(200.0, $summary['balance'], 0.01);
        $this->assertEqualsWithDelta(1200.0, $summary['amount_tendered'], 0.01);
        $this->assertSame(SALE_PAY_STATUS_PARTIAL, $summary['status']);
    }

    public function testCashRefundDoesNotIncreaseReceivableBalance(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [[lang('Sales.cash'), 1000.0]]);
        $return = $this->createReturnSale(200.0, $fixture['customer_id'], $fixture['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'credit');
        $this->assertEqualsWithDelta(200.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);

        $this->accountLib->refundCustomerCredit(
            $fixture['customer_id'],
            200.0,
            lang('Sales.cash'),
            $fixture['employee_id'],
            null,
            null,
            'refund-ar-safe'
        );

        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);

        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $this->assertEqualsWithDelta(0.0, $statement['ending_balance'], 0.01);

        $refundRows = array_filter(
            $statement['rows'],
            static fn (array $r): bool => ($r['type'] ?? '') === 'credit_refund'
        );
        $this->assertCount(1, $refundRows);
        $refundRow = array_values($refundRows)[0];
        $this->assertEqualsWithDelta(0.0, $refundRow['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $refundRow['credit'], 0.01);
    }

    public function testApplyCreditCapsToOutstandingAndAvailable(): void
    {
        $paid = $this->createSaleWithPayments(3700.0, [[lang('Sales.cash'), 3700.0]]);
        $open = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.cash'), 4000.0],
            [lang('Sales.due'), 400.0],
        ], $paid['customer_id']);

        $return = $this->createReturnSale(3700.0, $paid['customer_id'], $paid['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $paid['employee_id'], 'credit');
        $this->assertEqualsWithDelta(3700.0, $this->accountLib->getCustomerCreditBalance($paid['customer_id']), 0.01);
        $this->assertEqualsWithDelta(400.0, $this->accountLib->getSaleOutstanding($open['sale_id']), 0.01);

        $this->expectException(RuntimeException::class);
        $this->accountLib->applyCustomerCredit($paid['customer_id'], $open['sale_id'], 500.0, $paid['employee_id']);
    }

    public function testApplyCreditExactOutstandingScenario13(): void
    {
        $paid = $this->createSaleWithPayments(3700.0, [[lang('Sales.cash'), 3700.0]]);
        $open = $this->createSaleWithPayments(4400.0, [
            [lang('Sales.cash'), 4000.0],
            [lang('Sales.due'), 400.0],
        ], $paid['customer_id']);

        $return = $this->createReturnSale(3700.0, $paid['customer_id'], $paid['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $paid['employee_id'], 'credit');

        $this->accountLib->applyCustomerCredit($paid['customer_id'], $open['sale_id'], 400.0, $paid['employee_id'], null, 'apply-400');
        $this->assertEqualsWithDelta(3300.0, $this->accountLib->getCustomerCreditBalance($paid['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getSaleOutstanding($open['sale_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($paid['customer_id']), 0.01);
    }

    public function testPartialReturnsCannotExceedOriginalQuantity(): void
    {
        $db = Database::connect();
        $fixture = $this->createSaleWithQty(10, 100.0);
        $itemId = (int) $db->table('sales_items')->where('sale_id', $fixture['sale_id'])->get()->getRowArray()['item_id'];

        $r1 = $this->createReturnSaleForItem($fixture['sale_id'], $fixture['customer_id'], $itemId, 3, 100.0);
        $this->accountLib->onReturnSaleCompleted($r1['sale_id'], $fixture['employee_id'], 'credit');

        $r2 = $this->createReturnSaleForItem($fixture['sale_id'], $fixture['customer_id'], $itemId, 4, 100.0);
        $this->accountLib->onReturnSaleCompleted($r2['sale_id'], $fixture['employee_id'], 'credit');

        $r3 = $this->createReturnSaleForItem($fixture['sale_id'], $fixture['customer_id'], $itemId, 3, 100.0);
        $this->accountLib->onReturnSaleCompleted($r3['sale_id'], $fixture['employee_id'], 'credit');

        $returnable = $this->accountLib->getSaleReturnableItems($fixture['sale_id']);
        $this->assertEqualsWithDelta(0.0, $returnable[$itemId]['remaining'], 0.01);

        $r4 = $this->createReturnSaleForItem($fixture['sale_id'], $fixture['customer_id'], $itemId, 1, 100.0);
        $this->expectException(RuntimeException::class);
        $this->accountLib->onReturnSaleCompleted($r4['sale_id'], $fixture['employee_id'], 'credit');
    }

    public function testAccountActivityIncludesSalesPaymentsReturns(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [[lang('Sales.cash'), 100.0]]);
        $activity = $this->accountLib->getAccountActivity($fixture['customer_id'], 1, 50);
        $this->assertGreaterThanOrEqual(1, $activity['total']);
        $types = array_column($activity['rows'], 'type');
        $this->assertContains('sale', $types);
    }

    public function testCashSettlementPostsLedgerWithoutIncreasingReceivable(): void
    {
        $fixture = $this->createSaleWithPayments(1000.0, [[lang('Sales.cash'), 1000.0]]);
        $return = $this->createReturnSale(200.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'cash');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('refund_id', $result);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);

        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $this->assertEqualsWithDelta(0.0, $statement['ending_balance'], 0.01);
        $cashRows = array_filter($statement['rows'], static fn (array $r): bool => ($r['type'] ?? '') === 'return_cash');
        $this->assertCount(1, $cashRows);
        $row = array_values($cashRows)[0];
        $this->assertEqualsWithDelta(0.0, $row['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $row['credit'], 0.01);
    }

    public function testAlexMultipleReturnsAllAppearInStatement(): void
    {
        // Sale $250, pay $50, returns $30 outstanding + $100 cash + $60 + $60 outstanding
        $fixture = $this->createSaleWithPayments(250.0, [
            [lang('Sales.cash'), 50.0],
            [lang('Sales.due'), 200.0],
        ]);
        $cid = $fixture['customer_id'];
        $saleId = $fixture['sale_id'];
        $emp = $fixture['employee_id'];

        $r2 = $this->createReturnSale(30.0, $cid, $saleId);
        $this->accountLib->onReturnSaleCompleted($r2['sale_id'], $emp, 'outstanding');

        $r3 = $this->createReturnSale(50.0, $cid, $saleId); // cash capped to eligible paid remaining ($50-$0 after? paid 50, no prior cash)
        // Eligible after no prior cash refunds = 50. Use 50 cash.
        $this->accountLib->onReturnSaleCompleted($r3['sale_id'], $emp, 'cash');

        $r4 = $this->createReturnSale(60.0, $cid, $saleId);
        $this->accountLib->onReturnSaleCompleted($r4['sale_id'], $emp, 'outstanding');

        $r5 = $this->createReturnSale(60.0, $cid, $saleId);
        $this->accountLib->onReturnSaleCompleted($r5['sale_id'], $emp, 'outstanding');

        $statement = $this->accountLib->getStatementPage($cid, 1, PHP_INT_MAX);
        $types = array_column($statement['rows'], 'type');
        $this->assertContains('return_credit', $types);
        $this->assertContains('return_cash', $types);

        // 250 - 50 - 30 - 60 - 60 = 50 remaining outstanding (cash 50 did not reduce AR)
        $this->assertEqualsWithDelta(50.0, $this->accountLib->getCustomerOutstanding($cid), 0.01);
        $this->assertEqualsWithDelta(50.0, $statement['ending_balance'], 0.01);
        $this->assertGreaterThan(0, count(array_filter(
            $statement['rows'],
            static fn (array $r): bool => str_starts_with((string) ($r['reference'] ?? ''), 'Return #')
        )));
    }

    public function testSaleHasNoReturnableQuantityAfterFullReturn(): void
    {
        $db = Database::connect();
        $fixture = $this->createSaleWithQty(5, 20.0);
        $itemId = (int) $db->table('sales_items')->where('sale_id', $fixture['sale_id'])->get()->getRowArray()['item_id'];
        $this->assertTrue($this->accountLib->saleHasReturnableQuantity($fixture['sale_id']));

        $r1 = $this->createReturnSaleForItem($fixture['sale_id'], $fixture['customer_id'], $itemId, 5, 20.0);
        $this->accountLib->onReturnSaleCompleted($r1['sale_id'], $fixture['employee_id'], 'credit');
        $this->assertFalse($this->accountLib->saleHasReturnableQuantity($fixture['sale_id']));
    }

    public function testReconcileCustomerDetectsConsistentAlexShape(): void
    {
        $fixture = $this->createSaleWithPayments(100.0, [[lang('Sales.cash'), 40.0], [lang('Sales.due'), 60.0]]);
        $return = $this->createReturnSale(20.0, $fixture['customer_id'], $fixture['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $report = $this->accountLib->reconcileCustomer($fixture['customer_id']);
        $this->assertEqualsWithDelta(40.0, $report['outstanding'], 0.01);
        $this->assertEqualsWithDelta(40.0, $report['statement_end'], 0.01);
        $mismatch = array_filter($report['issues'], static fn (array $i): bool => ($i['type'] ?? '') === 'outstanding_vs_statement');
        $this->assertCount(0, $mismatch);
    }

    public function testPaidSaleCashRefundVisibleWithoutReceivable(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [[lang('Sales.cash'), 500.0]]);
        $return = $this->createReturnSale(30.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'cash');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('refund_id', $result);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(30.0, $this->accountLib->getSaleCashRefunded($fixture['sale_id']), 0.01);

        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $cashRows = array_values(array_filter(
            $statement['rows'],
            static fn (array $r): bool => ($r['type'] ?? '') === 'return_cash'
        ));
        $this->assertCount(1, $cashRows);
        $this->assertStringContainsString('Return #' . $return['sale_id'], $cashRows[0]['reference']);
        $this->assertStringContainsString('Sale #' . $fixture['sale_id'], $cashRows[0]['reference']);
        $this->assertEqualsWithDelta(0.0, $cashRows[0]['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $cashRows[0]['credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $statement['ending_balance'], 0.01);

        $ledger = $this->accountLib->getLedgerPage($fixture['customer_id'], 1, 50);
        $saleRow = null;
        foreach ($ledger['rows'] as $row) {
            if (($row['sale_id'] ?? null) === $fixture['sale_id']) {
                $saleRow = $row;
                break;
            }
        }
        $this->assertNotNull($saleRow);
        $this->assertEqualsWithDelta(0.0, $saleRow['credits'], 0.01);
        $this->assertEqualsWithDelta(0.0, $saleRow['balance'], 0.01);
        $childRefs = array_column($saleRow['children'], 'reference');
        $this->assertTrue(
            (bool) array_filter($childRefs, static fn ($r) => str_contains((string) $r, 'cash refund')),
            'Ledger children should include cash refund return'
        );
    }

    public function testPaidSaleCustomerCreditReturnVisibleWithoutDuplicateAr(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [[lang('Sales.cash'), 500.0]]);
        $return = $this->createReturnSale(50.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'credit');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(0.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(50.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(50.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);

        $summary = $this->accountLib->getSaleFinancialSummary($fixture['sale_id']);
        $this->assertEqualsWithDelta(0.0, $summary['credits_returns'], 0.01);
        $this->assertEqualsWithDelta(0.0, $summary['balance'], 0.01);

        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $creditRows = array_values(array_filter(
            $statement['rows'],
            static fn (array $r): bool => ($r['type'] ?? '') === 'return_credit_issued'
        ));
        $this->assertCount(1, $creditRows);
        $this->assertEqualsWithDelta(0.0, $creditRows[0]['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $creditRows[0]['credit'], 0.01);
    }

    public function testPartialUnpaidSaleReturnToOutstanding(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [
            [lang('Sales.cash'), 300.0],
            [lang('Sales.due'), 200.0],
        ]);
        $return = $this->createReturnSale(50.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $this->assertEqualsWithDelta(50.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(150.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
    }

    public function testPartialUnpaidSaleReturnGreaterThanOutstandingCreatesCredit(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [
            [lang('Sales.cash'), 450.0],
            [lang('Sales.due'), 50.0],
        ]);
        $return = $this->createReturnSale(80.0, $fixture['customer_id'], $fixture['sale_id']);
        $result = $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'outstanding');

        $this->assertEqualsWithDelta(50.0, $result['allocated'], 0.01);
        $this->assertEqualsWithDelta(30.0, $result['credit_remainder'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $this->assertEqualsWithDelta(30.0, $this->accountLib->getCustomerCreditBalance($fixture['customer_id']), 0.01);
    }

    public function testCashRefundMustNotCreateReceivable(): void
    {
        $fixture = $this->createSaleWithPayments(500.0, [[lang('Sales.cash'), 500.0]]);
        $return = $this->createReturnSale(30.0, $fixture['customer_id'], $fixture['sale_id']);
        $this->accountLib->onReturnSaleCompleted($return['sale_id'], $fixture['employee_id'], 'cash');

        $this->assertEqualsWithDelta(0.0, $this->accountLib->getCustomerOutstanding($fixture['customer_id']), 0.01);
        $statement = $this->accountLib->getStatementPage($fixture['customer_id'], 1, PHP_INT_MAX);
        $this->assertEqualsWithDelta(0.0, $statement['ending_balance'], 0.01);
        foreach ($statement['rows'] as $row) {
            if (($row['type'] ?? '') === 'return_cash') {
                $this->assertEqualsWithDelta(0.0, $row['debit'], 0.01);
                $this->assertEqualsWithDelta(0.0, $row['credit'], 0.01);
            }
        }
    }

    /**
     * @return array{sale_id:int, customer_id:int, employee_id:int}
     */
    private function createSaleWithQty(float $qty, float $unitPrice): array
    {
        $db = Database::connect();
        $unique = uniqid('qty', true);
        $db->table('people')->insert([
            'first_name' => 'Qty', 'last_name' => $unique, 'gender' => null, 'phone_number' => '',
            'email' => $unique . '@test.com', 'address_1' => '', 'address_2' => '', 'city' => '',
            'state' => '', 'zip' => '', 'country' => '', 'comments' => '',
        ]);
        $customerId = (int) $db->insertID();
        $db->table('customers')->insert([
            'person_id' => $customerId, 'company_name' => null, 'account_number' => null, 'taxable' => 1,
            'tax_id' => '', 'sales_tax_code_id' => null, 'deleted' => 0, 'date' => date('Y-m-d H:i:s'),
            'employee_id' => 1, 'discount' => 0, 'discount_type' => 0,
        ]);
        $db->table('items')->insert([
            'name' => 'Qty Item ' . $unique, 'category' => 'Test', 'description' => '',
            'cost_price' => 0, 'unit_price' => $unitPrice, 'item_number' => 'Q-' . substr(md5($unique), 0, 8),
        ]);
        $itemId = (int) $db->insertID();
        $db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => 1, 'quantity' => 1000]);
        $db->table('sales')->insert([
            'sale_time' => date('Y-m-d H:i:s'), 'customer_id' => $customerId, 'employee_id' => 1,
            'comment' => 'qty sale', 'invoice_number' => null, 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS,
        ]);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert([
            'sale_id' => $saleId, 'item_id' => $itemId, 'description' => '', 'serialnumber' => '',
            'line' => 0, 'quantity_purchased' => $qty, 'item_cost_price' => 0, 'item_unit_price' => $unitPrice,
            'discount' => 0, 'discount_type' => 0, 'item_location' => 1,
        ]);
        $db->table('sales_payments')->insert([
            'sale_id' => $saleId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $qty * $unitPrice,
            'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => 1, 'payment_time' => date('Y-m-d H:i:s'),
            'reference_code' => '',
        ]);

        return ['sale_id' => $saleId, 'customer_id' => $customerId, 'employee_id' => 1];
    }

    /**
     * @return array{sale_id:int, customer_id:int, employee_id:int}
     */
    private function createReturnSaleForItem(int $originalSaleId, int $customerId, int $itemId, float $qty, float $unitPrice): array
    {
        $db = Database::connect();
        $db->table('sales')->insert([
            'sale_time' => date('Y-m-d H:i:s'), 'customer_id' => $customerId, 'employee_id' => 1,
            'comment' => 'partial return', 'invoice_number' => null, 'sale_status' => COMPLETED,
            'sale_type' => SALE_TYPE_RETURN, 'return_of_sale_id' => $originalSaleId,
        ]);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert([
            'sale_id' => $saleId, 'item_id' => $itemId, 'description' => '', 'serialnumber' => '',
            'line' => 0, 'quantity_purchased' => -1 * $qty, 'item_cost_price' => 0, 'item_unit_price' => $unitPrice,
            'discount' => 0, 'discount_type' => 0, 'item_location' => 1,
        ]);

        return ['sale_id' => $saleId, 'customer_id' => $customerId, 'employee_id' => 1];
    }

    /**
     * @return array{sale_id:int, customer_id:int, employee_id:int}
     */
    private function createReturnSale(float $returnAmount, int $customerId, ?int $originalSaleId = null): array
    {
        $db = Database::connect();
        $unique = uniqid('ret', true);

        // Prefer returning the same item as the original sale so quantity rules can apply.
        $itemId = null;
        $unitPrice = $returnAmount;
        if ($originalSaleId !== null) {
            $origItem = $db->table('sales_items')->where('sale_id', $originalSaleId)->get()->getRowArray();
            if ($origItem !== null) {
                $itemId = (int) $origItem['item_id'];
                $unitPrice = (float) $origItem['item_unit_price'];
            }
        }

        if ($itemId === null) {
            $db->table('items')->insert([
                'name'        => 'Return Item ' . $unique,
                'category'    => 'Test',
                'description' => '',
                'cost_price'  => 0,
                'unit_price'  => $returnAmount,
                'item_number' => 'RT-' . substr(md5($unique), 0, 8),
            ]);
            $itemId = (int) $db->insertID();
            $db->table('item_quantities')->insert([
                'item_id'     => $itemId,
                'location_id' => 1,
                'quantity'    => 100,
            ]);
            $qty = -1;
        } else {
            $qty = $unitPrice > 0 ? -1 * ($returnAmount / $unitPrice) : -1;
        }

        $db->table('sales')->insert([
            'sale_time'         => date('Y-m-d H:i:s'),
            'customer_id'       => $customerId,
            'employee_id'       => 1,
            'comment'           => 'Return test',
            'invoice_number'    => null,
            'sale_status'       => COMPLETED,
            'sale_type'         => SALE_TYPE_RETURN,
            'return_of_sale_id' => $originalSaleId,
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $itemId,
            'description'        => '',
            'serialnumber'       => '',
            'line'               => 0,
            'quantity_purchased' => $qty,
            'item_cost_price'    => 0,
            'item_unit_price'    => $unitPrice,
            'discount'           => 0,
            'discount_type'      => 0,
            'item_location'      => 1,
        ]);

        return [
            'sale_id'     => $saleId,
            'customer_id' => $customerId,
            'employee_id' => 1,
        ];
    }

    /**
     * @param list<array{0:string,1:float}> $payments
     * @return array{sale_id:int, customer_id:int, employee_id:int}
     */
    private function createSaleWithPayments(float $saleTotal, array $payments, ?int $existingCustomerId = null): array
    {
        $db = Database::connect();
        $unique = uniqid('acct', true);

        if ($existingCustomerId !== null) {
            $customerId = $existingCustomerId;
        } else {
            $db->table('people')->insert([
                'first_name'   => 'Acct',
                'last_name'    => $unique,
                'gender'       => null,
                'phone_number' => '',
                'email'        => $unique . '@test.com',
                'address_1'    => '',
                'address_2'    => '',
                'city'         => '',
                'state'        => '',
                'zip'          => '',
                'country'      => '',
                'comments'     => '',
            ]);
            $customerId = (int) $db->insertID();

            $db->table('customers')->insert([
                'person_id'         => $customerId,
                'company_name'      => null,
                'account_number'    => null,
                'taxable'           => 1,
                'tax_id'            => '',
                'sales_tax_code_id' => null,
                'deleted'           => 0,
                'date'              => date('Y-m-d H:i:s'),
                'employee_id'       => 1,
                'discount'          => 0,
                'discount_type'     => 0,
            ]);
        }

        $db->table('items')->insert([
            'name'        => 'AR Item ' . $unique,
            'category'    => 'Test',
            'description' => '',
            'cost_price'  => 0,
            'unit_price'  => $saleTotal,
            'item_number' => 'AR-' . substr(md5($unique), 0, 8),
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => 100,
        ]);

        $db->table('sales')->insert([
            'sale_time'      => date('Y-m-d H:i:s'),
            'customer_id'    => $customerId,
            'employee_id'    => 1,
            'comment'        => 'AR test',
            'invoice_number' => null,
            'sale_status'    => COMPLETED,
            'sale_type'      => SALE_TYPE_POS,
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $itemId,
            'description'        => '',
            'serialnumber'       => '',
            'line'               => 0,
            'quantity_purchased' => 1,
            'item_cost_price'    => 0,
            'item_unit_price'    => $saleTotal,
            'discount'           => 0,
            'discount_type'      => 0,
            'item_location'      => 1,
        ]);

        foreach ($payments as [$type, $amount]) {
            $db->table('sales_payments')->insert([
                'sale_id'         => $saleId,
                'payment_type'    => $type,
                'payment_amount'  => $amount,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'employee_id'     => 1,
                'payment_time'    => date('Y-m-d H:i:s'),
                'reference_code'  => '',
            ]);
        }

        return [
            'sale_id'     => $saleId,
            'customer_id' => $customerId,
            'employee_id' => 1,
        ];
    }
}
