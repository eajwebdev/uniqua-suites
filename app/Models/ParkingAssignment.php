<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function space()
    {
        return $this->belongsTo(ParkingSpace::class, 'parking_space_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
