<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function source()
    {
        return $this->belongsTo(BookingSource::class, 'booking_source_id');
    }
}
