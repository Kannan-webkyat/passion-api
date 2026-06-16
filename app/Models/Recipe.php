<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    public const KIND_MENU_ITEM = 'menu_item';

    public const KIND_SEMI_FINISHED = 'semi_finished';

    protected $fillable = [
        'menu_item_id',
        'output_inventory_item_id',
        'recipe_kind',
        'yield_quantity',
        'yield_uom_id',
        'food_cost_target',
        'notes',
        'is_active',
        'requires_production',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_production' => 'boolean',
        'yield_quantity' => 'decimal:3',
        'food_cost_target' => 'decimal:2',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function outputInventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'output_inventory_item_id');
    }

    public function yieldUom()
    {
        return $this->belongsTo(InventoryUom::class, 'yield_uom_id');
    }

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function productionLogs()
    {
        return $this->hasMany(ProductionLog::class);
    }

    public function isSemiFinished(): bool
    {
        return $this->recipe_kind === self::KIND_SEMI_FINISHED;
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->isSemiFinished()) {
            return $this->outputInventoryItem?->name ?? 'Semi-finished item';
        }

        return $this->menuItem?->name ?? 'Unknown';
    }

    /**
     * Inventory item that receives stock when this recipe is produced.
     */
    public function resolveOutputInventoryItemId(): ?int
    {
        if ($this->output_inventory_item_id) {
            return (int) $this->output_inventory_item_id;
        }

        return $this->menuItem?->inventory_item_id
            ? (int) $this->menuItem->inventory_item_id
            : null;
    }

    /**
     * Calculate total raw material cost for one batch (yield_quantity portions).
     */
    public function getTotalCostAttribute(): float
    {
        return $this->ingredients->sum('line_cost');
    }

    /**
     * Cost per portion.
     */
    public function getCostPerPortionAttribute(): float
    {
        return $this->yield_quantity > 0
            ? $this->total_cost / $this->yield_quantity
            : 0;
    }
}
