<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantCombo extends Model
{
    protected $fillable = [
        'combo_id',
        'restaurant_master_id',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_master_id');
    }
}
