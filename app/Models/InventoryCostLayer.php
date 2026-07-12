<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostLayer extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'inventory_location_id',
        'source_type',
        'source_id',
        'grn_item_id',
        'inventory_transaction_id',
        'quantity_received',
        'quantity_remaining',
        'landed_unit_cost',
        'merchandise_unit_cost',
        'cess_unit_cost',
        'freight_unit_cost',
        'non_recoverable_tax_unit_cost',
        'inventory_costing_mode',
        'received_at',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'landed_unit_cost' => 'decimal:4',
        'merchandise_unit_cost' => 'decimal:4',
        'cess_unit_cost' => 'decimal:4',
        'freight_unit_cost' => 'decimal:4',
        'non_recoverable_tax_unit_cost' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }
}
