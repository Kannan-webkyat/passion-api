<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    public function segments()
    {
        return $this->hasMany(BookingSegment::class);
    }

    public function roomTransfers()
    {
        return $this->hasMany(BookingRoomTransfer::class)->orderByDesc('transferred_at');
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
        'bill_to_name',
        'guest_gstin',
        'adults_count',
        'children_count',
        'child_ages',
        'infants_count',
        'extra_beds_count',
        'check_in',
        'check_out',
        'check_in_at',
        'check_out_at',
        'booking_unit',
        'early_checkin_time',
        'late_checkout_time',
        'estimated_arrival_time',
        'total_price',
        'payment_status',
        'payment_method',
        'deposit_amount',
        'refund_amount',
        'refund_method',
        'extra_charges',
        'checkout_discount_amount',
        'checkout_discount_reason',
        'status',
        'booking_source',
        'source_reference',
        'notes',
        'booking_group_id',
        'created_by',
        'adult_breakfast_count',
        'child_breakfast_count',
        'rate_plan_id',
    ];

    public function bookingGroup()
    {
        return $this->belongsTo(BookingGroup::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function laundryRequests()
    {
        return $this->hasMany(LaundryRequest::class);
    }

    // Accessor for full name
    public function getGuestNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // Appended attributes
    protected $appends = ['guest_name'];

    protected $casts = [
        'child_ages' => 'array',
        'guest_identities' => 'array',
        'guest_identity_types' => 'array',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'checkout_discount_amount' => 'decimal:2',
    ];
}
