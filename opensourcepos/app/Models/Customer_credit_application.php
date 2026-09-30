<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_credit_application extends Model
{
    protected $table = 'customer_credit_applications';
    protected $primaryKey = 'application_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'sale_id',
        'amount',
        'status',
        'employee_id',
        'idempotency_key',
        'comment',
        'voided_at',
        'voided_by',
    ];
}
