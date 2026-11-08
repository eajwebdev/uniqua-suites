<?php

namespace App\Domain\Availability;

use App\Models\Room;
use App\Support\Local;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Owner-configured limits on a stay. With nothing configured, every stay passes. A room's own value beats the property-wide one.
 * The property-wide values under Settings are suite rules: a camera follows only limits set on that camera itself.
 */
class StayRules
{
    public function violation(Room $room, string $checkIn, string $checkOut): ?string
    {
        $camera = $room->isCamera();
        $minimum = $room->min_nights ?? ($camera ? null : Settings::get('stay.min_nights'));
        $nights = (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
        if ($minimum && $nights < $minimum) {
            return $camera
                ? "{$room->name} has a minimum rental of {$minimum} day(s); {$nights} requested."
                : "{$room->name} has a minimum stay of {$minimum} night(s); {$nights} requested.";
        }

        $ahead = $room->max_advance_days ?? ($camera ? null : Settings::get('stay.max_advance_days'));
        if ($ahead && $checkIn > Local::now()->addDays($ahead)->toDateString()) {
            return "{$room->name} can be booked at most {$ahead} day(s) ahead.";
        }

        return null;
    }
}
