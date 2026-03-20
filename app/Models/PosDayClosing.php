<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayClosing extends Model
{
    protected $fillable = [
        'restaurant_id', 'closed_date', 'closed_at', 'closed_by',
        'opening_balance', 'closing_balance',
        'total_sales', 'total_discount', 'total_tax', 'total_service_charge', 'total_tip', 'total_paid',
        'cash_total', 'card_total', 'upi_total', 'room_charge_total',
        'order_count', 'void_count', 'notes',
    ];

    protected $casts = [
        'closed_date'      => 'date',
        'closed_at'       => 'datetime',
        'opening_balance'  => 'decimal:2',
        'closing_balance'  => 'decimal:2',
        'total_sales'            => 'decimal:2',
        'total_discount'         => 'decimal:2',
        'total_tax'              => 'decimal:2',
        'total_service_charge'   => 'decimal:2',
        'total_tip'              => 'decimal:2',
        'total_paid'             => 'decimal:2',
        'cash_total'       => 'decimal:2',
        'card_total'       => 'decimal:2',
        'upi_total'        => 'decimal:2',
        'room_charge_total' => 'decimal:2',
    ];

    public function restaurant()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_id');
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
