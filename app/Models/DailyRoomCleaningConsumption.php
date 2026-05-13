<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyRoomCleaningConsumption extends Model
{
    protected $fillable = [
        'daily_room_cleaning_id',
        'inventory_item_id',
        'qty',
        'notes',
        'recorded_by',
    ];

    public function dailyRoomCleaning(): BelongsTo
    {
        return $this->belongsTo(DailyRoomCleaning::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
