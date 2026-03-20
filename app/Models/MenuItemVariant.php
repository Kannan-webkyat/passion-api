<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItemVariant extends Model
{
    protected $fillable = [
        'menu_item_id', 'size_label', 'price', 'ml_quantity', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'ml_quantity' => 'decimal:2',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function restaurantOverrides()
    {
        return $this->hasMany(RestaurantMenuItemVariant::class, 'menu_item_variant_id');
    }
}
