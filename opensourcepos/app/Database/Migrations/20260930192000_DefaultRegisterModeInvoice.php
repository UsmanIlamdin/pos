<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Default sales register mode to Invoice when invoices are enabled.
 */
class DefaultRegisterModeInvoice extends Migration
{
    public function up(): void
    {
        $this->db->table('app_config')->replace([
            'key'   => 'default_register_mode',
            'value' => 'sale_invoice',
        ]);
    }

    public function down(): void
    {
        $this->db->table('app_config')->replace([
            'key'   => 'default_register_mode',
            'value' => 'sale',
        ]);
    }
}
