<?php

namespace App\Database\Migrations;

use App\Libraries\Customer_account_lib;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Schema for return settlement mode / source_line / inventory.sale_id,
 * then idempotent backfill of missing return accounting + Return #2 stock repair.
 */
class ReturnAccountingIntegrity extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('customer_return_credits', 'settlement_mode', "VARCHAR(20) NOT NULL DEFAULT 'outstanding' AFTER `return_amount`");
        $this->addColumnIfMissing('customer_credit_refunds', 'return_sale_id', 'INT(10) NULL DEFAULT NULL AFTER `customer_id`');
        $this->addIndexIfMissing('customer_credit_refunds', 'return_sale_id', 'KEY `return_sale_id` (`return_sale_id`)');
        $this->addColumnIfMissing('sales_items', 'source_line', 'INT(3) NULL DEFAULT NULL AFTER `line`');
        $this->addColumnIfMissing('inventory', 'sale_id', 'INT(10) NULL DEFAULT NULL AFTER `trans_id`');
        $this->addIndexIfMissing('inventory', 'sale_id', 'KEY `sale_id` (`sale_id`)');

        // Backfill inventory.sale_id from POS {id} comments where possible.
        $this->db->query(
            "UPDATE " . $this->db->prefixTable('inventory')
            . " SET sale_id = CAST(SUBSTRING(trans_comment, 5) AS UNSIGNED)"
            . " WHERE sale_id IS NULL AND trans_comment REGEXP '^POS [0-9]+'"
        );
    }

    public function down(): void
    {
        // Schema extensions are left in place; repair is not reversed.
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $prefixed = $this->db->prefixTable($table);
        if ($this->db->fieldExists($column, $table)) {
            return;
        }
        try {
            $this->db->query("ALTER TABLE `{$prefixed}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            // Column may already exist under race / partial run.
        }
    }

    private function addIndexIfMissing(string $table, string $indexName, string $indexSql): void
    {
        $prefixed = $this->db->prefixTable($table);
        $exists = $this->db->query("SHOW INDEX FROM `{$prefixed}` WHERE Key_name = " . $this->db->escape($indexName))
            ->getResultArray();
        if ($exists !== []) {
            return;
        }
        try {
            $this->db->query("ALTER TABLE `{$prefixed}` ADD {$indexSql}");
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
