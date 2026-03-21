<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $fillable = [
        'recipe_id', 'inventory_item_id', 'uom_id', 'quantity', 'yield_percentage', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'yield_percentage' => 'decimal:2',
    ];

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function uom()
    {
        return $this->belongsTo(InventoryUom::class, 'uom_id');
    }

    /**
     * Raw quantity to deduct from stock (accounts for yield/waste).
     */
    public function getRawQuantityAttribute(): float
    {
        return $this->yield_percentage > 0
            ? $this->quantity / ($this->yield_percentage / 100)
            : $this->quantity;
    }

    /**
     * Line cost = raw_quantity × ingredient cost_price.
     */
    public function getLineCostAttribute(): float
    {
        $unitPrice = floatval($this->inventoryItem->cost_price ?? 0);

        return $this->raw_quantity * $unitPrice;
    }
}
