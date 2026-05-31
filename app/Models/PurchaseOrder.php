<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'vendor_id', 'location_id', 'procurement_requisition_id', 'order_date', 'expected_delivery_date',
        'status', 'notes', 'subtotal', 'tax_amount', 'total_cess_amount', 'transportation_charge', 'loading_unloading_charge',
        'total_amount', 'grand_total_payable', 'tds_amount', 'created_by',
        'received_document_path', 'invoice_path', 'payment_status',
        'payment_method', 'payment_reference', 'paid_amount', 'paid_at',
        'received_at',
    ];

    protected $casts = [
        'tds_amount' => 'float',
    ];

    /** Vendor payable total (falls back to legacy total_amount). */
    public function payableAmount(): float
    {
        $grand = (float) ($this->grand_total_payable ?? 0);

        return $grand > 0 ? $grand : (float) $this->total_amount;
    }

    public function procurementRequisition()
    {
        return $this->belongsTo(ProcurementRequisition::class);
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
