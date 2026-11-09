<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Availability\StayRules;
use App\Domain\Finance\FinanceService;
use App\Domain\Pricing\PricingService;
use App\Domain\Tasks\TurnoverService;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\BookingNight;
use App\Models\BookingRoom;
use App\Models\BookingStatusEvent;
use App\Models\PricingSnapshot;
use App\Models\Quote;
use App\Models\Room;
use App\Models\TaxRule;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(
        private InventoryService $inventory,
        private PricingService $pricing,
        private TurnoverService $turnover,
        private StayRules $stayRules,
        private FinanceService $finance,
    ) {
    }

    /** Creates an inquiry (non-blocking). $data holds guest_id, source, dates, party and room_ids. */
    public function createInquiry(array $data): Booking
    {
        if (! empty($data['idempotency_key'])) {
            $existing = Booking::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $rooms = Room::whereIn('id', $data['room_ids'])->orderBy('id')->get();
        if ($rooms->isEmpty()) {
            throw new RoomConflict('Select at least one room.');
        }
        if ($rooms->pluck('property_id')->unique()->count() > 1) {
            throw new RoomConflict('All rooms in one booking must be at the same property.');
        }
        if ($data['check_in'] >= $data['check_out']) {
            throw new RoomConflict('Check-out must be after check-in.');
        }

        $guestCount = (int) $data['adults'] + (int) ($data['children'] ?? 0);
        $this->assertCapacity($rooms, $guestCount);

        return DB::transaction(function () use ($data, $rooms, $guestCount) {
            $booking = Booking::create([
                'reference' => $this->newReference(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'guest_id' => $data['guest_id'],
                'property_id' => $rooms->first()->property_id,
                'booking_source_id' => $data['booking_source_id'],
                'source_reference' => $data['source_reference'] ?? null,
                'referrer' => $data['referrer'] ?? null,
                'status' => Booking::INQUIRY,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'approved_early_time' => $data['approved_early_time'] ?? null,
                'requested_arrival_time' => $data['pickup_time'] ?? null,
                'return_time' => $data['return_time'] ?? null,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'guest_count' => $guestCount,
                'special_requests' => $data['special_requests'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->writeRooms($booking, $rooms, $data['check_in'], $data['check_out'], $guestCount);
            $this->event($booking, null, Booking::INQUIRY, 'Created');

            return $booking;
        });
    }

    /** Places a time-limited hold. Only an explicit hold blocks inventory; a quote never does. */
    public function hold(Booking $booking, ?int $hours = null): Booking
    {
        $this->assertStatus($booking, [Booking::INQUIRY, Booking::TENTATIVE, Booking::EXPIRED], 'hold');
        $hours ??= (int) Settings::get('holds.default_hours');

        return $this->lockForBooking($booking, function ($fresh) use ($hours) {
            $this->assertRoomsFree($fresh);
            $from = $fresh->status;
            $fresh->update(['status' => Booking::TENTATIVE, 'hold_expires_at' => now()->addHours($hours)]);
            $this->event($fresh, $from, Booking::TENTATIVE, "Hold for {$hours}h");

            return $fresh;
        });
    }

    /** Confirms and writes the immutable price snapshot. Only one of two concurrent confirmations can succeed. */
    public function confirm(Booking $booking, array $pricingOpts = []): Booking
    {
        return $this->lockForBooking($booking, function ($fresh) use ($pricingOpts) {
            $this->assertStatus($fresh, [Booking::INQUIRY, Booking::TENTATIVE], 'confirm');
            $this->assertRoomsFree($fresh);
            $this->finance->assertStayOpen($fresh->check_in->toDateString(), $fresh->check_out->toDateString());

            if ($fresh->status === Booking::TENTATIVE && $fresh->hold_expires_at?->isPast()) {
                $this->event($fresh, Booking::TENTATIVE, Booking::TENTATIVE, 'Confirmed after hold lapse; availability rechecked');
            }

            if (Settings::get('finance.require_verification_to_confirm')
                && ! $fresh->receipts()->where('verification_status', 'verified')->exists()) {
                throw new RoomConflict('Policy requires a verified payment before confirmation.');
            }

            $this->reprice($fresh, $pricingOpts, 'Confirmation');
            $from = $fresh->status;
            $fresh->update([
                'status' => Booking::CONFIRMED,
                'hold_expires_at' => null,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);
            $this->event($fresh, $from, Booking::CONFIRMED, 'Confirmed by staff');

            return $fresh;
        });
    }

    /** Date, room or party change. Revalidates every conflict and writes a new pricing version. */
    public function amend(Booking $booking, array $data, array $pricingOpts = []): Booking
    {
        $roomIds = $data['room_ids'] ?? $booking->activeRooms()->pluck('room_id')->all();
        $locks = array_merge($roomIds, $booking->activeRooms()->pluck('room_id')->all());

        return $this->inventory->withRoomLocks($locks, function () use ($booking, $data, $roomIds, $pricingOpts) {
            $fresh = Booking::lockForUpdate()->findOrFail($booking->id);
            $this->assertStatus($fresh, [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN], 'amend');

            $checkIn = $data['check_in'] ?? $fresh->check_in->toDateString();
            $checkOut = $data['check_out'] ?? $fresh->check_out->toDateString();
            $adults = (int) ($data['adults'] ?? $fresh->adults);
            $children = (int) ($data['children'] ?? $fresh->children);
            $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->get();
            if ($rooms->isEmpty()) {
                throw new RoomConflict('Select at least one room.');
            }
            if ($rooms->pluck('property_id')->unique()->count() > 1) {
                throw new RoomConflict('All rooms in one booking must be at the same property. Create a separate booking for another property.');
            }
            // A suite stay cannot become a camera rental or the reverse: its money, guest and reports would change business.
            if ($rooms->first()->isCamera() !== ($fresh->property->category === \App\Models\Property::CAMERAS)) {
                throw new RoomConflict('A booking cannot be moved to the other business. Cancel it and create a new booking instead.');
            }
            $this->assertCapacity($rooms, $adults + $children);

            // Repricing rewrites every night, so a stay touching a closed month must stay as it is.
            if (in_array($fresh->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)) {
                $this->finance->assertStayOpen($fresh->check_in->toDateString(), $fresh->check_out->toDateString());
                $this->finance->assertStayOpen($checkIn, $checkOut);
            }

            $current = $fresh->activeRooms()->pluck('room_id')->sort()->values()->all();
            $moved = $checkIn !== $fresh->check_in->toDateString() || $checkOut !== $fresh->check_out->toDateString() || $current !== $rooms->pluck('id')->sort()->values()->all();

            // An inquiry holds nothing, but it must not be moved onto dates that are already taken either.
            if (in_array($fresh->status, [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN], true)) {
                foreach ($rooms as $room) {
                    $this->inventory->assertFree($room, $checkIn, $checkOut, $fresh->id);
                    if ($moved) {
                        $this->assertStayRules($room, $checkIn, $checkOut);
                    }
                }
            }

            $fresh->update([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $adults,
                'children' => $children,
                'guest_count' => $adults + $children,
                'property_id' => $rooms->first()->property_id,
                'approved_early_time' => array_key_exists('approved_early_time', $data) ? $data['approved_early_time'] : $fresh->approved_early_time,
            ]);
            $fresh->activeRooms()->update(['released_at' => now()]);
            $this->writeRooms($fresh, $rooms, $checkIn, $checkOut, $adults + $children);

            if (in_array($fresh->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)) {
                $this->reprice($fresh, $pricingOpts, $data['reason'] ?? 'Amendment');
            }
            $this->inventory->syncClaims($fresh);
            $this->turnover->syncWaiting($fresh);
            $this->event($fresh, $fresh->status, $fresh->status, 'Amended: '.($data['reason'] ?? 'dates, rooms or party'));
            Audit::log('booking.amended', $fresh, ['check_in' => $checkIn, 'check_out' => $checkOut, 'room_ids' => $roomIds]);

            return $fresh;
        });
    }

    public function cancel(Booking $booking, string $reason): Booking
    {
        return $this->lockForBooking($booking, function ($fresh) use ($reason) {
            $this->assertStatus($fresh, [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED], 'cancel');
            if ($fresh->status === Booking::CONFIRMED) {
                $this->finance->assertStayOpen($fresh->check_in->toDateString(), $fresh->check_out->toDateString());
            }
            $from = $fresh->status;
            $fresh->update(['status' => Booking::CANCELLED, 'cancellation_reason' => $reason, 'hold_expires_at' => null]);
            $fresh->activeRooms()->update(['released_at' => now()]);
            $this->turnover->syncWaiting($fresh);
            $this->event($fresh, $from, Booking::CANCELLED, $reason);

            return $fresh;
        });
    }

    public function markNoShow(Booking $booking, ?string $note = null): Booking
    {
        return $this->lockForBooking($booking, function ($fresh) use ($note) {
            $this->assertStatus($fresh, [Booking::CONFIRMED], 'mark as no-show');
            if ($fresh->check_in->toDateString() > Local::today()) {
                throw new RoomConflict('A booking cannot be a no-show before its check-in date.');
            }
            $this->finance->assertStayOpen($fresh->check_in->toDateString(), $fresh->check_out->toDateString());
            $fresh->update(['status' => Booking::NO_SHOW]);
            $fresh->activeRooms()->update(['released_at' => now()]);
            $this->turnover->syncWaiting($fresh);
            $this->event($fresh, Booking::CONFIRMED, Booking::NO_SHOW, $note ?? 'No show');

            return $fresh;
        });
    }

    /** A vacant but dirty room blocks check-in: availability and readiness are separate. */
    public function checkIn(Booking $booking, bool $overrideReadiness = false, ?string $overrideNote = null): Booking
    {
        return $this->lockForBooking($booking, function ($fresh, $rooms) use ($overrideReadiness, $overrideNote) {
            $this->assertStatus($fresh, [Booking::CONFIRMED], 'check in');
            foreach ($rooms as $room) {
                if ($room->hasOpenSafetyBlock()) {
                    throw new RoomConflict("{$room->name} has an unresolved safety-critical work order.");
                }
                // A room can show as clean while its last guest has not left. No override: two parties cannot share a room.
                $stillIn = Booking::where('status', Booking::CHECKED_IN)->whereKeyNot($fresh->id)
                    ->whereHas('activeRooms', fn ($stay) => $stay->where('room_id', $room->id))->first();
                if ($stillIn) {
                    throw new RoomConflict("{$room->name} still has a guest checked in ({$stillIn->reference}). Check them out first.");
                }
                // Camera units have no cleaning turnover, so there is no readiness to wait for.
                if (! $room->isCamera() && $room->housekeeping_status !== 'ready' && ! $overrideReadiness) {
                    throw new RoomConflict("{$room->name} is vacant but not ready (housekeeping: {$room->housekeeping_status}). Complete turnover and inspection first.");
                }
            }
            $fresh->update(['status' => Booking::CHECKED_IN, 'actual_check_in_at' => now()]);
            $note = $overrideReadiness ? 'Checked in with readiness override: '.$overrideNote : 'Checked in';
            $this->event($fresh, Booking::CONFIRMED, Booking::CHECKED_IN, $note);
            if ($overrideReadiness) {
                Audit::log('booking.readiness_override', $fresh, ['note' => $overrideNote]);
            }

            return $fresh;
        });
    }

    /** Safe to retry: a repeated checkout returns without duplicating turnover work. */
    public function checkOut(Booking $booking, ?string $note = null): Booking
    {
        return $this->lockForBooking($booking, function ($fresh, $rooms) use ($note) {
            if ($fresh->status === Booking::CHECKED_OUT) {
                return $fresh;
            }
            $this->assertStatus($fresh, [Booking::CHECKED_IN], 'check out');
            $fresh->update(['status' => Booking::CHECKED_OUT, 'actual_check_out_at' => now()]);
            $this->event($fresh, Booking::CHECKED_IN, Booking::CHECKED_OUT, $note ?? 'Checked out');

            foreach ($rooms as $room) {
                if (! $room->isCamera()) {
                    $this->turnover->onCheckout($fresh, $room);
                }
            }

            return $fresh;
        });
    }

    /** Idempotent; a confirmed booking is never touched because the status guard is in the WHERE clause. */
    public function expireHolds(): int
    {
        $count = 0;
        $due = Booking::where('status', Booking::TENTATIVE)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$count) {
                $updated = Booking::where('id', $id)
                    ->where('status', Booking::TENTATIVE)
                    ->where('hold_expires_at', '<=', now())
                    ->update(['status' => Booking::EXPIRED]);
                if ($updated) {
                    $count++;
                    DB::table('room_night_claims')->where('booking_id', $id)->delete();
                    BookingStatusEvent::create([
                        'booking_id' => $id,
                        'from_status' => Booking::TENTATIVE,
                        'to_status' => Booking::EXPIRED,
                        'note' => 'Hold expired automatically',
                        'created_at' => now(),
                    ]);
                }
            });
        }

        return $count;
    }

    /** Runs $work with booking rooms locked first, then the booking row, always in that order. */
    private function lockForBooking(Booking $booking, callable $work): mixed
    {
        $roomIds = $booking->activeRooms()->pluck('room_id')->all();

        return $this->inventory->withRoomLocks($roomIds, function ($rooms) use ($booking, $work) {
            $fresh = Booking::lockForUpdate()->findOrFail($booking->id);

            // A concurrent move could have changed the rooms between reading and locking them.
            $current = $fresh->activeRooms()->pluck('room_id')->sort()->values()->all();
            if ($current !== $rooms->keys()->sort()->values()->all()) {
                throw new RoomConflict('This booking was just changed by someone else. Reload and try again.');
            }

            $result = $work($fresh, $rooms);
            $this->inventory->syncClaims($fresh);

            return $result;
        });
    }

    private function assertRoomsFree(Booking $booking): void
    {
        foreach ($booking->activeRooms()->with('room')->get() as $br) {
            $this->inventory->assertFree($br->room, $br->check_in->toDateString(), $br->check_out->toDateString(), $booking->id);
            $this->assertStayRules($br->room, $br->check_in->toDateString(), $br->check_out->toDateString());
        }
    }

    private function assertStayRules(Room $room, string $checkIn, string $checkOut): void
    {
        $problem = $this->stayRules->violation($room, $checkIn, $checkOut);
        if ($problem) {
            throw new RoomConflict($problem);
        }
    }

    private function assertStatus(Booking $booking, array $allowed, string $action): void
    {
        if (! in_array($booking->status, $allowed, true)) {
            throw new RoomConflict("Cannot {$action} a booking in status {$booking->status}.");
        }
    }

    private function assertCapacity($rooms, int $guests): void
    {
        $capacity = $rooms->sum('max_guests');
        if ($guests > $capacity) {
            $names = $rooms->pluck('name')->join(', ');
            throw new RoomConflict("{$names} allows at most {$capacity} guests; {$guests} requested.");
        }
    }

    private function writeRooms(Booking $booking, $rooms, string $in, string $out, int $guests): void
    {
        $remaining = $guests;
        foreach ($rooms as $room) {
            $here = min($remaining, $room->max_guests);
            $remaining -= $here;
            BookingRoom::create([
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'check_in' => $in,
                'check_out' => $out,
                'guest_count' => $here,
            ]);
        }
    }

    /**
     * Replaces the priced lines with a new immutable version. Manually added charges
     * (damage, parking, adjustments) survive repricing because only engine types are voided.
     */
    private function reprice(Booking $booking, array $opts, string $reason): void
    {
        $version = $booking->pricing_version + 1;
        $engineTypes = ['accommodation', 'extra_guest', 'early_check_in', 'tax'];
        $opts = array_merge(['source_id' => $booking->booking_source_id], $opts);
        if ($booking->approved_early_time) {
            $opts['early_time'] = substr($booking->approved_early_time, 0, 5);
        }

        $booking->charges()->whereIn('type', $engineTypes)->whereNull('voided_at')
            ->update(['voided_at' => now(), 'void_reason' => "Superseded by pricing v{$version}"]);
        $booking->nights()->where('is_current', true)->update(['is_current' => false]);

        $all = [];
        $earlyApplied = false;
        foreach ($booking->activeRooms()->with('room')->orderBy('room_id')->get() as $br) {
            $roomOpts = $opts;
            if ($earlyApplied) {
                unset($roomOpts['early_time']);
            }
            $roomOpts['parking_cents'] = $earlyApplied ? 0 : ($opts['parking_cents'] ?? 0);
            $roomOpts['discount_cents'] = $earlyApplied ? 0 : ($opts['discount_cents'] ?? 0);
            $earlyApplied = true;

            $quote = $this->pricing->quote($br->room, $br->check_in->toDateString(), $br->check_out->toDateString(), max(1, $br->guest_count), $roomOpts);
            $all[] = ['room' => $br->room->name, 'unit_code' => $br->room->unit_code] + $quote;

            foreach ($quote['lines'] as $line) {
                BookingCharge::create($line + [
                    'booking_id' => $booking->id,
                    'pricing_version' => $version,
                    'approved_by' => $line['type'] === 'discount' ? auth()->id() : null,
                    'created_by' => auth()->id(),
                ]);
            }
            foreach ($quote['nights'] as $n) {
                BookingNight::create([
                    'booking_id' => $booking->id,
                    'room_id' => $br->room_id,
                    'stay_date' => $n['date'],
                    'rate_cents' => $n['cents'],
                    'rate_source' => $n['source'],
                    'pricing_version' => $version,
                ]);
            }
        }

        $total = (int) $booking->activeCharges()->sum('amount_cents');
        PricingSnapshot::create([
            'booking_id' => $booking->id,
            'version' => $version,
            'snapshot' => ['rooms' => $all, 'settings' => [
                'weekend_days' => Settings::get('pricing.weekend_days'),
                'extra_guest_unit' => Settings::get('pricing.extra_guest_unit'),
                'extra_guest_cents' => Settings::get('pricing.extra_guest_cents'),
                'early_fee_cents_per_hour' => Settings::get('pricing.early_fee_cents_per_hour'),
                'tax_rules' => TaxRule::inForceOn($booking->check_in->toDateString())->get(['name', 'rate_basis_points'])->toArray(),
            ]],
            'total_cents' => $total,
            'reason' => $reason,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
        $booking->update(['pricing_version' => $version, 'total_cents' => $total]);
    }

    /**
     * What an unpriced booking (inquiry or hold) will cost, shown but never saved, so it is not counted as money owed.
     * A booking made from a quote shows that quote while it still describes the stay; otherwise today's rates,
     * worked out the same way Confirm will. Null once the booking is priced, or when it cannot be priced.
     *
     * @return array{source: string, quote?: string, lines: list<array{type: string, description: string, amount_cents: int}>, total_cents: int}|array{error: string}|null
     */
    public function estimate(Booking $booking): ?array
    {
        if ($booking->pricing_version > 0 || in_array($booking->status, ['cancelled', 'no_show'], true)) {
            return null;
        }
        $rooms = $booking->activeRooms()->with('room')->orderBy('room_id')->get();
        if ($rooms->isEmpty()) {
            return null;
        }

        $quote = Quote::with('items')->where('booking_id', $booking->id)->orderByDesc('id')->first();
        if ($quote && $rooms->count() === 1 && (int) $quote->room_id === (int) $rooms[0]->room_id
            && $quote->check_in->toDateString() === $booking->check_in->toDateString() && $quote->check_out->toDateString() === $booking->check_out->toDateString()) {
            return [
                'source' => 'quote',
                'quote' => "{$quote->reference} v{$quote->version}",
                'lines' => $quote->items->map(fn ($i) => ['type' => $i->type, 'description' => $i->description, 'amount_cents' => (int) $i->amount_cents])->values()->all(),
                'total_cents' => (int) $quote->total_cents,
            ];
        }

        $opts = ['source_id' => $booking->booking_source_id];
        if ($booking->approved_early_time) {
            $opts['early_time'] = substr($booking->approved_early_time, 0, 5);
        }
        $lines = [];
        try {
            foreach ($rooms as $index => $br) {
                $roomOpts = $index === 0 ? $opts : array_diff_key($opts, ['early_time' => true]);
                $priced = $this->pricing->quote($br->room, $br->check_in->toDateString(), $br->check_out->toDateString(), max(1, $br->guest_count), $roomOpts);
                foreach ($priced['lines'] as $line) {
                    $lines[] = ['type' => $line['type'], 'description' => ($rooms->count() > 1 ? "{$br->room->name}: " : '').$line['description'], 'amount_cents' => (int) $line['amount_cents']];
                }
            }
        } catch (RoomConflict $e) { // includes missing rates and pricing settings
            return ['error' => 'An estimate cannot be worked out yet: '.$e->getMessage()];
        }

        return ['source' => 'rates', 'lines' => $lines, 'total_cents' => array_sum(array_column($lines, 'amount_cents'))];
    }

    /** Recalculates the stored total after a non-engine charge is added or voided. */
    public function syncTotal(Booking $booking): void
    {
        $booking->update(['total_cents' => (int) $booking->activeCharges()->sum('amount_cents')]);
    }

    private function event(Booking $booking, ?string $from, string $to, ?string $note): void
    {
        BookingStatusEvent::create([
            'booking_id' => $booking->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
        Audit::log('booking.'.$to, $booking, ['from' => $from, 'note' => $note]);
    }

    private function newReference(): string
    {
        do {
            $ref = 'UQ-'.Local::now()->format('ym').'-'.Str::upper(Str::random(5));
        } while (Booking::where('reference', $ref)->exists());

        return $ref;
    }
}
