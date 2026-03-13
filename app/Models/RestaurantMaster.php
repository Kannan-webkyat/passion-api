<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RestaurantMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'floor',
        'description',
        'is_active',
    ];
}
