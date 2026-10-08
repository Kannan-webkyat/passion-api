<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deleting a room type archives it (`deleted_at`); archived types drop out of every default query.
 */
class RoomType extends Model
{
    use SoftDeletes;

    /**
     * API/clients expect `seasonal_prices`; the relation is `seasons`.
     * @see RoomTypeSeason
     */
    protected $hidden = [
        'seasons',
    ];

    protected $appends = [
        'seasonal_prices',
    ];

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'breakfast_price',
        'child_breakfast_price',
        'adult_meal_price',
        'child_meal_price',
        'adult_lunch_price',
        'child_lunch_price',
        'adult_dinner_price',
        'child_dinner_price',
        'child_age_limit',
        'child_age_from',
        'extra_bed_cost',
        'child_extra_bed_cost',
        'early_check_in_fee',
        'early_check_in_type',
        'early_check_in_buffer_minutes',
        'late_check_out_fee',
        'late_check_out_type',
        'late_check_out_buffer_minutes',
        'base_occupancy',
        'capacity',
        'bedrooms',
        'washrooms',
        'weekday_price',
        'weekend_price',
        'doorloom_property_id',
        'extra_bed_capacity',
        'child_sharing_limit',
        'bed_config',
        'amenities',
        'tax_id',
    ];

    protected $casts = [
        'amenities' => 'array',
        'is_active' => 'boolean',
        'breakfast_price' => 'decimal:2',
        'child_breakfast_price' => 'decimal:2',
        'adult_meal_price' => 'decimal:2',
        'child_meal_price' => 'decimal:2',
        'adult_lunch_price' => 'decimal:2',
        'child_lunch_price' => 'decimal:2',
        'adult_dinner_price' => 'decimal:2',
        'child_dinner_price' => 'decimal:2',
        'early_check_in_fee' => 'decimal:2',
        'late_check_out_fee' => 'decimal:2',
        'bedrooms' => 'integer',
        'washrooms' => 'integer',
        'weekday_price' => 'decimal:2',
        'weekend_price' => 'decimal:2',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function tax()
    {
        return $this->belongsTo(InventoryTax::class, 'tax_id');
    }

    public function ratePlans()
    {
        return $this->hasMany(RatePlan::class);
    }

    public function seasons()
    {
        return $this->hasMany(RoomTypeSeason::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RoomTypeSeason>
     */
    public function getSeasonalPricesAttribute()
    {
        return $this->relationLoaded('seasons')
            ? $this->getRelation('seasons')
            : $this->seasons()->get();
    }
}
