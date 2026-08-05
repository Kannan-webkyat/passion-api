<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GRN extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const REJECTION_REASONS = [
        'bottle_damaged' => 'Bottle damaged',
        'seal_broken' => 'Seal broken',
        'short_supply' => 'Short supply',
        'wrong_item' => 'Wrong item',
        'expired' => 'Expired',
        'packaging_damage' => 'Packaging damage',
    ];

    /** @var array<string, string> */
    public const QUALITY_STATUSES = [
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'partial_acceptance' => 'Partial acceptance',
    ];

    /** @var array<string, string> */
    public const DOCUMENT_TYPES = [
        'delivery_note' => 'Supplier delivery note',
        'supplier_invoice' => 'Supplier invoice',
        'transport_document' => 'Transport document',
        'photo' => 'Photo (evidence)',
    ];

    protected $table = 'grns';

    protected $fillable = [
        'grn_number',
        'purchase_order_id',
        'vendor_id',
        'inventory_location_id',
        'received_date',
        'delivery_note_number',
        'supplier_invoice_number',
        'invoice_date',
        'payment_due_date',
        'currency',
        'exchange_rate',
        'status',
        'allow_over_receive',
        'notes',
        'received_by',
        'created_by',
        'submitted_by',
        'submitted_at',
        'inspected_by',
        'inspected_at',
        'approved_by',
        'approved_at',
        'inventory_costing_mode',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'document_path',
    ];

    protected $casts = [
        'received_date' => 'date',
        'invoice_date' => 'date',
        'payment_due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'allow_over_receive' => 'boolean',
        'submitted_at' => 'datetime',
        'inspected_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class, 'grn_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GrnAttachment::class, 'grn_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(GrnAuditLog::class, 'grn_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Approved and cancelled GRNs are immutable — corrections via adjustment or reversal GRN. */
    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_CANCELLED], true);
    }

    public function allowsAttachments(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
        ], true);
    }

    public function isInspectable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->inspected_at === null;
    }

    public function isApprovable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->inspected_at !== null;
    }
}
