<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'room_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'adults_count',
        'children_count',
        'infants_count',
        'extra_beds_count',
        'check_in',
        'check_out',
        'total_price',
        'payment_status',
        'payment_method',
        'deposit_amount',
        'status',
        'booking_source',
        'notes',
        'booking_group_id',
        'created_by'
    ];

    public function bookingGroup()
    {
        return $this->belongsTo(BookingGroup::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accessor for full name
    public function getGuestNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // Appended attributes
    protected $appends = ['guest_name'];
}
