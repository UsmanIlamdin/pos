<?php

namespace Tests\Models;

use App\Models\Customer;
use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class CustomerStatsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\\Database\\Seeds\\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();
            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    private function createCustomer(): int
    {
        $db = \Config\Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Alex',
            'last_name'    => 'Alex',
            'phone_number' => '555-0101',
            'email'        => 'alex-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('customers')->insert([
            'person_id'   => $personId,
            'employee_id' => 1,
            'points'      => 0,
        ]);

        return $personId;
    }

    private function buildCart(array $lines): array
    {
        $items = [];
        foreach ($lines as $index => $line) {
            $items[] = [
                'item_id'       => $line['item_id'],
                'line'          => $index,
                'description'   => 'Test Item',
                'serialnumber'  => '',
                'quantity'      => $line['quantity'],
                'discount'      => 0,
                'discount_type' => 0,
                'cost_price'    => 1.00,
                'price'         => $line['price'],
                'item_location' => 1,
                'print_option'  => 0,
            ];
        }

        return $items;
    }

    public function testCustomerStatsIgnoreReturnDocumentsAndVoidedPaymentRows(): void
    {
        $employeeId = $this->createEmployee();
        $customerId = $this->createCustomer();
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $saleModel = model(Sale::class);
        $saleId = $saleModel->save_value(
            NEW_ENTRY,
            COMPLETED,
            $this->buildCart([
                ['item_id' => $itemId, 'quantity' => 5, 'price' => 20.00],
                ['item_id' => $itemId, 'quantity' => 5, 'price' => 30.00],
            ]),
            $customerId,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            [[
                'payment_type'    => 'Cash',
                'payment_amount'  => 100.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ]],
            null,
            [[], []]
        );

        $this->assertGreaterThan(0, $saleId);

        $returnId = $saleModel->save_value(
            NEW_ENTRY,
            COMPLETED,
            $this->buildCart([
                ['item_id' => $itemId, 'quantity' => -2, 'price' => 20.00],
            ]),
            $customerId,
            $employeeId,
            'return sale',
            null,
            null,
            null,
            SALE_TYPE_RETURN,
            [[
                'payment_type'    => 'Cash',
                'payment_amount'  => 0,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ]],
            null,
            [[], []]
        );

        $this->assertGreaterThan(0, $returnId);
        \Config\Database::connect()->table('sales')->where('sale_id', $returnId)->update(['return_of_sale_id' => $saleId]);

        $stats = model(Customer::class)->get_stats($customerId);

        $this->assertNotNull($stats);
        $this->assertEqualsWithDelta(250.00, $stats->total, 0.001);
        $this->assertEqualsWithDelta(250.00, $stats->min, 0.001);
        $this->assertEqualsWithDelta(250.00, $stats->max, 0.001);
        $this->assertEqualsWithDelta(250.00, $stats->average, 0.001);
        $this->assertEqualsWithDelta(10.0, $stats->quantity, 0.001);
    }
}

