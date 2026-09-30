<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_account_payment extends Model
{
    protected $table = 'customer_account_payments';
    protected $primaryKey = 'payment_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'consolidated_invoice_id',
        'payment_type',
        'payment_amount',
        'payment_time',
        'reference_code',
        'comment',
        'employee_id',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
        'idempotency_key',
    ];
}
