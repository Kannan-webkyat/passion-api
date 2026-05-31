<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRoomTransfer extends Model
{
    public const REASONS = [
        'maintenance',
        'ac_issue',
        'noise',
        'guest_complaint',
        'service_recovery',
        'guest_request',
        'operational',
        'other',
    ];

    public const RATE_MODES = [
        'keep_existing',
        'apply_new_category',
    ];

    protected $fillable = [
        'booking_id',
        'booking_segment_id',
        'from_room_id',
        'to_room_id',
        'from_room_type_id',
        'to_room_type_id',
        'transfer_reason',
        'internal_notes',
        'rate_mode',
        'is_complimentary_upgrade',
        'old_total_price',
        'new_total_price',
        'price_delta',
        'segment_price',
        'transferred_at',
        'performed_by',
    ];

    protected $casts = [
        'is_complimentary_upgrade' => 'boolean',
        'old_total_price' => 'decimal:2',
        'new_total_price' => 'decimal:2',
        'price_delta' => 'decimal:2',
        'segment_price' => 'decimal:2',
        'transferred_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(BookingSegment::class, 'booking_segment_id');
    }

    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public static function reasonLabel(string $code): string
    {
        return match ($code) {
            'maintenance' => 'Maintenance issue',
            'ac_issue' => 'AC problem',
            'noise' => 'Noise disturbance',
            'guest_complaint' => 'Guest complaint',
            'service_recovery' => 'Service recovery',
            'guest_request' => 'Guest request',
            'operational' => 'Operational',
            'other' => 'Other',
            default => $code,
        };
    }
}
