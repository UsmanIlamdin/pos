<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_payment_allocation extends Model
{
    protected $table = 'customer_payment_allocations';
    protected $primaryKey = 'allocation_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_account_payment_id',
        'sale_id',
        'amount',
        'status',
    ];
}
