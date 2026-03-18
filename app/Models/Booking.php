<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    public function segments()
    {
        return $this->hasMany(BookingSegment::class);
    }
    protected $fillable = [
        'room_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'guest_identity_types',
        'guest_identities',
        'city',
        'country',
        'adults_count',
        'children_count',
        'infants_count',
        'extra_beds_count',
        'check_in',
        'check_out',
        'early_checkin_time',
        'late_checkout_time',
        'estimated_arrival_time',
        'total_price',
        'payment_status',
        'payment_method',
        'deposit_amount',
        'status',
        'booking_source',
        'source_reference',
        'notes',
        'booking_group_id',
        'created_by',
        'adult_breakfast_count',
        'child_breakfast_count',
        'rate_plan_id'
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

    protected $casts = [
        'guest_identities' => 'array',
        'guest_identity_types' => 'array',
    ];
}
