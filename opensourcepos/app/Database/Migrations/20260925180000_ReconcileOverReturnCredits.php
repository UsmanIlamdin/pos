<?php

namespace App\Database\Migrations;

use App\Libraries\Customer_account_lib;
use CodeIgniter\Database\Migration;

/**
 * Soft-void return credits / refunds that violate cumulative return quantity rules.
 * Preserves audit trail (status=voided + audit_events); does not delete history.
 */
class ReconcileOverReturnCredits extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $customers = $db->query(
            'SELECT DISTINCT customer_id FROM ' . $db->prefixTable('customer_return_credits')
            . ' UNION SELECT DISTINCT customer_id FROM ' . $db->prefixTable('customer_credit_refunds')
        )->getResultArray();

        $lib = new Customer_account_lib();
        foreach ($customers as $row) {
            $customerId = (int) $row['customer_id'];
            if ($customerId <= 0) {
                continue;
            }
            $lib->reconcileOverReturnsForCustomer($customerId, 1);
        }
    }

    public function down(): void
    {
        // Irreversible soft-void reconciliation — no automatic restore.
    }
}
