<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnAttachment extends Model
{
    public const TYPE_DELIVERY_NOTE = 'delivery_note';

    public const TYPE_SUPPLIER_INVOICE = 'supplier_invoice';

    public const TYPE_TRANSPORT_DOCUMENT = 'transport_document';

    public const TYPE_PHOTO = 'photo';

    protected $fillable = [
        'grn_id',
        'document_type',
        'file_path',
        'original_filename',
        'notes',
        'uploaded_by',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GRN::class, 'grn_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
