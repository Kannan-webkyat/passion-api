<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = [
        'menu_item_id', 'yield_quantity', 'yield_uom_id', 'food_cost_target', 'notes', 'is_active',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'yield_quantity'    => 'decimal:3',
        'food_cost_target'  => 'decimal:2',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
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
