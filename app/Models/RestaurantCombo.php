<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantCombo extends Model
{
    /** Default: sell price includes tax (GST). */
    protected $attributes = [
        'price_tax_inclusive' => true,
    ];

    protected $fillable = [
        'combo_id',
        'restaurant_master_id',
        'price',
        'is_active',
        'price_tax_inclusive',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'price_tax_inclusive' => 'boolean',
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
