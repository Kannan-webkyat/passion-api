<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMenuItemVariant extends Model
{
    protected $fillable = [
        'restaurant_menu_item_id', 'menu_item_variant_id', 'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function restaurantMenuItem()
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'restaurant_menu_item_id');
    }

    public function variant()
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }
}
