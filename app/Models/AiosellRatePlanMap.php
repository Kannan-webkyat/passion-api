<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiosellRatePlanMap extends Model
{
    protected $fillable = [
        'room_type_id',
        'rate_plan_id',
        'room_code',
        'rateplan_code',
        'occupancy_letter',
        'meal_code',
        'rateplan_name',
        'price_override',
        'active',
    ];

    protected $casts = [
        'price_override' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }
}
