<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPaymentAmendment extends Model
{
    protected $fillable = [
        'pos_order_id',
        'previous_payments',
        'new_payments',
        'reason',
        'amended_by',
        'amended_at',
    ];

    protected $casts = [
        'previous_payments' => 'array',
        'new_payments' => 'array',
        'amended_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function amendedBy()
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
