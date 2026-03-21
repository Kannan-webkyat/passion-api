<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryItem extends Model
{
    protected $fillable = [
        'name', 'sku', 'description', 'category_id', 'vendor_id', 'tax_id',
        'purchase_uom_id', 'issue_uom_id', 'conversion_factor',
        'cost_price', 'reorder_level', 'current_stock', 'is_direct_sale',
    ];

    protected $casts = [
        'cost_price' => 'float',
        'reorder_level' => 'integer',
        'current_stock' => 'integer',
        'conversion_factor' => 'float',
        'tax_id' => 'integer',
        'is_direct_sale' => 'boolean',
    ];

    public function tax()
    {
        return $this->belongsTo(InventoryTax::class, 'tax_id');
    }

    public function category()
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function purchaseUom()
    {
        return $this->belongsTo(InventoryUom::class, 'purchase_uom_id');
    }

    public function issueUom()
    {
        return $this->belongsTo(InventoryUom::class, 'issue_uom_id');
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function locations()
    {
        return $this->belongsToMany(InventoryLocation::class, 'inventory_item_locations')
            ->withPivot('quantity', 'reorder_level')
            ->withTimestamps();
    }

    /**
     * Source of truth for on-hand quantity: sum of all location rows (Option B).
     */
    public static function sumQuantityAcrossLocations(int $inventoryItemId): float
    {
        return (float) DB::table('inventory_item_locations')
            ->where('inventory_item_id', $inventoryItemId)
            ->sum('quantity');
    }

    /**
     * Persist inventory_items.current_stock to match sum of locations (keeps legacy column aligned).
     */
    public static function syncStoredCurrentStockFromLocations(int $inventoryItemId): void
    {
        $sum = self::sumQuantityAcrossLocations($inventoryItemId);
        self::where('id', $inventoryItemId)->update([
            'current_stock' => (int) round($sum),
        ]);
    }

    public function refreshStoredStockFromLocations(): void
    {
        self::syncStoredCurrentStockFromLocations($this->id);
        $this->refresh();
    }
}
