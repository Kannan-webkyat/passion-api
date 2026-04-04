<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProcurementRequisitionItem extends Model
{
    protected $fillable = [
        'procurement_requisition_id', 'inventory_item_id', 'quantity',
        'winning_unit_price', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'float',
        'winning_unit_price' => 'float',
    ];

    public function procurementRequisition(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequisition::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(
            Vendor::class,
            'procurement_requisition_item_vendors',
            'procurement_requisition_item_id',
            'vendor_id'
        )->withTimestamps();
    }
}
