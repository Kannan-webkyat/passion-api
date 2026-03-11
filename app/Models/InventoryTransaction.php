<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'inventory_item_id', 'inventory_location_id', 'department_id', 'type', 'quantity', 'department', 'reason', 'notes', 'user_id',
        'reference_id', 'reference_type',
    ];

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
