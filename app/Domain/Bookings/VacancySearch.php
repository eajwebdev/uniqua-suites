<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Availability\StayRules;
use App\Domain\Pricing\PricingPolicyPending;
use App\Domain\Pricing\PricingInputInvalid;
use App\Domain\Pricing\PricingService;
use App\Models\Room;

/**
 * Staff-only: which units can take a stay, and what it would cost. It reads without locking,
 * so it can go out of date: only a hold or a confirmation actually reserves a room.
 */
class VacancySearch
{
    public function __construct(private InventoryService $inventory, private PricingService $pricing, private StayRules $stayRules)
    {
    }

    /** @return array{vacant: list<array>, unavailable: list<array>, pricing_pending: ?string} */
    public function find(string $checkIn, string $checkOut, int $guests, array $pricingOpts = []): array
    {
        $vacant = [];
        $unavailable = [];
        $pending = null;

        // Quotes are for suites; camera rentals are booked from New Booking.
        foreach (Room::suites()->where('operational_status', '!=', 'retired')->with(['property:id,name', 'type:id,name'])->orderBy('id')->get() as $room) {
            $unit = ['id' => $room->id, 'name' => $room->name, 'unit' => $room->unit_code, 'property' => $room->property->name, 'type' => $room->type->name, 'included' => $room->included_guests, 'max' => $room->max_guests];

            $reason = $this->reasonUnavailable($room, $checkIn, $checkOut, $guests);
            if ($reason) {
                $unavailable[] = $unit + ['reason' => $reason];
                continue;
            }

            try {
                $quote = $this->pricing->quote($room, $checkIn, $checkOut, $guests, $pricingOpts);
                $vacant[] = $unit + ['housekeeping' => $room->housekeeping_status, 'total_cents' => $quote['total_cents'], 'nights' => count($quote['nights']), 'warnings' => $quote['warnings']];
            } catch (PricingPolicyPending|PricingInputInvalid $e) {
                $pending = $e->getMessage();
                $vacant[] = $unit + ['housekeeping' => $room->housekeeping_status, 'total_cents' => null, 'nights' => null, 'warnings' => []];
            } catch (RoomConflict $e) {
                $unavailable[] = $unit + ['reason' => $e->getMessage()];
            }
        }

        usort($vacant, fn ($a, $b) => [$a['total_cents'] ?? 0, $a['id']] <=> [$b['total_cents'] ?? 0, $b['id']]);

        return ['vacant' => $vacant, 'unavailable' => $unavailable, 'pricing_pending' => $pending];
    }

    /** Why this room cannot be offered for these dates, or null when it can. A booking being re-quoted does not count against itself. */
    public function reasonUnavailable(Room $room, string $checkIn, string $checkOut, int $guests, ?int $ignoreBookingId = null): ?string
    {
        if ($room->operational_status !== 'active') {
            return 'In maintenance.';
        }
        if ($room->hasOpenSafetyBlock()) {
            return 'Has an unresolved safety work order.';
        }
        if ($guests > $room->max_guests) {
            return "Takes at most {$room->max_guests} guests.";
        }

        $conflict = $this->inventory->conflicts($room->id, $checkIn, $checkOut, $ignoreBookingId)->first();
        if ($conflict) {
            return "{$conflict['label']}, {$conflict['start']} to {$conflict['end']}.";
        }

        return $this->stayRules->violation($room, $checkIn, $checkOut);
    }
}
