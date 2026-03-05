<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'vendor_id', 'order_date', 'expected_delivery_date',
        'status', 'notes', 'total_amount', 'created_by',
        'received_document_path', 'invoice_path', 'payment_status',
        'payment_method', 'payment_reference', 'paid_amount', 'paid_at',
        'received_at',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
