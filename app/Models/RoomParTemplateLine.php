<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomParTemplateLine extends Model
{
    protected $fillable = [
        'template_id',
        'kind',
        'inventory_item_id',
        'par_qty',
        'meta',
    ];

    protected $casts = [
        'par_qty' => 'float',
        'meta' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(RoomParTemplate::class, 'template_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
