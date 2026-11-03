<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaundryJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pickup_on' => 'date:Y-m-d',
        'delivered_on' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
