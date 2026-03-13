<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $fillable = [
        'restaurant_master_id',
        'table_number',
        'category_id',
        'capacity',
        'status',
        'location',
        'notes'
    ];

    public function category()
    {
        return $this->belongsTo(TableCategorie::class, 'category_id');
    }

    public function restaurantMaster()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_master_id');
    }
}
