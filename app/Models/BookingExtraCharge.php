<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingExtraCharge extends Model
{
    protected $table = 'booking_extra_charges';

    protected $fillable = [
        'booking_id',
        'source',
        'kind',
        'label',
        'description',
        'qty',
        'unit_amount',
        'total_amount',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'qty' => 'decimal:2',
        'unit_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
