<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnItem extends Model
{
    protected $fillable = [
        'grn_id',
        'purchase_order_item_id',
        'inventory_item_id',
        'quantity_ordered',
        'quantity_previously_received',
        'quantity_received',
        'quantity_rejected',
        'quantity_accepted',
        'unit_price',
        'tax_rate',
        'line_subtotal_accepted',
        'line_tax_accepted',
        'rejection_reason',
        'rejection_notes',
        'quality_status',
        'expiry_date',
        'batch_number',
        'manufacture_date',
        'storage_condition',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:3',
        'quantity_previously_received' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'quantity_rejected' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'line_subtotal_accepted' => 'decimal:2',
        'line_tax_accepted' => 'decimal:2',
        'expiry_date' => 'date',
        'manufacture_date' => 'date',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GRN::class, 'grn_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
