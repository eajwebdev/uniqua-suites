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
        'deposit_cents' => 'integer',
    ];

    /**
     * Camera rentals: minutes past the agreed return moment. Counted against the recorded return,
     * or against now while the camera is still out. 0 when on time or not a timed rental.
     * pickup_at / return_at are property-local wall-clock times (like check_in), stored without a cast.
     */
    public function lateMinutes(): int
    {
        // Older rentals saved without a return time only know the day: lateness cannot be told, so none is shown.
        if (! $this->return_at || ! $this->return_time || ! in_array($this->status, [self::CHECKED_IN, self::CHECKED_OUT], true)) {
            return 0;
        }
        $due = \Carbon\CarbonImmutable::parse($this->return_at, \App\Support\Local::tz());
        $back = $this->actual_check_out_at ?? ($this->status === self::CHECKED_IN ? now() : null);
        if (! $back) {
            return 0;
        }

        return max(0, (int) floor(($back->getTimestamp() - $due->getTimestamp()) / 60));
    }

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
