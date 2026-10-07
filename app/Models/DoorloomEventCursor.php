<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoorloomEventCursor extends Model
{
    protected $fillable = [
        'doorloom_property_id',
        'event_type',
        'last_sequence',
    ];
}
