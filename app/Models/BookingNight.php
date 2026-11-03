<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingNight extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stay_date' => 'date:Y-m-d',
        'is_current' => 'boolean',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
