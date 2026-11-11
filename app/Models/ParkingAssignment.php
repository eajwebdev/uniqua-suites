<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A parking reservation. Its assignment status (is a stall confirmed?) and its payment status (has the fee
 * been paid?) are separate: a guest can have paid while the stall is still being confirmed with its owner.
 */
class ParkingAssignment extends Model
{
    /** Asked for; no stall chosen yet. */
    public const REQUESTED = 'requested';

    /** A stall is pencilled in but not yet confirmed with its owner. Does not block the stall. */
    public const PENDING = 'pending_confirmation';

    /** Confirmed on a stall: blocks it for the dates. */
    public const ASSIGNED = 'assigned';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::REQUESTED => 'Requested', self::PENDING => 'Pending confirmation', self::ASSIGNED => 'Assigned', self::CANCELLED => 'Cancelled'];

    /** Not cancelled: still a reservation, whether or not it has a stall yet. */
    public const LIVE = [self::REQUESTED, self::PENDING, self::ASSIGNED];

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'remitted_on' => 'date:Y-m-d',
        'fee_cents' => 'integer',
        'rate_cents' => 'integer',
        'nights' => 'integer',
    ];

    public function charge()
    {
        return $this->belongsTo(BookingCharge::class, 'charge_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE);
    }

    /** Holds its stall: only a confirmed assignment does. */
    public function scopeBlocking($query)
    {
        return $query->where('status', self::ASSIGNED)->whereNotNull('parking_space_id');
    }

    /** Still owed to the stall's owner: a live charge, not yet remitted, assignment not cancelled. */
    public function scopeToRemit($query)
    {
        return $query->whereNotNull('remit_cents')->whereNull('remitted_on')->whereIn('status', self::LIVE)
            ->whereHas('charge', fn ($c) => $c->whereNull('voided_at'));
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function space()
    {
        return $this->belongsTo(ParkingSpace::class, 'parking_space_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
