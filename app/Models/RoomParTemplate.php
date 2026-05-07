<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\RoomParTemplateLine;

class RoomParTemplate extends Model
{
    protected $fillable = [
        'room_type_id',
        'name',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function lines()
    {
        return $this->hasMany(RoomParTemplateLine::class, 'template_id');
    }
}
