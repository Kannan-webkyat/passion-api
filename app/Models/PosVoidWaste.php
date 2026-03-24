<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosVoidWaste extends Model
{
    protected $table = 'pos_void_waste';

    protected $fillable = [
        'pos_order_id', 'pos_order_item_id', 'inventory_item_id', 'inventory_location_id',
        'quantity', 'unit_cost', 'total_cost', 'void_reason', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
