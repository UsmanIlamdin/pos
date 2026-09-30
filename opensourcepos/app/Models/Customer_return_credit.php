<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_return_credit extends Model
{
    protected $table = 'customer_return_credits';
    protected $primaryKey = 'return_credit_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'return_sale_id',
        'customer_id',
        'original_sale_id',
        'return_amount',
        'settlement_mode',
        'status',
        'employee_id',
        'voided_at',
        'voided_by',
        'void_reason',
    ];
}
