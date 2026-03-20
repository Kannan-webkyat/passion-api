<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'menu_item_id', 'combo_id', 'quantity', 'unit_price',
        'tax_rate', 'line_total', 'kot_sent', 'status', 'kot_batch', 'kot_started_at', 'kitchen_ready_at', 'kitchen_served_at', 'notes',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'tax_rate'         => 'decimal:2',
        'line_total'       => 'decimal:2',
        'kot_sent'         => 'boolean',
        'kot_started_at'   => 'datetime',
        'kitchen_ready_at'  => 'datetime',
        'kitchen_served_at' => 'datetime',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }
}
