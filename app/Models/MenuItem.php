<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = [
        'restaurant_master_id', 'item_code', 'name', 'menu_category_id', 'menu_sub_category_id',
        'price', 'fixed_ept', 'type', 'is_active', 'image'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(MenuSubCategory::class, 'menu_sub_category_id');
    }

    public function restaurantMaster()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_master_id');
    }
}
