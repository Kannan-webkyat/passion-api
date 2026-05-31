<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HousekeepingJobLine extends Model
{
    protected $fillable = [
        'housekeeping_job_id',
        'kind',
        'inventory_item_id',
        'menu_item_id',
        'qty',
        'meta',
    ];

    protected $casts = [
        'qty' => 'float',
        'meta' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(HousekeepingJob::class, 'housekeeping_job_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
