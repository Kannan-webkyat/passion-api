<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderRefund extends Model
{
    protected $fillable = [
        'order_id', 'amount', 'method', 'reference_no', 'reason',
        'refunded_at', 'refunded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
