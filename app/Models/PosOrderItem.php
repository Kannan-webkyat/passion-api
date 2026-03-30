<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'menu_item_id', 'menu_item_variant_id', 'combo_id', 'quantity', 'unit_price',
        'tax_rate', 'price_tax_inclusive', 'line_total', 'kot_sent', 'kot_hold', 'status', 'kot_batch', 'kot_started_at', 'kitchen_ready_at', 'kitchen_served_at', 'notes', 'inventory_deducted',
        'cancel_reason', 'cancel_notes', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'price_tax_inclusive' => 'boolean',
        'line_total' => 'decimal:2',
        'kot_sent' => 'boolean',
        'kot_hold' => 'boolean',
        'kot_started_at' => 'datetime',
        'kitchen_ready_at' => 'datetime',
        'kitchen_served_at' => 'datetime',
        'inventory_deducted' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function variant()
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'order_id');
    }
}
