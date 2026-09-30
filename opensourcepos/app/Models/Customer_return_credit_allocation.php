<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_return_credit_allocation extends Model
{
    protected $table = 'customer_return_credit_allocations';
    protected $primaryKey = 'allocation_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'return_credit_id',
        'sale_id',
        'amount',
        'status',
    ];
}
