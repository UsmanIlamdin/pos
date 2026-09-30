<?php

namespace App\Models;

use CodeIgniter\Model;

class Consolidated_invoice_sale extends Model
{
    protected $table = 'consolidated_invoice_sales';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'consolidated_invoice_id',
        'sale_id',
        'amount',
    ];
}
