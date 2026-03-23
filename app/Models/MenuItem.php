<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = [
        'item_code', 'name', 'menu_category_id', 'menu_sub_category_id',
        'price', 'tax_id', 'fixed_ept', 'type', 'is_active', 'is_direct_sale',
        'requires_production', 'image', 'inventory_item_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_direct_sale' => 'boolean',
        'requires_production' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function tax()
    {
        return $this->belongsTo(\App\Models\InventoryTax::class , 'tax_id');
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class , 'menu_category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(MenuSubCategory::class , 'menu_sub_category_id');
    }

    public function recipe()
    {
        return $this->hasOne(Recipe::class);
    }

    public function restaurantMenuItems()
    {
        return $this->hasMany(RestaurantMenuItem::class);
    }

    public function variants()
    {
        return $this->hasMany(MenuItemVariant::class)->orderBy('sort_order');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(\App\Models\InventoryItem::class , 'inventory_item_id');
    }

    public function restaurants()
    {
        return $this->belongsToMany(RestaurantMaster::class , 'restaurant_menu_items', 'menu_item_id', 'restaurant_master_id')
            ->withPivot(['price', 'fixed_ept', 'is_active'])
            ->withTimestamps();
    }
}