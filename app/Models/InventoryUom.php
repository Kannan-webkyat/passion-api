<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryUom extends Model
{
    protected $fillable = ['name', 'short_name'];

    public function purchaseItems()
    {
        return $this->hasMany(InventoryItem::class, 'purchase_uom_id');
    }

    public function issueItems()
    {
        return $this->hasMany(InventoryItem::class, 'issue_uom_id');
    }
}
