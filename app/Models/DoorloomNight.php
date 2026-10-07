<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoorloomNight extends Model
{
    protected $fillable = [
        'doorloom_property_id',
        'room_type_id',
        'night_date',
        'base_price',
        'is_weekend',
        'price_source',
        'base_guests',
        'extra_adult_price',
        'extra_child_price',
        'currency',
        'gst_applicable',
        'price_includes_gst',
        'meal_plans',
        'available_units',
        'total_units',
        'is_blocked',
        'block_scope',
        'stop_sell',
        'min_nights',
        'max_nights',
    ];

    protected $casts = [
        'night_date' => 'date:Y-m-d',
        'base_price' => 'decimal:2',
        'is_weekend' => 'boolean',
        'extra_adult_price' => 'decimal:2',
        'extra_child_price' => 'decimal:2',
        'gst_applicable' => 'boolean',
        'price_includes_gst' => 'boolean',
        'meal_plans' => 'array',
        'is_blocked' => 'boolean',
        'stop_sell' => 'boolean',
    ];
}
