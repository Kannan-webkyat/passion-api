<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'name', 'sku', 'description', 'category_id', 'vendor_id',
        'unit_of_measure', 'cost_price', 'reorder_level', 'current_stock',
    ];

    protected $casts = [
        'cost_price'    => 'float',
        'reorder_level' => 'integer',
        'current_stock' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
