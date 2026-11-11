<?php

namespace App\Domain\Parking;

use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\ExternalSettlement;
use App\Models\ParkingAssignment;
use App\Models\ParkingBlock;
use App\Models\ParkingSpace;
use App\Models\Receipt;
use App\Models\Refund;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parking reservations, their fees and what has been paid for them.
 *
 * The fee is a charge on the guest's booking, so it shows in the booking's breakdown and balance, but it is
 * added on its own: the room's price and earlier payments are never recalculated. Payments for parking are
 * receipts tagged to the parking; a general payment on the booking covers the room first and only what is
 * left over counts toward parking. So an Airbnb stay paid on Airbnb stays paid, and its parking is still to collect.
 */
class ParkingService
{
    public const TO_COLLECT = 'to_collect';
    public const PARTIAL = 'partially_paid';
    public const PAID = 'paid';
    public const NO_FEE = 'no_fee';

    public const PAYMENT_LABELS = [self::TO_COLLECT => 'To collect', self::PARTIAL => 'Partially paid', self::PAID => 'Paid', self::NO_FEE => 'No fee'];

    public function __construct(private FinanceService $finance)
    {
    }

    /**
     * Creates or changes a parking reservation. The stall row is locked, so two people cannot confirm the same stall twice.
     *
     * @param  array{booking_id?:?int, holder_name:string, vehicle?:?string, notes?:?string, parking_space_id?:?int, status:string, start_date:string, end_date:string, rate_cents?:?int, owner_confirmed?:bool}  $data
     */
    public function save(?ParkingAssignment $assignment, array $data, ?int $userId): ParkingAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $userId) {
            $before = $assignment?->only(['parking_space_id', 'status', 'start_date', 'end_date', 'fee_cents', 'booking_id']);
            $spaceId = $data['parking_space_id'] ?? null;
            $status = $data['status'];
            if ($status === ParkingAssignment::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Use Cancel to cancel a parking reservation.']);
            }
            if ($status === ParkingAssignment::ASSIGNED && ! $spaceId) {
                throw ValidationException::withMessages(['parking_space_id' => 'Choose the stall to mark this parking as assigned, or keep it as requested.']);
            }
            if ($status === ParkingAssignment::REQUESTED && $spaceId) {
                $status = ParkingAssignment::PENDING; // a stall is pencilled in, so it is waiting for confirmation
            }
            $space = $spaceId ? ParkingSpace::lockForUpdate()->findOrFail($spaceId) : null;
            $start = $data['start_date'];
            $end = $data['end_date'];
            $nights = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end));
            $rate = $data['rate_cents'] ?? null;
            $fee = $rate ? $nights * (int) $rate : 0;

            $changedStall = ! $assignment || (int) $assignment->parking_space_id !== (int) $spaceId || $assignment->status !== $status
                || $assignment->start_date->toDateString() !== $start || $assignment->end_date->toDateString() !== $end;
            if ($space && $changedStall && ! $space->isActive()) {
                throw ValidationException::withMessages(['parking_space_id' => "{$space->code} is marked ".($space->status === ParkingSpace::INACTIVE ? 'inactive' : 'unavailable').'. Choose another stall or leave it to be assigned.']);
            }
            if ($space && $status === ParkingAssignment::ASSIGNED && $changedStall) {
                $this->assertFree($space, $start, $end, $assignment?->id);
                $alreadyConfirmed = $assignment && $assignment->status === ParkingAssignment::ASSIGNED && (int) $assignment->parking_space_id === $space->id;
                if ($space->isBorrowed() && ! $alreadyConfirmed && empty($data['owner_confirmed'])) {
                    throw ValidationException::withMessages(['owner_confirmed' => "{$space->code} belongs to ".($space->vendor_name ?: 'another owner').'. Confirm with them that it is free for these dates, then tick the box.']);
                }
            }
            if ($assignment?->remitted_on && ($fee !== (int) $assignment->fee_cents || (int) $assignment->parking_space_id !== (int) $spaceId)) {
                throw ValidationException::withMessages(['rate' => 'This parking was already remitted to the stall owner. Undo the remittance under Parking > Remittances before changing the stall or the fee.']);
            }

            $assignment ??= new ParkingAssignment(['created_by' => $userId]);
            $assignment->fill([
                'booking_id' => $data['booking_id'] ?? null,
                'parking_space_id' => $space?->id,
                'holder_name' => $data['holder_name'],
                'vehicle' => $data['vehicle'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $status,
                'start_date' => $start,
                'end_date' => $end,
                'nights' => $nights,
                'rate_cents' => $rate,
                'fee_cents' => $fee,
            ])->save();
            $this->syncCharge($assignment, $userId, $before['booking_id'] ?? null);
            Audit::log($before ? 'parking.updated' : 'parking.requested', $assignment, ['before' => $before, 'status' => $status, 'space' => $space?->code, 'fee_cents' => $fee]);

            return $assignment;
        });
    }

    public function cancel(ParkingAssignment $assignment, ?int $userId, string $reason = 'Parking cancelled'): void
    {
        if ($assignment->remitted_on) {
            throw ValidationException::withMessages(['assignment' => 'This parking was already remitted to the stall owner. Undo the remittance under Parking > Remittances first.']);
        }
        DB::transaction(function () use ($assignment, $userId, $reason) {
            $assignment->update(['status' => ParkingAssignment::CANCELLED]);
            $this->syncCharge($assignment, $userId, null, $reason);
            Audit::log('parking.cancelled', $assignment, ['reason' => $reason]);
        });
    }

    /** A cancelled or no-show booking frees its parking. Parking already remitted to its owner is left for staff to sort out. */
    public function releaseForBooking(Booking $booking, string $reason): void
    {
        foreach (ParkingAssignment::where('booking_id', $booking->id)->live()->whereNull('remitted_on')->get() as $assignment) {
            $assignment->update(['status' => ParkingAssignment::CANCELLED]);
            if ($assignment->charge_id) {
                BookingCharge::whereKey($assignment->charge_id)->whereNull('voided_at')->update(['voided_at' => now(), 'void_reason' => $reason]);
            }
            Audit::log('parking.cancelled', $assignment, ['reason' => $reason]);
        }
        $this->syncTotal($booking);
    }

    /**
     * Keeps the booking charge in step with the reservation: one live charge for the fee while it is live and on a booking.
     * A changed amount, owner type or booking voids the old line and writes a new one, so the booking's history stays readable.
     */
    public function syncCharge(ParkingAssignment $assignment, ?int $userId, ?int $previousBookingId = null, string $voidReason = 'Parking changed'): void
    {
        $assignment->loadMissing('space');
        $space = $assignment->space;
        $type = $space?->isBorrowed() ? BookingCharge::PARKING_REMIT : 'parking';
        $want = $assignment->isLive() && $assignment->booking_id && $assignment->fee_cents > 0;
        $charge = $assignment->charge_id ? BookingCharge::whereKey($assignment->charge_id)->whereNull('voided_at')->first() : null;
        $description = 'Parking '.($space ? $space->code : '(stall to be assigned)').", {$assignment->nights} night".($assignment->nights === 1 ? '' : 's').' × '.Money::plain((int) $assignment->rate_cents)
            .($space?->isBorrowed() ? ', third-party stall of '.($space->vendor_name ?: 'another owner').' (to remit)' : '');

        if ($charge && (! $want || (int) $charge->amount_cents !== $assignment->fee_cents || $charge->type !== $type || (int) $charge->booking_id !== (int) $assignment->booking_id)) {
            $this->finance->assertOpen($charge->charge_date?->toDateString() ?? $assignment->start_date->toDateString());
            $charge->update(['voided_at' => now(), 'void_reason' => $voidReason]);
            $charge = null;
        } elseif ($charge && $charge->description !== $description) {
            $charge->update(['description' => $description]); // the stall was named: wording only, the amount is unchanged
        }
        if ($want && ! $charge) {
            $booking = Booking::findOrFail($assignment->booking_id);
            $this->finance->assertOpen($assignment->start_date->toDateString());
            $charge = BookingCharge::create([
                'booking_id' => $booking->id,
                'type' => $type,
                'description' => $description,
                'quantity' => $assignment->nights,
                'unit_cents' => (int) $assignment->rate_cents,
                'amount_cents' => $assignment->fee_cents,
                'charge_date' => $assignment->start_date->toDateString(),
                'pricing_version' => $booking->pricing_version,
                'approved_by' => $userId,
                'created_by' => $userId,
            ]);
        }

        $assignment->update([
            'charge_id' => $charge?->id ?? ($want ? null : $assignment->charge_id),
            'remit_cents' => $charge && $space?->isBorrowed() ? $assignment->fee_cents : null,
            'remit_to' => $space?->isBorrowed() ? $space->vendor_name : null,
        ]);
        foreach (array_unique(array_filter([$assignment->booking_id, $previousBookingId])) as $bookingId) {
            $this->syncTotal(Booking::find($bookingId));
        }
    }

    private function syncTotal(?Booking $booking): void
    {
        $booking?->update(['total_cents' => (int) $booking->activeCharges()->sum('amount_cents')]);
    }

    /** Throws when a confirmed reservation or a manual block already holds the stall on any of these nights. */
    public function assertFree(ParkingSpace $space, string $start, string $end, ?int $ignoreId = null): void
    {
        if ($reason = $this->takenReason($space->id, $start, $end, $ignoreId)) {
            throw ValidationException::withMessages(['parking_space_id' => "{$space->code} is not free: {$reason}."]);
        }
    }

    private function takenReason(int $spaceId, string $start, string $end, ?int $ignoreId = null, ?int $ignoreBlockId = null): ?string
    {
        $clash = ParkingAssignment::blocking()->where('parking_space_id', $spaceId)->where('start_date', '<', $end)->where('end_date', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->first();
        if ($clash) {
            return "assigned to {$clash->holder_name}, {$clash->start_date->format('M j')} to {$clash->end_date->format('M j')}";
        }
        $block = ParkingBlock::where('parking_space_id', $spaceId)->where('start_date', '<', $end)->where('end_date', '>', $start)
            ->when($ignoreBlockId, fn ($q) => $q->whereKeyNot($ignoreBlockId))->first();

        return $block ? 'blocked ('.strtolower(ParkingBlock::REASONS[$block->reason] ?? $block->reason).'), '.$block->start_date->format('M j').' to '.$block->end_date->format('M j') : null;
    }

    /** A manual block cannot cover nights a guest has already been confirmed on. */
    public function saveBlock(?ParkingBlock $block, array $data, ?int $userId): ParkingBlock
    {
        return DB::transaction(function () use ($block, $data, $userId) {
            $space = ParkingSpace::lockForUpdate()->findOrFail($data['parking_space_id']);
            $clash = ParkingAssignment::blocking()->where('parking_space_id', $space->id)->where('start_date', '<', $data['end_date'])->where('end_date', '>', $data['start_date'])->first();
            if ($clash) {
                throw ValidationException::withMessages(['start_date' => "{$space->code} is assigned to {$clash->holder_name} from {$clash->start_date->format('M j')} to {$clash->end_date->format('M j')}. Move or cancel that parking first."]);
            }
            $block ??= new ParkingBlock(['created_by' => $userId]);
            $block->fill(['parking_space_id' => $space->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'reason' => $data['reason'], 'note' => $data['note'] ?? null])->save();
            Audit::log('parking.blocked', $block, ['space' => $space->code, 'from' => $data['start_date'], 'to' => $data['end_date']]);

            return $block;
        });
    }

    /**
     * Every stall that could take these dates, and why not when it cannot.
     *
     * @return list<array{id:int, label:string, property:string, ownership:string, owner:?string, rate_cents:?int, status:string, free:bool, reason:?string}>
     */
    public function availability(?string $start, ?string $end, ?int $ignoreId = null): array
    {
        $valid = $start && $end && $end > $start;

        return ParkingSpace::with('property:id,name')->where('status', '!=', ParkingSpace::INACTIVE)->orderBy('property_id')->orderBy('code')->get()
            ->map(function (ParkingSpace $s) use ($valid, $start, $end, $ignoreId) {
                $reason = ! $s->isActive() ? 'Marked unavailable' : ($valid ? $this->takenReason($s->id, $start, $end, $ignoreId) : null);

                return [
                    'id' => $s->id, 'label' => $s->label(), 'property' => $s->property->name, 'ownership' => $s->ownership, 'owner' => $s->vendor_name,
                    'rate_cents' => $s->rate_cents === null ? null : (int) $s->rate_cents, 'status' => $s->status, 'free' => $reason === null, 'reason' => $reason ? ucfirst($reason) : null,
                ];
            })->values()->all();
    }

    /**
     * Fee, paid, waiting-to-verify and balance for each reservation, keyed by id.
     *
     * @param  Collection<int, ParkingAssignment>  $assignments
     * @return array<int, array{fee_cents:int, paid_cents:int, pending_cents:int, balance_cents:int, payment_status:string}>
     */
    public function payments(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }
        $ids = $assignments->pluck('id');
        $tagged = Receipt::whereIn('parking_assignment_id', $ids)->where('verification_status', 'verified')->groupBy('parking_assignment_id')->selectRaw('parking_assignment_id as id, SUM(amount_cents) as cents')->pluck('cents', 'id');
        $pending = Receipt::whereIn('parking_assignment_id', $ids)->where('verification_status', 'pending')->groupBy('parking_assignment_id')->selectRaw('parking_assignment_id as id, SUM(amount_cents) as cents')->pluck('cents', 'id');
        $spill = $this->leftOverForParking($assignments->pluck('booking_id')->filter()->unique()->values());

        $result = [];
        // A booking's leftover payment covers its parking in date order.
        foreach ($assignments->sortBy(fn ($a) => [$a->start_date->toDateString(), $a->id]) as $a) {
            $fee = $a->isLive() ? (int) $a->fee_cents : 0;
            $paid = (int) ($tagged[$a->id] ?? 0);
            if ($a->booking_id && isset($spill[$a->booking_id]) && $paid < $fee) {
                $extra = min($spill[$a->booking_id], $fee - $paid);
                $paid += $extra;
                $spill[$a->booking_id] -= $extra;
            }
            $result[$a->id] = [
                'fee_cents' => $fee,
                'paid_cents' => $paid,
                'pending_cents' => (int) ($pending[$a->id] ?? 0),
                'balance_cents' => max(0, $fee - $paid),
                'payment_status' => match (true) {
                    $fee === 0 => self::NO_FEE,
                    $paid >= $fee => self::PAID,
                    $paid > 0 => self::PARTIAL,
                    default => self::TO_COLLECT,
                },
            ];
        }

        return $result;
    }

    /**
     * Per booking: what its general payments (not tagged to a parking) leave over once everything but parking is covered.
     *
     * @return array<int, int>
     */
    private function leftOverForParking(Collection $bookingIds): array
    {
        if ($bookingIds->isEmpty()) {
            return [];
        }
        $sum = fn ($query, string $column) => $query->whereIn('booking_id', $bookingIds)->groupBy('booking_id')->selectRaw("booking_id, SUM({$column}) as cents")->pluck('cents', 'booking_id');
        $general = $sum(Receipt::where('verification_status', 'verified')->whereNull('parking_assignment_id'), 'amount_cents');
        $settled = $sum(ExternalSettlement::whereNotNull('actual_payout_cents'), 'gross_cents');
        $refunded = $sum(Refund::where('status', 'executed'), 'amount_cents');
        $charges = $sum(BookingCharge::whereNull('voided_at'), 'amount_cents');
        $parkingCharges = $sum(BookingCharge::whereNull('voided_at')->whereIn('id', ParkingAssignment::live()->whereNotNull('charge_id')->select('charge_id')), 'amount_cents');

        $left = [];
        foreach ($bookingIds as $id) {
            $applied = (int) ($general[$id] ?? 0) + (int) ($settled[$id] ?? 0) - (int) ($refunded[$id] ?? 0);
            $left[$id] = max(0, $applied - ((int) ($charges[$id] ?? 0) - (int) ($parkingCharges[$id] ?? 0)));
        }

        return $left;
    }

    /**
     * A booking's balance split into the stay and its parking, so adding parking never makes a paid stay look unpaid.
     *
     * @return array{stay_charges_cents:int, stay_paid_cents:int, parking_fee_cents:int, parking_paid_cents:int, parking_balance_cents:int}
     */
    public function bookingSplit(Booking $booking, array $balance): array
    {
        $assignments = ParkingAssignment::where('booking_id', $booking->id)->get();
        $payments = collect($this->payments($assignments));
        $parkingFee = (int) $payments->sum('fee_cents');
        $parkingCharges = (int) BookingCharge::whereNull('voided_at')->whereIn('id', $assignments->filter->isLive()->pluck('charge_id')->filter())->sum('amount_cents');
        $stayCharges = $balance['charges_cents'] - $parkingCharges;
        $tagged = (int) Receipt::where('booking_id', $booking->id)->whereNotNull('parking_assignment_id')->where('verification_status', 'verified')->sum('amount_cents');
        $applied = $balance['verified_cents'] + $balance['settled_gross_cents'] - $balance['refunded_cents'] - $tagged;

        return [
            'stay_charges_cents' => $stayCharges,
            'stay_paid_cents' => min(max(0, $applied), max(0, $stayCharges)),
            'parking_fee_cents' => $parkingFee,
            'parking_paid_cents' => (int) $payments->sum('paid_cents'),
            'parking_balance_cents' => (int) $payments->sum('balance_cents'),
        ];
    }

    /** "Airbnb" or "Direct" for the summary and reports; parking without a booking is "No booking". */
    public static function bookingType(?Booking $booking): string
    {
        return match (true) {
            $booking === null => 'none',
            $booking->source?->code === 'airbnb' => 'airbnb',
            default => 'direct',
        };
    }

    public const BOOKING_TYPES = ['airbnb' => 'Airbnb', 'direct' => 'Direct booking', 'none' => 'No booking'];

    /**
     * Today's parking at a glance. Money figures only when $money: this month's parking by start date,
     * plus everything still owed regardless of date.
     */
    public function dashboard(string $today, bool $money): array
    {
        $spaces = ParkingSpace::where('status', '!=', ParkingSpace::INACTIVE)->get(['id', 'status']);
        $activeIds = $spaces->where('status', ParkingSpace::ACTIVE)->pluck('id');
        $occupied = ParkingAssignment::blocking()->whereIn('parking_space_id', $activeIds)->where('start_date', '<=', $today)->where('end_date', '>', $today)->distinct()->pluck('parking_space_id');
        $blocked = ParkingBlock::whereIn('parking_space_id', $activeIds)->where('start_date', '<=', $today)->where('end_date', '>', $today)->distinct()->pluck('parking_space_id')->diff($occupied);
        $open = ParkingAssignment::live()->where('end_date', '>', $today);

        $data = [
            'spaces' => $spaces->count(),
            'occupied' => $occupied->count(),
            'blocked' => $blocked->count() + $spaces->where('status', ParkingSpace::UNAVAILABLE)->count(),
            'available' => max(0, $activeIds->count() - $occupied->count() - $blocked->count()),
            'reservations' => (clone $open)->count(),
            'upcoming' => (clone $open)->where('start_date', '>', $today)->count(),
            'unassigned' => (clone $open)->whereIn('status', [ParkingAssignment::REQUESTED, ParkingAssignment::PENDING])->count(),
            'money' => null,
        ];
        if (! $money) {
            return $data;
        }

        $monthStart = substr($today, 0, 7).'-01';
        $monthEnd = CarbonImmutable::parse($monthStart)->addMonth()->toDateString();
        $all = ParkingAssignment::live()->with(['space:id,ownership', 'booking:id,booking_source_id', 'booking.source:id,code'])
            ->where(fn ($q) => $q->where('fee_cents', '>', 0))->get();
        $pay = $this->payments($all);
        $month = $all->filter(fn ($a) => $a->start_date->toDateString() >= $monthStart && $a->start_date->toDateString() < $monthEnd);
        $owed = $all->filter(fn ($a) => $pay[$a->id]['balance_cents'] > 0);
        $group = fn (callable $key) => $month->groupBy($key)->map(fn ($rows) => ['fee_cents' => (int) $rows->sum(fn ($a) => $pay[$a->id]['fee_cents']), 'paid_cents' => (int) $rows->sum(fn ($a) => $pay[$a->id]['paid_cents'])]);

        $data['money'] = [
            'month_fee_cents' => (int) $month->sum(fn ($a) => $pay[$a->id]['fee_cents']),
            'month_collected_cents' => (int) $month->sum(fn ($a) => $pay[$a->id]['paid_cents']),
            'outstanding_cents' => (int) $owed->sum(fn ($a) => $pay[$a->id]['balance_cents']),
            'unpaid' => $owed->filter(fn ($a) => $pay[$a->id]['payment_status'] === self::TO_COLLECT)->count(),
            'partial' => $owed->filter(fn ($a) => $pay[$a->id]['payment_status'] === self::PARTIAL)->count(),
            'by_ownership' => collect(['own' => 'Condo-owned', 'borrowed' => 'Third-party', 'tba' => 'Stall to be assigned'])
                ->map(fn ($label, $key) => ['label' => $label] + ($group(fn ($a) => $a->space?->ownership ?? 'tba')[$key] ?? ['fee_cents' => 0, 'paid_cents' => 0]))->values()->all(),
            'by_booking_type' => collect(self::BOOKING_TYPES)
                ->map(fn ($label, $key) => ['label' => $label] + ($group(fn ($a) => self::bookingType($a->booking))[$key] ?? ['fee_cents' => 0, 'paid_cents' => 0]))->values()->all(),
        ];

        return $data;
    }
}
