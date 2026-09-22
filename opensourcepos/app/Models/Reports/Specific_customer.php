<?php

namespace App\Models\Reports;

use App\Models\Sale;

/**
 *
 *
 * @property sale sale
 *
 */
class Specific_customer extends Report
{
    /**
     * @param array $inputs
     * @return void
     */
    public function create(array $inputs): void
    {
        // Create our temp tables to work with the data in our report
        $sale = model(Sale::class);
        $sale->create_temp_table($inputs);
    }

    /**
     * @return array
     */
    public function getDataColumns(): array
    {
        return [
            'summary' => [
                ['id'            => lang('Reports.sale_id')],
                ['type_code'     => lang('Reports.code_type')],
                ['sale_time'     => lang('Reports.date'), 'sortable' => false],
                ['quantity'      => lang('Reports.quantity')],
                ['employee_name' => lang('Reports.sold_by')],
                ['customer_name' => lang('Reports.customer')],
                ['subtotal'      => lang('Reports.subtotal'), 'sorter' => 'number_sorter'],
                ['tax'           => lang('Reports.tax'), 'sorter' => 'number_sorter'],
                ['total'         => lang('Reports.total'), 'sorter' => 'number_sorter'],
                ['cost'          => lang('Reports.cost'), 'sorter' => 'number_sorter'],
                ['profit'        => lang('Reports.profit'), 'sorter' => 'number_sorter'],
                ['payment_type'  => lang('Reports.payment_type'), 'sortable' => false],
                ['comment'       => lang('Reports.comments')]
            ],
            'details' => [
                lang('Reports.name'),
                lang('Reports.category'),
                lang('Reports.item_number'),
                lang('Reports.description'),
                lang('Reports.quantity'),
                lang('Reports.subtotal'),
                lang('Reports.tax'),
                lang('Reports.total'),
                lang('Reports.cost'),
                lang('Reports.profit'),
                lang('Reports.discount')
            ],
            'details_rewards' => [
                lang('Reports.used'),
                lang('Reports.earned')
            ]
        ];
    }

    /**
     * @param array $inputs
     * @return array
     */
    public function getData(array $inputs): array
    {
        $builder = $this->db->table('sales_items_temp');
        $builder->select('
            sale_id,
            MAX(CASE
            WHEN sale_type = ' . SALE_TYPE_POS . ' && sale_status = ' . COMPLETED . ' THEN \'' . lang('Reports.code_pos') . '\'
            WHEN sale_type = ' . SALE_TYPE_INVOICE . ' && sale_status = ' . COMPLETED . ' THEN \'' . lang('Reports.code_invoice') . '\'
            WHEN sale_type = ' . SALE_TYPE_WORK_ORDER . ' && sale_status = ' . SUSPENDED . ' THEN \'' . lang('Reports.code_work_order') . '\'
            WHEN sale_type = ' . SALE_TYPE_QUOTE . ' && sale_status = ' . SUSPENDED . ' THEN \'' . lang('Reports.code_quote') . '\'
            WHEN sale_type = ' . SALE_TYPE_RETURN . ' && sale_status = ' . COMPLETED . ' THEN \'' . lang('Reports.code_return') . '\'
            WHEN sale_status = ' . CANCELED . ' THEN \'' . lang('Reports.code_canceled') . '\'
            ELSE \'\'
            END) AS type_code,
            MAX(sale_status) as sale_status,
            MAX(sale_time) AS sale_time,
            SUM(quantity_purchased) AS items_purchased,
            MAX(employee_name) AS employee_name,
            MAX(customer_name) AS customer_name,
            SUM(subtotal) AS subtotal,
            SUM(tax) AS tax,
            SUM(total) AS total,
            SUM(cost) AS cost,
            SUM(profit) AS profit,
            MAX(payment_type) AS payment_type,
            MAX(comment) AS comment');

        $this->applyReportFilters($builder, $inputs);

        $builder->groupBy('sale_id');    // TODO: Duplicated code
        $builder->orderBy('MAX(sale_time)');

        $data = [];
        $data['summary'] = $builder->get()->getResultArray();
        $data['details'] = [];
        $data['rewards'] = [];

        foreach ($data['summary'] as $key => $value) {
            $builder = $this->db->table('sales_items_temp');
            $builder->select('name, category, item_number, description, quantity_purchased, subtotal, tax, total, cost, profit, discount, discount_type');
            $builder->where('sale_id', $value['sale_id']);
            $data['details'][$key] = $builder->get()->getResultArray();

            $builder = $this->db->table('sales_reward_points');
            $builder->select('used, earned');
            $builder->where('sale_id', $value['sale_id']);
            $data['rewards'][$key] = $builder->get()->getResultArray();
        }

        return $data;
    }

    /**
     * @param array $inputs
     * @return array
     */
    public function getSummaryData(array $inputs): array
    {
        $builder = $this->db->table('sales_items_temp');
        $builder->select('SUM(subtotal) AS subtotal, SUM(tax) AS tax, SUM(total) AS total, SUM(cost) AS cost, SUM(profit) AS profit');

        $this->applyReportFilters($builder, $inputs);

        $summary = $builder->get()->getRowArray() ?? [
            'subtotal' => 0,
            'tax'      => 0,
            'total'    => 0,
            'cost'     => 0,
            'profit'   => 0,
        ];

        $summary['trans_due'] = $this->getDueTotal($inputs);

        return $summary;
    }

    /**
     * Sum of Due payment amounts for sales matching the same report filters.
     */
    private function getDueTotal(array $inputs): float
    {
        $saleIdsBuilder = $this->db->table('sales_items_temp');
        $saleIdsBuilder->distinct();
        $saleIdsBuilder->select('sale_id');
        $this->applyReportFilters($saleIdsBuilder, $inputs);
        $saleIds = array_column($saleIdsBuilder->get()->getResultArray(), 'sale_id');

        if ($saleIds === []) {
            return 0.0;
        }

        $paymentsBuilder = $this->db->table('sales_payments');
        $paymentsBuilder->select('SUM(payment_amount - cash_refund) AS due_total', false);
        $paymentsBuilder->where('payment_type', lang('Sales.due'));
        $paymentsBuilder->whereIn('sale_id', $saleIds);
        $row = $paymentsBuilder->get()->getRowArray();

        return (float) ($row['due_total'] ?? 0);
    }

    /**
     * Shared customer / payment / sale-type filters for detail + summary queries.
     *
     * @param \CodeIgniter\Database\BaseBuilder $builder
     */
    private function applyReportFilters($builder, array $inputs): void
    {
        // Specific customer: filter to that id.
        // All Customers (empty / "all"): every sale that has a customer — exclude walk-ins (NULL).
        // Never use customer_id = 0 to mean "all".
        if (isset($inputs['customer_id']) && $inputs['customer_id'] !== '' && $inputs['customer_id'] !== 'all') {
            $builder->where('customer_id', $inputs['customer_id']);
        } else {
            $builder->where('customer_id IS NOT NULL', null, false);
        }

        if ($inputs['payment_type'] == 'invoices') {
            $builder->where('sale_type', SALE_TYPE_INVOICE);
        } elseif ($inputs['payment_type'] != 'all') {
            $builder->like('payment_type', lang('Sales.' . $inputs['payment_type']));
        }

        switch ($inputs['sale_type']) {
            case 'complete':
                $builder->where('sale_status', COMPLETED);
                $builder->groupStart();
                $builder->where('sale_type', SALE_TYPE_POS);
                $builder->orWhere('sale_type', SALE_TYPE_INVOICE);
                $builder->orWhere('sale_type', SALE_TYPE_RETURN);
                $builder->groupEnd();
                break;

            case 'sales':
                $builder->where('sale_status', COMPLETED);
                $builder->groupStart();
                $builder->where('sale_type', SALE_TYPE_POS);
                $builder->orWhere('sale_type', SALE_TYPE_INVOICE);
                $builder->groupEnd();
                break;

            case 'quotes':
                $builder->where('sale_status', SUSPENDED);
                $builder->where('sale_type', SALE_TYPE_QUOTE);
                break;

            case 'work_orders':
                $builder->where('sale_status', SUSPENDED);
                $builder->where('sale_type', SALE_TYPE_WORK_ORDER);
                break;

            case 'canceled':
                $builder->where('sale_status', CANCELED);
                break;

            case 'returns':
                $builder->where('sale_status', COMPLETED);
                $builder->where('sale_type', SALE_TYPE_RETURN);
                break;
        }
    }
}
