<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CustomerAccounts extends Migration
{
    public function up(): void
    {
        helper('migration');
        executeScript(APPPATH . 'Database/Migrations/sqlscripts/20260922190000_customer_accounts.sql');
    }

    public function down(): void
    {
        $this->db->query('DELETE FROM `ospos_grants` WHERE `permission_id` IN (\'accounts\', \'accounts_payments\', \'accounts_consolidated\', \'accounts_cancel\', \'reports_accounts\')');
        $this->db->query('DELETE FROM `ospos_permissions` WHERE `permission_id` IN (\'accounts\', \'accounts_payments\', \'accounts_consolidated\', \'accounts_cancel\', \'reports_accounts\')');
        $this->db->query('DELETE FROM `ospos_modules` WHERE `module_id` = \'accounts\'');
        $this->db->query('DELETE FROM `ospos_app_config` WHERE `key` = \'last_used_consolidated_invoice_number\'');
        $this->forge->dropTable('customer_payment_allocations', true);
        $this->forge->dropTable('customer_account_payments', true);
        $this->forge->dropTable('consolidated_invoice_sales', true);
        $this->forge->dropTable('consolidated_invoices', true);
    }
}
