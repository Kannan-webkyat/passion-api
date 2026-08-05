<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'grn_id',
        'user_id',
        'action',
        'old_status',
        'new_status',
        'payload',
        'remarks',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GRN::class, 'grn_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
