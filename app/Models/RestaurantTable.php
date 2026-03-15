<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $fillable = [
        'table_number',
        'restaurant_master_id',
        'category_id',
        'capacity',
        'status',
        'location',
        'notes',
    ];

    public function category()
    {
        return $this->belongsTo(TableCategory::class, 'category_id');
    }

    public function restaurantMaster()
    {
        return $this->belongsTo(RestaurantMaster::class);
    }

    public function reservations()
    {
        return $this->hasMany(TableReservation::class, 'table_id');
    }
}
