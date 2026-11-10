<?php

namespace App\Domain\Pricing;

use App\Domain\Availability\RoomConflict;
use App\Models\RateOverride;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\TaxRule;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;

class PricingService
{
    /**
     * Calculates a stay. All money is integer centavos.
     *
     * @param  array{source_id?:?int, early_time?:?string, discount_cents?:int, discount_reason?:?string, parking_cents?:int, parking_nights?:int, parking_rate_cents?:int, service_cents?:int}  $opts
     * @return array{lines: list<array>, nights: list<array>, total_cents: int, warnings: list<string>}
     */
    public function quote(Room $room, string $checkIn, string $checkOut, int $guests, array $opts = []): array
    {
        // Grab Cam units are priced from the camera rate card, not nightly suite rates.
        if ($room->isCamera()) {
            return app(CameraPricing::class)->quote($room, $checkIn, $checkOut, $opts);
        }
        if ($checkIn >= $checkOut) {
            throw new RoomConflict('Check-out must be after check-in.');
        }
        if ($guests < 1) {
            throw new RoomConflict('At least one guest is required.');
        }
        if ($guests > $room->max_guests) {
            throw new RoomConflict("{$room->name} allows at most {$room->max_guests} guests; {$guests} requested.");
        }

        $warnings = [];
        // An Airbnb stay costs what Airbnb charged, whatever the dates, so the nightly rates are not used.
        $channelTotal = isset($opts['channel_total_cents']) ? (int) $opts['channel_total_cents'] : null;
        $nights = $channelTotal !== null
            ? $this->spread($room, $checkIn, $checkOut, $channelTotal)
            : $this->nightly($room, $checkIn, $checkOut, $opts['source_id'] ?? null);
        $lines = [];

        foreach ($nights as $n) {
            $lines[] = [
                'type' => 'accommodation',
                'description' => $n['date'].' · '.$n['label'],
                'quantity' => 1,
                'unit_cents' => $n['cents'],
                'amount_cents' => $n['cents'],
                'charge_date' => $n['date'],
            ];
        }

        // Airbnb charges the guest its own fixed price for the whole party (up to the room maximum), so no extra-guest fee.
        $extra = $channelTotal !== null || $this->isAirbnb($opts['source_id'] ?? null) ? 0 : max(0, $guests - $room->included_guests);
        if ($extra > 0) {
            $unit = Settings::get('pricing.extra_guest_unit');
            $fee = (int) Settings::get('pricing.extra_guest_cents');
            if ($unit === null) {
                $warnings[] = "{$extra} guest(s) above the included {$room->included_guests}: extra-guest fee not applied because the owner has not yet approved per-night or per-stay billing.";
            } else {
                $qty = $unit === 'per_night' ? $extra * count($nights) : $extra;
                $lines[] = [
                    'type' => 'extra_guest',
                    'description' => "{$extra} extra guest(s), ".str_replace('_', ' ', $unit),
                    'quantity' => $qty,
                    'unit_cents' => $fee,
                    'amount_cents' => $qty * $fee,
                    'charge_date' => $checkIn,
                ];
            }
        }

        if (! empty($opts['early_time'])) {
            $early = $this->earlyFee($opts['early_time']);
            if ($early['hours'] === 0) {
                throw new PricingInputInvalid("Requested early check-in must be before the standard {$early['standard']} check-in time.");
            }
            if ($early['hours'] > 0) {
                $lines[] = [
                    'type' => 'early_check_in',
                    'description' => "Early check-in {$opts['early_time']} ({$early['hours']} hour(s) before {$early['standard']})",
                    'quantity' => $early['hours'],
                    'unit_cents' => $early['unit_cents'],
                    'amount_cents' => $early['cents'],
                    'charge_date' => $checkIn,
                ];
                $warnings[] = 'Early check-in is subject to availability, room readiness and staff approval.';
            }
        }

        // Worked out before parking, services and discounts, so repricing a stay gives the same tax.
        $taxable = array_sum(array_column($lines, 'amount_cents'));

        $parkingNights = (int) ($opts['parking_nights'] ?? 0);
        if ($parkingNights > count($nights)) {
            throw new PricingInputInvalid('Parking nights cannot be more than the nights in the stay.');
        }
        if ($parkingNights > 0 && ($opts['parking_rate_cents'] ?? 0) <= 0) {
            throw new PricingInputInvalid('Enter a parking rate when parking is selected.');
        }
        if ($parkingNights > 0 && ($opts['parking_rate_cents'] ?? 0) > 0) {
            $rate = (int) $opts['parking_rate_cents'];
            $lines[] = [
                'type' => 'parking',
                'description' => 'Parking ('.$parkingNights.' '.($parkingNights === 1 ? 'night' : 'nights').' × '.Money::plain($rate).')',
                'quantity' => $parkingNights,
                'unit_cents' => $rate,
                'amount_cents' => $parkingNights * $rate,
                'charge_date' => $checkIn,
            ];
        }
        if (($opts['parking_cents'] ?? 0) > 0) {
            $lines[] = ['type' => 'parking', 'description' => 'Parking', 'quantity' => 1, 'unit_cents' => $opts['parking_cents'], 'amount_cents' => $opts['parking_cents'], 'charge_date' => $checkIn];
        }
        if (($opts['service_cents'] ?? 0) > 0) {
            $lines[] = ['type' => 'service', 'description' => 'Other services', 'quantity' => 1, 'unit_cents' => $opts['service_cents'], 'amount_cents' => $opts['service_cents'], 'charge_date' => $checkIn];
        }
        if (($opts['discount_cents'] ?? 0) > array_sum(array_column($lines, 'amount_cents'))) {
            throw new PricingInputInvalid('The discount cannot be more than the charges being discounted.');
        }
        if (($opts['discount_cents'] ?? 0) > 0) {
            $lines[] = [
                'type' => 'discount',
                'description' => empty($opts['discount_reason']) ? 'Discount' : "Discount: {$opts['discount_reason']}",
                'quantity' => 1,
                'unit_cents' => -$opts['discount_cents'],
                'amount_cents' => -$opts['discount_cents'],
                'charge_date' => $checkIn,
            ];
        }

        // The Airbnb total is what the guest paid, so no tax is added on top of it.
        $taxRules = $channelTotal !== null ? [] : TaxRule::inForceOn($checkIn)->orderBy('id')->get();
        foreach ($taxRules as $rule) {
            $lines[] = [
                'type' => 'tax',
                'description' => $rule->name.' ('.rtrim(rtrim(number_format($rule->rate_basis_points / 100, 2, '.', ''), '0'), '.').'%)',
                'quantity' => 1,
                'unit_cents' => $this->taxOn($taxable, (int) $rule->rate_basis_points),
                'amount_cents' => $this->taxOn($taxable, (int) $rule->rate_basis_points),
                'charge_date' => $checkIn,
            ];
        }

        return [
            'lines' => $lines,
            'nights' => $nights,
            'total_cents' => array_sum(array_column($lines, 'amount_cents')),
            'warnings' => $warnings,
        ];
    }

    /** Rounds half up in whole centavos, using integers only. */
    private function taxOn(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    /** One entry per occupied night, priced from that night's date and effective rule. */
    public function nightly(Room $room, string $checkIn, string $checkOut, ?int $sourceId = null): array
    {
        $weekendDays = Settings::get('pricing.weekend_days');
        if ($weekendDays === null) {
            throw new PricingPolicyPending('Weekend days are not configured. Set them in Settings before quoting; the source workbook does not define them.');
        }

        $nights = [];
        $day = CarbonImmutable::parse($checkIn);
        $end = CarbonImmutable::parse($checkOut);

        while ($day->lt($end)) {
            $date = $day->toDateString();
            $override = $this->override($room->id, $date, $sourceId);

            if ($override) {
                $nights[] = ['date' => $date, 'room_id' => $room->id, 'cents' => (int) $override->nightly_cents, 'label' => $override->label, 'source' => 'override:'.$override->id];
            } else {
                $rule = $this->rule($room->id, $date);
                if (! $rule) {
                    throw new RoomConflict("No rate is configured for {$room->name} on {$date}.");
                }
                $isWeekend = in_array($day->dayOfWeekIso, $weekendDays, true);
                $nights[] = [
                    'date' => $date,
                    'room_id' => $room->id,
                    'cents' => (int) ($isWeekend ? $rule->weekend_cents : $rule->weekday_cents),
                    'label' => $isWeekend ? 'Weekend rate' : 'Weekday rate',
                    'source' => 'rule:'.$rule->id,
                ];
            }
            $day = $day->addDay();
        }

        return $nights;
    }

    /**
     * A fixed stay total shared across its nights, so night-by-night reports still add up.
     * Leftover centavos go on the first nights; the nights always add up to the total.
     */
    public function spread(Room $room, string $checkIn, string $checkOut, int $totalCents): array
    {
        if ($totalCents < 0) {
            throw new PricingInputInvalid('The Airbnb total cannot be negative.');
        }
        $dates = [];
        for ($day = CarbonImmutable::parse($checkIn), $end = CarbonImmutable::parse($checkOut); $day->lt($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        $count = count($dates);
        $each = intdiv($totalCents, $count);
        $left = $totalCents - $each * $count;

        return array_map(fn ($date, $i) => [
            'date' => $date,
            'room_id' => $room->id,
            'cents' => $each + ($i < $left ? 1 : 0),
            'label' => 'Airbnb price',
            'source' => 'channel_total',
        ], $dates, array_keys($dates));
    }

    /** ₱ per started hour before the standard check-in time. */
    public function earlyFee(string $time): array
    {
        $standard = (string) Settings::get('pricing.standard_check_in');
        $unit = (int) Settings::get('pricing.early_fee_cents_per_hour');
        $base = CarbonImmutable::parse('2000-01-01 '.$standard);
        $req = CarbonImmutable::parse('2000-01-01 '.$time);
        $minutes = max(0, (int) $req->diffInMinutes($base, false));
        $hours = (int) ceil($minutes / 60);

        return ['hours' => $hours, 'unit_cents' => $unit, 'cents' => $hours * $unit, 'standard' => $standard];
    }

    /** Lowest and highest posted nightly rate today, for public "from" display when weekend days are pending. */
    public function currentRange(Room $room, string $date): ?array
    {
        $rule = $this->rule($room->id, $date);

        return $rule ? ['weekday_cents' => (int) $rule->weekday_cents, 'weekend_cents' => (int) $rule->weekend_cents] : null;
    }

    private function rule(int $roomId, string $date): ?RateRule
    {
        return RateRule::where('room_id', $roomId)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isAirbnb(mixed $sourceId): bool
    {
        return $sourceId && \App\Models\BookingSource::whereKey($sourceId)->value('code') === 'airbnb';
    }

    /** A channel-specific override beats a general one. */
    private function override(int $roomId, string $date, ?int $sourceId): ?RateOverride
    {
        return RateOverride::where('room_id', $roomId)
            ->where('date_from', '<=', $date)
            ->where('date_to', '>=', $date)
            ->where(fn ($q) => $q->whereNull('booking_source_id')->when($sourceId, fn ($s) => $s->orWhere('booking_source_id', $sourceId)))
            ->orderByRaw('booking_source_id IS NULL')
            ->orderByDesc('id')
            ->first();
    }
}
