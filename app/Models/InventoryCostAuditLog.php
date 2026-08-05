<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostAuditLog extends Model
{
    protected $table = 'inventory_cost_audit_log';

    protected $fillable = [
        'inventory_item_id',
        'inventory_location_id',
        'event_type',
        'source_type',
        'source_id',
        'inventory_transaction_id',
        'inventory_cost_layer_id',
        'quantity_delta',
        'unit_cost',
        'total_cost',
        'wac_before',
        'wac_after',
        'stock_before',
        'stock_after',
        'cost_breakdown',
        'meta',
        'user_id',
        'occurred_at',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'wac_before' => 'decimal:4',
        'wac_after' => 'decimal:4',
        'stock_before' => 'decimal:4',
        'stock_after' => 'decimal:4',
        'cost_breakdown' => 'array',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryCostLayer::class, 'inventory_cost_layer_id');
    }
}
