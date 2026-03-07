<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'name', 'sku', 'description', 'category_id', 'vendor_id', 'tax_id',
        'purchase_uom_id', 'issue_uom_id', 'conversion_factor',
        'cost_price', 'reorder_level', 'current_stock',
    ];

    protected $casts = [
        'cost_price'        => 'float',
        'reorder_level'     => 'integer',
        'current_stock'     => 'integer',
        'conversion_factor' => 'float',
        'tax_id'            => 'integer',
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
}
