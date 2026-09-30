<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_credit_refund extends Model
{
    protected $table = 'customer_credit_refunds';
    protected $primaryKey = 'refund_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'return_sale_id',
        'amount',
        'payment_type',
        'status',
        'employee_id',
        'reference_code',
        'comment',
        'idempotency_key',
        'refund_time',
        'voided_at',
        'voided_by',
    ];
}
