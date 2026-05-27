<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CessSlab extends Model
{
    protected $fillable = [
        'item_category',
        'min_mrp',
        'max_mrp',
        'flat_cess_amount',
        'is_active',
    ];

    protected $casts = [
        'min_mrp' => 'float',
        'max_mrp' => 'float',
        'flat_cess_amount' => 'float',
        'is_active' => 'boolean',
    ];
}
