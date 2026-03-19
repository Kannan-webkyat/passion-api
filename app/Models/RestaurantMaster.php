<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMaster extends Model
{
    protected $fillable = [
        'name', 'floor', 'description', 'is_active',
        'address', 'email', 'phone', 'gstin', 'fssai', 'logo_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tables()
    {
        return $this->hasMany(RestaurantTable::class, 'restaurant_master_id');
    }

    public function restaurantMenuItems()
    {
        return $this->hasMany(RestaurantMenuItem::class, 'restaurant_master_id');
    }

    public function menuItems()
    {
        return $this->belongsToMany(MenuItem::class, 'restaurant_menu_items', 'restaurant_master_id', 'menu_item_id')
            ->withPivot(['price', 'fixed_ept', 'is_active'])
            ->withTimestamps();
    }
}
