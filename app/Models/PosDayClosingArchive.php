<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayClosingArchive extends Model
{
    protected $fillable = [
        'original_id', 'restaurant_id', 'closed_date', 'closed_at', 'closed_by',
        'snapshot', 'unlocked_by', 'unlocked_at', 'unlock_reason',
    ];

    protected $casts = [
        'closed_date' => 'date',
        'closed_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function restaurant()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_id');
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function unlockedByUser()
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
