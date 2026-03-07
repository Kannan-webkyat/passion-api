<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTax extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'rate', 'type'];

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'tax_id');
    }
}
