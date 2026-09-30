<?php

namespace App\Database\Migrations;

use App\Libraries\Consolidated_invoice_lib;
use App\Libraries\Customer_account_lib;
use CodeIgniter\Database\Migration;

/**
 * Reconciles derived customer-account state without deleting historical rows.
 */
class ReconcileCustomerAccountPayments extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $prefix = $db->DBPrefix;

        $db->query(
            'UPDATE ' . $prefix . 'customer_payment_allocations AS cpa
             INNER JOIN ' . $prefix . 'customer_account_payments AS cap
                 ON cap.payment_id = cpa.customer_account_payment_id
             SET cpa.status = ?
             WHERE cap.status = ? AND cpa.status = ?',
            [CA_STATUS_VOIDED, CA_STATUS_VOIDED, CA_STATUS_ACTIVE]
        );

        $accountLib = new Customer_account_lib();
        $invoiceLib = new Consolidated_invoice_lib($accountLib);
        $invoices = $db->table('consolidated_invoices')
            ->select('consolidated_invoice_id')
            ->get()
            ->getResultArray();

        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice['consolidated_invoice_id'];
            $before = $db->table('consolidated_invoices')
                ->select('status')
                ->where('consolidated_invoice_id', $invoiceId)
                ->get()
                ->getRowArray();
            $after = $invoiceLib->refreshStatus($invoiceId);

            if ($before !== null && (int) $before['status'] !== (int) $after) {
                $customer = $db->table('consolidated_invoices')
                    ->select('customer_id')
                    ->where('consolidated_invoice_id', $invoiceId)
                    ->get()
                    ->getRowArray();
                if ($customer !== null) {
                    $db->table('customer_account_audit_events')->insert([
                        'customer_id' => (int) $customer['customer_id'],
                        'event_type'  => 'invoice_status_reconciled',
                        'entity_type' => 'consolidated_invoice',
                        'entity_id'   => $invoiceId,
                        'payload'     => json_encode([
                            'before' => (int) $before['status'],
                            'after'  => (int) $after,
                        ]),
                        'employee_id' => 1,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Derived-state reconciliation is intentionally not reversed.
    }
}
