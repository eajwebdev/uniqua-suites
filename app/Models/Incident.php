<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discovered_on' => 'date:Y-m-d',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function charge()
    {
        return $this->belongsTo(BookingCharge::class, 'charge_id');
    }

    public function evidence()
    {
        return $this->hasMany(IncidentEvidence::class);
    }
}
