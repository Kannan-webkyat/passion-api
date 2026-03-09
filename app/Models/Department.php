<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\InventoryLocation;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function locations()
    {
        return $this->hasMany(InventoryLocation::class);
    }
}
