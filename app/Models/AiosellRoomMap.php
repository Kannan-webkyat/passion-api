<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiosellRoomMap extends Model
{
    protected $fillable = [
        'room_type_id',
        'room_code',
        'room_name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
