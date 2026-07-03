<?php

namespace App\Models;

use App\Services\GrnItemCostSnapshot;
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
        'line_cess_accepted',
        'line_freight_allocated',
        'merchandise_unit_cost',
        'cess_unit_cost',
        'freight_unit_cost',
        'landed_unit_cost',
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
        'line_cess_accepted' => 'decimal:2',
        'line_freight_allocated' => 'decimal:2',
        'merchandise_unit_cost' => 'decimal:4',
        'cess_unit_cost' => 'decimal:4',
        'freight_unit_cost' => 'decimal:4',
        'landed_unit_cost' => 'decimal:4',
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

    /** Read-only frozen cost for reports/UI — never read PO or inventory_items.cost_price. */
    public function frozenCostSnapshot(?string $grnCostingMode = null): array
    {
        if ($grnCostingMode === null) {
            $this->loadMissing('grn');
            $grnCostingMode = $this->grn?->inventory_costing_mode;
        }

        return GrnItemCostSnapshot::fromGrnItem($this, $grnCostingMode);
    }
}
