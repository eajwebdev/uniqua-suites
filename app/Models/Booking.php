<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    public const INQUIRY = 'inquiry';
    public const TENTATIVE = 'tentative';
    public const CONFIRMED = 'confirmed';
    public const CHECKED_IN = 'checked_in';
    public const CHECKED_OUT = 'checked_out';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW = 'no_show';
    public const EXPIRED = 'expired_hold';

    protected $guarded = [];

    protected $casts = [
        'check_in' => 'date:Y-m-d',
        'check_out' => 'date:Y-m-d',
        'hold_expires_at' => 'datetime',
        'actual_check_in_at' => 'datetime',
        'actual_check_out_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function source()
    {
        return $this->belongsTo(BookingSource::class, 'booking_source_id');
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function activeRooms()
    {
        return $this->hasMany(BookingRoom::class)->whereNull('released_at');
    }

    public function nights()
    {
        return $this->hasMany(BookingNight::class);
    }

    public function charges()
    {
        return $this->hasMany(BookingCharge::class);
    }

    public function activeCharges()
    {
        return $this->hasMany(BookingCharge::class)->whereNull('voided_at');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function settlements()
    {
        return $this->hasMany(ExternalSettlement::class);
    }

    public function statusEvents()
    {
        return $this->hasMany(BookingStatusEvent::class)->orderByDesc('id');
    }

    public function snapshots()
    {
        return $this->hasMany(PricingSnapshot::class)->orderByDesc('version');
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }
}
