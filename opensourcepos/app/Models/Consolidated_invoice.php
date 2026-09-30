<?php

namespace App\Models;

use CodeIgniter\Model;

class Consolidated_invoice extends Model
{
    protected $table = 'consolidated_invoices';
    protected $primaryKey = 'consolidated_invoice_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'total_amount',
        'status',
        'comment',
        'employee_id',
    ];
}
