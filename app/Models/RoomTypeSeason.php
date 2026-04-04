<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomTypeSeason extends Model
{
    protected $fillable = [
        'room_type_id',
        'season_name',
        'start_date',
        'end_date',
        'adjustment_type',
        'price_adjustment',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'price_adjustment' => 'decimal:2',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
