<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    protected $fillable = ['name', 'description', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(InventoryCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(InventoryCategory::class, 'parent_id');
    }

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'category_id');
    }
}
