<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMenuItem extends Model
{
    /** Default: sell price includes tax (GST). */
    protected $attributes = [
        'price_tax_inclusive' => true,
    ];

    protected $fillable = [
        'menu_item_id',
        'restaurant_master_id',
        'price',
        'fixed_ept',
        'is_active',
        'price_tax_inclusive',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'price_tax_inclusive' => 'boolean',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_master_id');
    }

    public function variantOverrides()
    {
        return $this->hasMany(RestaurantMenuItemVariant::class, 'restaurant_menu_item_id');
    }
}
