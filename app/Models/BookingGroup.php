<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingGroup extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'status', 'notes'];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
