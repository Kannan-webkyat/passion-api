<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'base_price',
        'breakfast_price',
        'child_breakfast_price',
        'extra_bed_cost',
        'base_occupancy',
        'capacity',
        'extra_bed_capacity',
        'child_sharing_limit',
        'bed_config',
        'amenities',
        'tax_id',
    ];

    protected $casts = [
        'amenities' => 'array',
        'is_active' => 'boolean',
        'breakfast_price' => 'decimal:2',
        'child_breakfast_price' => 'decimal:2',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function tax()
    {
        return $this->belongsTo(InventoryTax::class, 'tax_id');
    }

    public function ratePlans()
    {
        return $this->hasMany(RatePlan::class);
    }
}
