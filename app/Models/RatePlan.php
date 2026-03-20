<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    protected $fillable = [
        'room_type_id',
        'name',
        'billing_unit',
        'base_price',
        'package_hours',
        'package_price',
        'grace_minutes',
        'overtime_step_minutes',
        'overtime_hour_price',
        'includes_breakfast',
        'is_active',
        'price_modifiers',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'package_price' => 'decimal:2',
        'overtime_hour_price' => 'decimal:2',
        'includes_breakfast' => 'boolean',
        'is_active' => 'boolean',
        'price_modifiers' => 'array',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
