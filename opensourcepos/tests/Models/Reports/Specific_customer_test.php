<?php

declare(strict_types=1);

namespace Tests\Models\Reports;

use App\Models\Reports\Specific_customer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Specific (detailed) customer report: optional "All Customers" filter.
 *
 * Export uses the same getData()/getSummaryData() payload rendered in
 * reports/tabular_details (bootstrap-table exportDataType: all), so covering
 * the model filter covers both on-screen report and export datasets.
 */
class Specific_customer_test extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private array $seededSaleIds = [];
    private array $seededItemIds = [];
    private array $seededPersonIds = [];
    private string $today;
    private int $customerAId;
    private int $customerBId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        config(OSPOS::class)->update_settings();
        $this->today = date('Y-m-d');
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function seedTestData(): void
    {
        $db = \Config\Database::connect();
        $prefix = $db->DBPrefix;
        $now = $this->today . ' 12:00:00';

        $db->table($prefix . 'people')->insert([
            'first_name'   => 'Armani',
            'last_name'    => 'Armani',
            'phone_number' => '555-1001',
            'email'        => 'armani-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $this->customerAId = (int) $db->insertID();
        $this->seededPersonIds[] = $this->customerAId;
        $db->table($prefix . 'customers')->insert([
            'person_id'     => $this->customerAId,
            'company_name'  => null,
            'taxable'       => 1,
            'discount'      => 0,
            'discount_type' => 0,
            'deleted'       => 0,
            'employee_id'   => 1,
            'consent'       => 0,
        ]);

        $db->table($prefix . 'people')->insert([
            'first_name'   => 'Other',
            'last_name'    => 'Customer',
            'phone_number' => '555-1002',
            'email'        => 'other-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $this->customerBId = (int) $db->insertID();
        $this->seededPersonIds[] = $this->customerBId;
        $db->table($prefix . 'customers')->insert([
            'person_id'     => $this->customerBId,
            'company_name'  => null,
            'taxable'       => 1,
            'discount'      => 0,
            'discount_type' => 0,
            'deleted'       => 0,
            'employee_id'   => 1,
            'consent'       => 0,
        ]);

        $db->table($prefix . 'items')->insert([
            'name'               => 'SC Report Item',
            'category'           => 'Test',
            'supplier_id'        => null,
            'item_number'        => 'SCR-' . uniqid(),
            'description'        => 'Specific customer report test item',
            'cost_price'         => 5.00,
            'unit_price'         => 10.00,
            'reorder_level'      => 0,
            'receiving_quantity' => 1,
            'stock_type'         => 0,
            'item_type'          => 0,
            'deleted'            => 0,
        ]);
        $this->itemId = (int) $db->insertID();
        $this->seededItemIds[] = $this->itemId;

        // Sale for customer A (Cash)
        $this->insertSale($db, $prefix, $now, $this->customerAId, 'Cash', 10.00);
        // Sale for customer B (Cash)
        $this->insertSale($db, $prefix, $now, $this->customerBId, 'Cash', 20.00);
        // Walk-in / no customer (Cash) — excluded from All Customers (customer reports only)
        $this->insertSale($db, $prefix, $now, null, 'Cash', 30.00);
        // Sale for customer A with Due payment (for payment-type filter)
        $this->insertSale($db, $prefix, $now, $this->customerAId, 'Due', 40.00);
        // Sale outside date range (yesterday) for customer A
        $yesterday = date('Y-m-d', strtotime('-1 day')) . ' 12:00:00';
        $this->insertSale($db, $prefix, $yesterday, $this->customerAId, 'Cash', 50.00);
        // Return for customer B (sale_type = RETURN) — excluded when sale_type = sales
        $this->insertSale($db, $prefix, $now, $this->customerBId, 'Cash', -5.00, SALE_TYPE_RETURN);
    }

    private function insertSale(
        $db,
        string $prefix,
        string $saleTime,
        ?int $customerId,
        string $paymentType,
        float $unitPrice,
        int $saleType = SALE_TYPE_POS
    ): void {
        $db->table($prefix . 'sales')->insert([
            'sale_time'      => $saleTime,
            'customer_id'    => $customerId,
            'employee_id'    => 1,
            'comment'        => 'specific_customer_test',
            'sale_status'    => COMPLETED,
            'invoice_number' => null,
            'sale_type'      => $saleType,
        ]);
        $saleId = (int) $db->insertID();
        $this->seededSaleIds[] = $saleId;

        $db->table($prefix . 'sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $this->itemId,
            'line'               => 0,
            'description'        => 'line',
            'quantity_purchased' => 1,
            'item_unit_price'    => $unitPrice,
            'discount'           => 0,
            'discount_type'      => PERCENT,
            'item_cost_price'    => 5.00,
            'item_location'      => 1,
        ]);

        $db->table($prefix . 'sales_payments')->insert([
            'sale_id'          => $saleId,
            'payment_type'     => $paymentType,
            'payment_amount'   => $unitPrice,
            'cash_refund'      => 0,
            'cash_adjustment'  => 0,
            'employee_id'      => 1,
            'payment_time'     => $saleTime,
            'reference_code'   => '',
        ]);
    }

    private function cleanupTestData(): void
    {
        $db = \Config\Database::connect();
        $prefix = $db->DBPrefix;

        if (!empty($this->seededSaleIds)) {
            $db->table($prefix . 'sales_payments')->whereIn('sale_id', $this->seededSaleIds)->delete();
            $db->table($prefix . 'sales_items')->whereIn('sale_id', $this->seededSaleIds)->delete();
            $db->table($prefix . 'sales')->whereIn('sale_id', $this->seededSaleIds)->delete();
        }

        if (!empty($this->seededItemIds)) {
            $db->table($prefix . 'items')->whereIn('item_id', $this->seededItemIds)->delete();
        }

        if (!empty($this->seededPersonIds)) {
            $db->table($prefix . 'customers')->whereIn('person_id', $this->seededPersonIds)->delete();
            $db->table($prefix . 'people')->whereIn('person_id', $this->seededPersonIds)->delete();
        }

        $this->seededSaleIds = [];
        $this->seededItemIds = [];
        $this->seededPersonIds = [];
    }

    private function baseInputs(array $overrides = []): array
    {
        return array_merge([
            'start_date'   => $this->today,
            'end_date'     => $this->today,
            'customer_id'  => '',
            'sale_type'    => 'complete',
            'payment_type' => 'all',
        ], $overrides);
    }

    private function runReport(array $inputs): array
    {
        $model = model(Specific_customer::class);
        $model->create($inputs);

        return [
            'data'    => $model->getData($inputs),
            'summary' => $model->getSummaryData($inputs),
        ];
    }

    public function testSpecificCustomerReturnsOnlyThatCustomer(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => (string) $this->customerAId,
        ]));

        $this->assertNotEmpty($result['data']['summary']);
        foreach ($result['data']['summary'] as $row) {
            $this->assertContains((float) $row['total'], [10.0, 40.0], 'Unexpected sale total for customer A');
            $this->assertSame('Armani Armani', $row['customer_name']);
        }
        $this->assertCount(2, $result['data']['summary']);
        $this->assertEqualsWithDelta(50.0, (float) $result['summary']['total'], 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $result['summary']['trans_due'], 0.01);
    }

    public function testAllCustomersIncludesOnlyNamedCustomers(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => '',
        ]));

        // Today complete with customers only: A cash 10, B cash 20, A due 40, B return -5
        // Walk-in (30) is excluded — this is a customer report.
        $this->assertCount(4, $result['data']['summary']);
        $totals = array_map(static fn (array $row): float => (float) $row['total'], $result['data']['summary']);
        sort($totals);
        $this->assertEquals([-5.0, 10.0, 20.0, 40.0], $totals);
        $this->assertEqualsWithDelta(65.0, (float) $result['summary']['total'], 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $result['summary']['trans_due'], 0.01);

        $names = array_unique(array_column($result['data']['summary'], 'customer_name'));
        $this->assertContains('Armani Armani', $names);
        $this->assertContains('Other Customer', $names);
        foreach ($result['data']['summary'] as $row) {
            $this->assertNotEmpty(trim((string) $row['customer_name']), 'Walk-in / null customer must not appear');
        }
    }

    public function testAllCustomersExcludesWalkInSales(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => 'all',
        ]));

        foreach ($result['data']['summary'] as $row) {
            $this->assertNotContains((float) $row['total'], [30.0], 'Walk-in sale total must not be included');
            $this->assertNotEmpty(trim((string) $row['customer_name']));
        }
    }

    public function testCustomerColumnIsPresentInHeaders(): void
    {
        $columns = model(Specific_customer::class)->getDataColumns();
        $summaryKeys = [];
        foreach ($columns['summary'] as $column) {
            $summaryKeys = array_merge($summaryKeys, array_keys($column));
        }
        $this->assertContains('customer_name', $summaryKeys);
    }

    public function testAllCustomersDoesNotUseCustomerIdZero(): void
    {
        $empty = $this->runReport($this->baseInputs(['customer_id' => '']));
        $all = $this->runReport($this->baseInputs(['customer_id' => 'all']));
        $zero = $this->runReport($this->baseInputs(['customer_id' => '0']));

        $this->assertCount(4, $empty['data']['summary']);
        $this->assertCount(4, $all['data']['summary']);
        // "0" is a concrete filter (WHERE customer_id = 0), not All Customers — expect no seeded rows.
        $this->assertCount(0, $zero['data']['summary']);
    }

    public function testDateFilterStillAppliedForAllCustomers(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => '',
            'start_date'  => date('Y-m-d', strtotime('-1 day')),
            'end_date'    => date('Y-m-d', strtotime('-1 day')),
        ]));

        // Only yesterday's customer A cash 50
        $this->assertCount(1, $result['data']['summary']);
        $this->assertEqualsWithDelta(50.0, (float) $result['summary']['total'], 0.01);
    }

    public function testTransactionTypeFilterStillAppliedForAllCustomers(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => '',
            'sale_type'   => 'sales', // completed POS/invoice only — excludes returns
        ]));

        $totals = array_map(static fn (array $row): float => (float) $row['total'], $result['data']['summary']);
        $this->assertNotContains(-5.0, $totals);
        // A cash 10, B cash 20, A due 40 (walk-in excluded)
        $this->assertCount(3, $result['data']['summary']);
    }

    public function testPaymentTypeFilterStillAppliedForAllCustomers(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id'  => '',
            'payment_type' => 'cash',
        ]));

        // Cash today complete with customers: A 10, B 20, B return -5 (walk-in excluded)
        $this->assertCount(3, $result['data']['summary']);
        foreach ($result['data']['summary'] as $row) {
            $this->assertStringContainsString('Cash', (string) $row['payment_type']);
        }
    }

    public function testSpecificCustomerExportDatasetUnchangedShape(): void
    {
        $result = $this->runReport($this->baseInputs([
            'customer_id' => (string) $this->customerAId,
        ]));

        $this->assertArrayHasKey('summary', $result['data']);
        $this->assertArrayHasKey('details', $result['data']);
        $this->assertArrayHasKey('subtotal', $result['summary']);
        $this->assertArrayHasKey('total', $result['summary']);
    }

    public function testAllCustomersExportUsesSameDatasetAsReport(): void
    {
        // Report and export share getData(); calling twice must match.
        $first = $this->runReport($this->baseInputs(['customer_id' => 'all']));
        $second = $this->runReport($this->baseInputs(['customer_id' => '']));

        $this->assertSame(
            array_column($first['data']['summary'], 'sale_id'),
            array_column($second['data']['summary'], 'sale_id')
        );
        $this->assertEqualsWithDelta(
            (float) $first['summary']['total'],
            (float) $second['summary']['total'],
            0.01
        );
    }
}
