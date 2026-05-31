<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'kind', 'is_active', 'department_id', 'room_id'];

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

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
