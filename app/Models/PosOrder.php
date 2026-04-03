<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $fillable = [
        'order_type', 'table_id', 'restaurant_id', 'business_date', 'waiter_id', 'opened_by',
        'room_id', 'booking_id', 'customer_name', 'customer_phone', 'customer_gstin', 'delivery_address', 'delivery_channel', 'delivery_charge',
        'covers', 'status', 'kitchen_status', 'current_kot_batch',
        'discount_type', 'discount_value', 'service_charge_type', 'service_charge_value', 'service_charge_amount',
        'subtotal', 'tax_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'vat_tax_amount', 'gst_net_taxable', 'vat_net_taxable', 'discount_amount', 'tip_amount', 'rounding_amount', 'total_amount', 'opened_at', 'closed_at', 'notes', 'tax_exempt', 'prices_tax_inclusive', 'receipt_show_tax_breakdown', 'is_complimentary',
        'void_reason', 'void_notes', 'voided_by', 'voided_at',
        'discount_approved_by', 'discount_approved_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'business_date' => 'date',
        'current_kot_batch' => 'integer',
        'covers' => 'integer',
        'discount_value' => 'decimal:2',
        'service_charge_value' => 'decimal:2',
        'service_charge_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'vat_tax_amount' => 'decimal:2',
        'gst_net_taxable' => 'decimal:2',
        'vat_net_taxable' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'rounding_amount' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_exempt' => 'boolean',
        'prices_tax_inclusive' => 'boolean',
        'receipt_show_tax_breakdown' => 'boolean',
        'is_complimentary' => 'boolean',
        'voided_at' => 'datetime',
        'discount_approved_at' => 'datetime',
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

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function items()
    {
        return $this->hasMany(PosOrderItem::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(PosPayment::class, 'order_id');
    }

    public function refunds()
    {
        return $this->hasMany(PosOrderRefund::class, 'order_id');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function discountApprovedBy()
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }
}
