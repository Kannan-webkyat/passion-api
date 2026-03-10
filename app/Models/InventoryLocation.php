<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'is_active', 'department_id'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function items()
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_item_locations')
                    ->withPivot('quantity', 'reorder_level')
                    ->withTimestamps();
    }
}
