<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryRequestLine extends Model
{
    protected $fillable = [
        'laundry_request_id',
        'item_type',
        'service_type',
        'qty',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function laundryRequest(): BelongsTo
    {
        return $this->belongsTo(LaundryRequest::class);
    }
}
