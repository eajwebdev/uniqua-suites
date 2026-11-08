<?php

namespace App\Domain\Pricing;

use App\Domain\Availability\RoomConflict;
use App\Models\RentalRateTier;
use App\Models\Room;
use Carbon\CarbonImmutable;

/**
 * Grab Cam rate card: one daily rate for the whole rental, picked by its length
 * (1–2, 3–5, 6+ days), and the last day free once a rental reaches the threshold (8 days).
 * Same result shape as PricingService::quote, so bookings, receipts and reports need no changes.
 * No weekday/weekend split, extra guests, early pickup fee, parking or tax.
 */
class CameraPricing
{
    /** @param  array{service_cents?:int, discount_cents?:int, discount_reason?:?string}  $opts  Parking and early check-in do not apply to cameras. */
    public function quote(Room $room, string $from, string $to, array $opts = []): array
    {
        if ($from >= $to) {
            throw new RoomConflict('Return must be after pickup.');
        }

        $start = CarbonImmutable::parse($from);
        $days = (int) $start->diffInDays(CarbonImmutable::parse($to));
        $tier = $this->tier($room->room_type_id, $days, $from);
        if (! $tier) {
            throw new RoomConflict("No camera rate is configured for {$room->name} for {$days} day(s).");
        }

        // The free-day rule belongs to the rate set in force on the pickup date, so changing it never reprices earlier rentals.
        $threshold = (int) $tier->free_day_from;
        $lines = [];
        $nights = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i)->toDateString();
            $free = $threshold > 0 && $days >= $threshold && $i === $days - 1;
            $cents = $free ? 0 : (int) $tier->daily_cents;
            $label = $free ? "Free day ({$threshold}+ days)" : $tier->label();

            $nights[] = ['date' => $date, 'room_id' => $room->id, 'cents' => $cents, 'label' => $label, 'source' => 'tier:'.$tier->id];
            $lines[] = [
                'type' => 'accommodation',
                'description' => $date.' · '.$label,
                'quantity' => 1,
                'unit_cents' => $cents,
                'amount_cents' => $cents,
                'charge_date' => $date,
            ];
        }

        if (($opts['service_cents'] ?? 0) > 0) {
            $lines[] = ['type' => 'service', 'description' => 'Other services', 'quantity' => 1, 'unit_cents' => $opts['service_cents'], 'amount_cents' => $opts['service_cents'], 'charge_date' => $from];
        }
        $discount = (int) ($opts['discount_cents'] ?? 0);
        if ($discount > array_sum(array_column($lines, 'amount_cents'))) {
            throw new PricingInputInvalid('The discount cannot be more than the charges being discounted.');
        }
        if ($discount > 0) {
            $lines[] = [
                'type' => 'discount',
                'description' => empty($opts['discount_reason']) ? 'Discount' : "Discount: {$opts['discount_reason']}",
                'quantity' => 1,
                'unit_cents' => -$discount,
                'amount_cents' => -$discount,
                'charge_date' => $from,
            ];
        }

        return [
            'lines' => $lines,
            'nights' => $nights,
            'total_cents' => array_sum(array_column($lines, 'amount_cents')),
            'warnings' => [],
        ];
    }

    /** The tier covering this many days, in force on the pickup date. */
    public function tier(int $roomTypeId, int $days, string $on): ?RentalRateTier
    {
        return RentalRateTier::where('room_type_id', $roomTypeId)
            ->where('min_days', '<=', $days)
            ->where(fn ($q) => $q->whereNull('max_days')->orWhere('max_days', '>=', $days))
            ->where('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->orderByDesc('min_days')
            ->first();
    }

    /** Current tiers per model for display: [{label, min_days, max_days, daily_cents}]. */
    public function card(int $roomTypeId, string $on): array
    {
        return RentalRateTier::where('room_type_id', $roomTypeId)
            ->where('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on))
            ->orderBy('min_days')->orderByDesc('effective_from')
            ->get()->unique('min_days')->values()
            ->map(fn ($t) => ['label' => $t->max_days === null ? "{$t->min_days}+ days" : "{$t->min_days}–{$t->max_days} days", 'min_days' => $t->min_days, 'max_days' => $t->max_days, 'daily_cents' => (int) $t->daily_cents, 'free_day_from' => (int) $t->free_day_from])
            ->all();
    }
}
