<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'menu_item_id', 'quantity', 'unit_price',
        'tax_rate', 'line_total', 'kot_sent', 'status', 'kot_batch', 'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate'   => 'decimal:2',
        'line_total' => 'decimal:2',
        'kot_sent'   => 'boolean',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }
}
