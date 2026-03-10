<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableCategorie extends Model
{
    protected $fillable = [ 'name', 'capacity', 'description' ];

    public function tables()
    {
        return $this->hasMany(RestaurantTable::class, 'category_id');
    }
}
