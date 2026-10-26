<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class VoidConsolidatedInvoice extends Migration
{
    public function up(): void
    {
        helper('migration');
        executeScript(APPPATH . 'Database/Migrations/sqlscripts/20261009120000_void_consolidated_invoice.sql');
    }

    public function down(): void
    {
        try {
            $this->db->query('ALTER TABLE `ospos_consolidated_invoices` DROP KEY `voided_by`');
        } catch (\Throwable $e) {
            // ignore
        }

        foreach (['void_reason', 'voided_by', 'voided_at'] as $column) {
            try {
                $this->db->query('ALTER TABLE `ospos_consolidated_invoices` DROP COLUMN `' . $column . '`');
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Best-effort restore of UNIQUE(sale_id); fails if duplicates exist after voids.
        try {
            $this->db->query('ALTER TABLE `ospos_consolidated_invoice_sales` DROP FOREIGN KEY `ospos_consolidated_invoice_sales_ibfk_2`');
            $this->db->query('ALTER TABLE `ospos_consolidated_invoice_sales` DROP INDEX `sale_id`');
            $this->db->query('ALTER TABLE `ospos_consolidated_invoice_sales` ADD UNIQUE KEY `sale_id` (`sale_id`)');
            $this->db->query(
                'ALTER TABLE `ospos_consolidated_invoice_sales`
                 ADD CONSTRAINT `ospos_consolidated_invoice_sales_ibfk_2`
                 FOREIGN KEY (`sale_id`) REFERENCES `ospos_sales` (`sale_id`)'
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
