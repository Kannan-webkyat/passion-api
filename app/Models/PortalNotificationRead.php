<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalNotificationRead extends Model
{
    protected $fillable = [
        'portal_notification_id',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(PortalNotification::class, 'portal_notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
