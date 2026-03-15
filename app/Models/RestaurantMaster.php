<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMaster extends Model
{
    protected $fillable = ['name', 'floor', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
