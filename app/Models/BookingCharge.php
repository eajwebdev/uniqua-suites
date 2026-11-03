<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCharge extends Model
{
    protected $guarded = [];

    protected $casts = [
        'charge_date' => 'date:Y-m-d',
        'voided_at' => 'datetime',
        'quantity' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
