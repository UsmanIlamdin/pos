<?php

namespace App\Models\Reports;

use App\Libraries\Customer_account_lib;
use App\Models\Customer;

/**
 * Customer account statement / balance reports backed by Customer_account_lib.
 *
 * Reports audit (Phase J):
 * | Area | Current | Correct |
 * | Statement | Chronological getStatementPage events | Sale debit + POS payment once + CA payment once + return/credit/refund |
 * | Balance | Outstanding Balance + phone/email | Same getCustomerOutstanding as statement ending |
 * | Specific_customer | Document Type (sale_type) vs Payment Method (excludes Due) | Outstanding Total from lib |
 * | Pagination / as-of | getStatementPage page + start/end date | Opening balance before page |
 */
class Account_receivables
{
    private Customer_account_lib $accountLib;

    public function __construct(?Customer_account_lib $accountLib = null)
    {
        $this->accountLib = $accountLib ?? new Customer_account_lib();
        helper(['tabular', 'locale']);
    }

    /**
     * @return array{headers: array, data: list<array>, summary_data: array}
     */
    public function getAccountStatement(
        int $customerId,
        ?string $startDate = null,
        ?string $endDate = null,
        int $page = 1,
        int $perPage = PHP_INT_MAX
    ): array {
        $statement = $this->accountLib->getStatementPage($customerId, $page, $perPage, $startDate, $endDate);
        $rows = [];
        foreach ($statement['rows'] as $row) {
            $allocated = !empty($row['allocated_to']) ? ' → ' . $row['allocated_to'] : '';
            $rows[] = [
                'date'        => $row['date'],
                'reference'   => $row['reference'] . $allocated . (!empty($row['memo']) ? ' — ' . $row['memo'] : ''),
                'transaction' => $row['transaction'] ?? $row['type'],
                'debit'       => to_currency($row['debit']),
                'credit'      => to_currency($row['credit']),
                'balance'     => to_currency($row['balance']),
            ];
        }

        $outstanding = $this->accountLib->getCustomerOutstanding($customerId);
        $credit = $this->accountLib->getCustomerCreditBalance($customerId);

        return [
            'headers' => [
                ['date'        => lang('Reports.date')],
                ['reference'   => lang('Accounts.reference')],
                ['transaction' => lang('Reports.transaction')],
                ['debit'       => lang('Accounts.debit')],
                ['credit'      => lang('Accounts.credit')],
                ['balance'     => lang('Accounts.balance')],
            ],
            'data' => $rows,
            'summary_data' => [
                'total'           => $outstanding,
                'customer_credit' => $credit,
                'opening_balance' => $statement['opening_balance'] ?? 0.0,
                'ending_balance'  => $statement['ending_balance'] ?? $outstanding,
            ],
            'pagination' => [
                'page'        => $statement['page'],
                'per_page'    => $statement['per_page'],
                'total'       => $statement['total'],
                'total_pages' => $statement['total_pages'],
            ],
        ];
    }

    /**
     * @return array{headers: array, data: list<array>, summary_data: array}
     */
    public function getAccountStatementAll(?string $startDate = null, ?string $endDate = null): array
    {
        $customers = model(Customer::class)->get_all()->getResult();
        $rows = [];
        $grandTotal = 0.0;

        foreach ($customers as $customer) {
            $customerId = (int) $customer->person_id;
            $name = trim($customer->first_name . ' ' . $customer->last_name);
            $statement = $this->accountLib->getStatementPage($customerId, 1, PHP_INT_MAX, $startDate, $endDate);
            if ($statement['rows'] === []) {
                continue;
            }

            $outstanding = $this->accountLib->getCustomerOutstanding($customerId);
            $grandTotal += $outstanding;

            foreach ($statement['rows'] as $row) {
                $allocated = !empty($row['allocated_to']) ? ' → ' . $row['allocated_to'] : '';
                $rows[] = [
                    'customer'    => $name,
                    'date'        => $row['date'],
                    'reference'   => $row['reference'] . $allocated . (!empty($row['memo']) ? ' — ' . $row['memo'] : ''),
                    'transaction' => $row['transaction'] ?? $row['type'],
                    'debit'       => to_currency($row['debit']),
                    'credit'      => to_currency($row['credit']),
                    'balance'     => to_currency($row['balance']),
                ];
            }
        }

        return [
            'headers' => [
                ['customer'    => lang('Reports.customer')],
                ['date'        => lang('Reports.date')],
                ['reference'   => lang('Accounts.reference')],
                ['transaction' => 'Transaction'],
                ['debit'       => lang('Accounts.debit')],
                ['credit'      => lang('Accounts.credit')],
                ['balance'     => lang('Accounts.balance')],
            ],
            'data' => $rows,
            'summary_data' => [
                'total' => $grandTotal,
            ],
        ];
    }

    /**
     * Customers Balance: Outstanding Balance + phone + email.
     *
     * @return array{headers: array, data: list<array>, summary_data: array}
     */
    public function getCustomersBalance(int $page = 1, int $perPage = PHP_INT_MAX): array
    {
        $customers = model(Customer::class)->get_all()->getResult();
        $rows = [];
        $grandTotal = 0.0;

        foreach ($customers as $customer) {
            $due = $this->accountLib->getCustomerOutstanding((int) $customer->person_id);
            if ($due <= 0) {
                continue;
            }
            $grandTotal += $due;
            $phone = trim((string) ($customer->phone_number ?? ''));
            $email = trim((string) ($customer->email ?? ''));
            $rows[] = [
                'customer'             => trim($customer->first_name . ' ' . $customer->last_name),
                'phone_number'         => $phone !== '' ? $phone : '—',
                'email'                => $email !== '' ? $email : '—',
                'outstanding_balance'  => to_currency($due),
                '_sort_balance'        => $due,
            ];
        }

        $total = count($rows);
        if ($perPage !== PHP_INT_MAX && $perPage > 0) {
            $page = max(1, $page);
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($rows, $offset, $perPage);
        }

        $data = [];
        foreach ($rows as $row) {
            unset($row['_sort_balance']);
            $data[] = $row;
        }

        return [
            'headers' => [
                ['customer'            => lang('Reports.customer')],
                ['phone_number'        => lang('Common.phone_number')],
                ['email'               => lang('Common.email')],
                ['outstanding_balance' => lang('Accounts.outstanding_balance')],
            ],
            'data' => $data,
            'summary_data' => [
                'total' => $grandTotal,
            ],
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage === PHP_INT_MAX ? max(1, $total) : $perPage,
                'total'    => $total,
            ],
        ];
    }
}
