<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPayment extends Model
{
    public const TYPE_PAYMENT = 'payment';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'booking_id',
        'type',
        'amount',
        'method',
        'reference_no',
        'notes',
        'source',
        'meta',
        'paid_at',
        'received_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'meta' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    /** Signed cash effect: payment +, refund −, adjustment from meta.signed_amount or ±amount. */
    public function signedAmount(): float
    {
        $amt = abs((float) $this->amount);
        return match ($this->type) {
            self::TYPE_PAYMENT => $amt,
            self::TYPE_REFUND => -$amt,
            self::TYPE_ADJUSTMENT => (float) ($this->meta['signed_amount'] ?? $amt),
            default => 0.0,
        };
    }
}
