<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Restore formatted sales invoice numbers (INVC-{ISEQ}).
 *
 * FILTER_SANITIZE_NUMBER_INT on setInvoiceNumber was stripping letter prefixes,
 * so values like "INVC-10" were stored as "-10".
 */
class FixInvoiceNumberFormat extends Migration
{
    public function up(): void
    {
        $prefix = $this->db->getPrefix();

        // Prefer INVC-{ISEQ} for new invoices (matches quote-style sequence tokens).
        $this->db->table('app_config')->replace([
            'key'   => 'sales_invoice_format',
            'value' => 'INVC-{ISEQ}',
        ]);

        // Repair mangled invoice numbers produced by NUMBER_INT sanitization.
        $this->db->query(
            "UPDATE `{$prefix}sales`
             SET `invoice_number` = CONCAT('INVC-', ABS(`invoice_number`))
             WHERE `invoice_number` REGEXP '^-?[0-9]+$'
               AND `invoice_number` LIKE '-%'"
        );

        // Advance sequence past the highest INVC-N already in use.
        $row = $this->db->query(
            "SELECT MAX(CAST(SUBSTRING(`invoice_number`, 6) AS UNSIGNED)) AS max_seq
             FROM `{$prefix}sales`
             WHERE `invoice_number` REGEXP '^INVC-[0-9]+$'"
        )->getRowArray();

        $maxSeq = (int) ($row['max_seq'] ?? 0);
        if ($maxSeq > 0) {
            $this->db->table('app_config')->replace([
                'key'   => 'last_used_invoice_number',
                'value' => (string) $maxSeq,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('app_config')->replace([
            'key'   => 'sales_invoice_format',
            'value' => '{CO}',
        ]);
    }
}
