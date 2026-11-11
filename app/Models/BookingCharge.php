<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCharge extends Model
{
    /** Parking on a stall borrowed from another condo owner: the guest pays it, but it is owed to that owner. */
    public const PARKING_REMIT = 'parking_remit';

    /** Money the guest pays that is not UNIQA's sale: taxes and parking to be remitted. Reports leave these out of revenue. */
    public const NOT_SALES = ['tax', self::PARKING_REMIT];

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
