<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = [
        'name', 'contact_person', 'phone', 'email', 'address',
        'gstin', 'pan', 'state', 'is_registered_dealer',
        'default_tax_price_basis',
    ];

    protected $casts = [
        'is_registered_dealer' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'vendor_id');
    }
}
