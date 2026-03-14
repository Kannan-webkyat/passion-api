<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionLog extends Model
{
    protected $fillable = [
        'recipe_id', 'inventory_location_id', 'quantity_produced', 'unit_cost', 'total_cost',
        'produced_by', 'production_date', 'notes', 'reference_id',
    ];

    protected $casts = [
        'quantity_produced' => 'decimal:3',
        'production_date'   => 'datetime',
    ];

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function producer()
    {
        return $this->belongsTo(User::class, 'produced_by');
    }
}
