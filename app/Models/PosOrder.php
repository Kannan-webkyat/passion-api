<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $fillable = [
        'table_id', 'restaurant_id', 'waiter_id', 'covers', 'status', 'kitchen_status',
        'discount_type', 'discount_value', 'subtotal', 'tax_amount',
        'discount_amount', 'total_amount', 'opened_at', 'closed_at', 'notes',
    ];

    protected $casts = [
        'opened_at'      => 'datetime',
        'closed_at'      => 'datetime',
        'discount_value' => 'decimal:2',
        'subtotal'       => 'decimal:2',
        'tax_amount'     => 'decimal:2',
        'discount_amount'=> 'decimal:2',
        'total_amount'   => 'decimal:2',
    ];

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(RestaurantMaster::class, 'restaurant_id');
    }

    public function waiter()
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function items()
    {
        return $this->hasMany(PosOrderItem::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(PosPayment::class, 'order_id');
    }
}
