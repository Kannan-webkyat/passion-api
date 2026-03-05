<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = ['name', 'description', 'base_price', 'extra_bed_cost', 'base_occupancy', 'capacity', 'extra_bed_capacity', 'child_sharing_limit', 'bed_config', 'amenities'];

    protected $casts = [
        'amenities' => 'array',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
