<?php

namespace App\Database\Migrations;

use App\Libraries\Consolidated_invoice_lib;
use App\Libraries\Customer_account_lib;
use CodeIgniter\Database\Migration;

/**
 * Refreshes consolidated-invoice statuses after active payment reconciliation.
 */
class ReconcileCustomerInvoiceBalances extends Migration
{
    public function up(): void
    {
        $invoiceLib = new Consolidated_invoice_lib(new Customer_account_lib());
        $invoices = $this->db->table('consolidated_invoices')
            ->select('consolidated_invoice_id')
            ->get()
            ->getResultArray();

        foreach ($invoices as $invoice) {
            $invoiceLib->refreshStatus((int) $invoice['consolidated_invoice_id']);
        }
    }

    public function down(): void
    {
        // Statuses are derived from current accounting records and are not restored.
    }
}
