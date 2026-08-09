<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMaster extends Model
{
    protected $fillable = [
        'name', 'floor', 'description', 'is_active', 'department_id', 'kitchen_location_id', 'bar_location_id', 'business_day_cutoff_time',
        'receipt_show_tax_breakdown',
        'auto_print_kot',
        'auto_print_bot',
        'kot_ticket_label',
        'auto_print_payment_receipt',
        'kot_include_all_items',
        'default_packing_charge',
        'address', 'email', 'phone', 'gstin', 'fssai', 'sac_code', 'logo_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'receipt_show_tax_breakdown' => 'boolean',
        'auto_print_kot' => 'boolean',
        'auto_print_bot' => 'boolean',
        'auto_print_payment_receipt' => 'boolean',
        'kot_include_all_items' => 'boolean',
        'default_packing_charge' => 'decimal:2',
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
