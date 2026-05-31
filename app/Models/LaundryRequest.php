<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaundryRequest extends Model
{
    public const STATUS_PENDING_PICKUP = 'pending_pickup';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'booking_id',
        'room_id',
        'guest_name',
        'pickup_at',
        'notes',
        'damage_notes',
        'express',
        'express_surcharge_amount',
        'status',
        'pickup_items',
        'posted_at',
        'posted_amount',
        'picked_up_at',
        'ready_at',
        'delivered_at',
        'created_by',
    ];

    protected $casts = [
        'pickup_at' => 'datetime',
        'express' => 'boolean',
        'express_surcharge_amount' => 'decimal:2',
        'pickup_items' => 'array',
        'posted_at' => 'datetime',
        'posted_amount' => 'decimal:2',
        'picked_up_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LaundryRequestLine::class);
    }
}
