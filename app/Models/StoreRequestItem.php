<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_request_id', 'inventory_item_id',
        'quantity_requested', 'quantity_issued',
    ];

    public function storeRequest()
    {
        return $this->belongsTo(StoreRequest::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
