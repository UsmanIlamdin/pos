<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CustomerAccountVoidReturns extends Migration
{
    public function up(): void
    {
        helper('migration');
        executeScript(APPPATH . 'Database/Migrations/sqlscripts/20260925120000_customer_account_void_returns.sql');
    }

    public function down(): void
    {
        $this->db->query('DELETE FROM `ospos_grants` WHERE `permission_id` IN (\'accounts_void\', \'accounts_reallocate\', \'accounts_credit_apply\', \'accounts_refund\')');
        $this->db->query('DELETE FROM `ospos_permissions` WHERE `permission_id` IN (\'accounts_void\', \'accounts_reallocate\', \'accounts_credit_apply\', \'accounts_refund\')');

        $this->forge->dropTable('customer_account_audit_events', true);
        $this->forge->dropTable('customer_credit_refunds', true);
        $this->forge->dropTable('customer_credit_applications', true);
        $this->forge->dropTable('customer_return_credit_allocations', true);
        $this->forge->dropTable('customer_return_credits', true);

        // Best-effort column drops (MySQL versions vary on IF EXISTS for columns)
        try {
            $this->db->query('ALTER TABLE `ospos_sales` DROP FOREIGN KEY `ospos_sales_return_of_sale_ibfk`');
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $this->db->query('ALTER TABLE `ospos_sales` DROP COLUMN `return_of_sale_id`');
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $this->db->query('ALTER TABLE `ospos_customer_payment_allocations` DROP COLUMN `status`');
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $this->db->query('ALTER TABLE `ospos_customer_account_payments` DROP INDEX `customer_idempotency`');
            $this->db->query('ALTER TABLE `ospos_customer_account_payments` DROP COLUMN `idempotency_key`, DROP COLUMN `void_reason`, DROP COLUMN `voided_by`, DROP COLUMN `voided_at`, DROP COLUMN `status`');
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
