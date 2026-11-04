<?php

namespace App\Domain\Availability;

use App\Models\Room;
use App\Support\Local;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/** Owner-configured limits on a stay. With nothing configured, every stay passes. A room's own value beats the property-wide one. */
class StayRules
{
    public function violation(Room $room, string $checkIn, string $checkOut): ?string
    {
        $minimum = $room->min_nights ?? Settings::get('stay.min_nights');
        $nights = (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
        if ($minimum && $nights < $minimum) {
            return "{$room->name} has a minimum stay of {$minimum} night(s); {$nights} requested.";
        }

        $ahead = $room->max_advance_days ?? Settings::get('stay.max_advance_days');
        if ($ahead && $checkIn > Local::now()->addDays($ahead)->toDateString()) {
            return "{$room->name} can be booked at most {$ahead} day(s) ahead.";
        }

        return null;
    }
}
