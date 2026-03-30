<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMaster extends Model
{
    protected $fillable = [
        'name', 'floor', 'description', 'is_active', 'department_id', 'kitchen_location_id', 'bar_location_id', 'business_day_cutoff_time',
        'bill_round_to_nearest_rupee',
        'receipt_show_tax_breakdown',
        'address', 'email', 'phone', 'gstin', 'fssai', 'logo_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'bill_round_to_nearest_rupee' => 'boolean',
        'receipt_show_tax_breakdown' => 'boolean',
    ];

    public function tables()
    {
        return $this->hasMany(RestaurantTable::class, 'restaurant_master_id');
    }

    public function restaurantMenuItems()
    {
        return $this->hasMany(RestaurantMenuItem::class, 'restaurant_master_id');
    }

    public function restaurantCombos()
    {
        return $this->hasMany(RestaurantCombo::class, 'restaurant_master_id');
    }

    public function menuItems()
    {
        return $this->belongsToMany(MenuItem::class, 'restaurant_menu_items', 'restaurant_master_id', 'menu_item_id')
            ->withPivot(['price', 'fixed_ept', 'is_active'])
            ->withTimestamps();
    }

    public function kitchenLocation()
    {
        return $this->belongsTo(\App\Models\InventoryLocation::class, 'kitchen_location_id');
    }

    public function department()
    {
        return $this->belongsTo(\App\Models\Department::class, 'department_id');
    }

    public function barLocation()
    {
        return $this->belongsTo(\App\Models\InventoryLocation::class, 'bar_location_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'restaurant_user', 'restaurant_master_id', 'user_id')
            ->withTimestamps();
    }
}
