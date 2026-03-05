<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address'];

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'vendor_id');
    }
}
