<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'is_active', 'is_housekeeping'];

    protected $casts = [
        'is_housekeeping' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function locations()
    {
        return $this->hasMany(InventoryLocation::class);
    }
}
