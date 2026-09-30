<?php

namespace App\Commands;

use App\Libraries\Customer_account_lib;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RepairCashReturnAccounting extends BaseCommand
{
    protected $group       = 'Accounts';
    protected $name        = 'accounts:repair-cash-returns';
    protected $description = 'Backfill customer accounting for cash-settled returns missing ledger rows.';

    public function run(array $params)
    {
        $customerId = isset($params[0]) ? (int) $params[0] : 0;
        $lib = new Customer_account_lib();

        if ($customerId > 0) {
            CLI::write('Before customer ' . $customerId . ':');
            CLI::write('  outstanding=' . $lib->getCustomerOutstanding($customerId));
            CLI::write('  credit=' . $lib->getCustomerCreditBalance($customerId));
        }

        $repaired = $lib->repairMissingCashReturnAccounting(1);
        CLI::write('Repaired return sale ids: ' . json_encode($repaired));

        if ($customerId > 0) {
            CLI::write('After customer ' . $customerId . ':');
            CLI::write('  outstanding=' . $lib->getCustomerOutstanding($customerId));
            CLI::write('  credit=' . $lib->getCustomerCreditBalance($customerId));
            $statement = $lib->getStatementPage($customerId, 1, PHP_INT_MAX);
            foreach ($statement['rows'] as $row) {
                if (str_starts_with((string) ($row['reference'] ?? ''), 'Return #')) {
                    CLI::write(sprintf(
                        '  [%s] %s debit=%s credit=%s memo=%s',
                        $row['type'] ?? '',
                        $row['reference'] ?? '',
                        $row['debit'] ?? 0,
                        $row['credit'] ?? 0,
                        $row['memo'] ?? ''
                    ));
                }
            }
            $report = $lib->reconcileCustomer($customerId);
            CLI::write('reconcile issues=' . count($report['issues'])
                . ' outstanding=' . $report['outstanding']
                . ' statement=' . $report['statement_end']);
        }
    }
}
