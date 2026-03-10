<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableReservation extends Model
{
    protected $fillable = [
        'table_id',
        'guest_name',
        'guest_phone',
        'guest_email',
        'party_size',
        'reservation_date',
        'reservation_time',
        'status',
        'checked_in_at',
        'special_requests',
        'notes',
    ];

    protected $casts = [
        'reservation_date' => 'date',
        'checked_in_at'    => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
