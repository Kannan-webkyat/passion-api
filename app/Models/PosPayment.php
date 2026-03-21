<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPayment extends Model
{
    protected $fillable = [
        'order_id', 'method', 'amount', 'reference_no', 'paid_at', 'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
