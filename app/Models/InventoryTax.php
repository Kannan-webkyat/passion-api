<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax master for menu / inventory.
 *
 * `type` drives POS behaviour:
 * - local: intra-state GST → CGST + SGST (combined rate split 50/50 on the bill)
 * - inter-state: IGST at full rate
 * - vat: state liquor / non-GST VAT (separate from CGST/SGST)
 */
class InventoryTax extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'rate', 'type'];

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'tax_id');
    }
}
