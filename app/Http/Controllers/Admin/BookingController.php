<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\MessageService;
use App\Domain\Finance\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\ParkingAssignment;
use App\Models\Room;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings, private FinanceService $finance, private MessageService $messages)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate(['status' => 'nullable|string|max:30', 'source' => 'nullable|integer', 'q' => 'nullable|string|max:80', 'arrivals' => 'nullable|string|max:10', 'room' => 'nullable|integer']);

        $rows = Booking::with(['guest:id,full_name', 'source:id,name', 'activeRooms.room:id,name'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('booking_source_id', $v))
            ->when($f['room'] ?? null, fn ($q, $v) => $q->whereHas('activeRooms', fn ($r) => $r->where('room_id', $v)))
            ->when(($f['arrivals'] ?? null) === 'today', fn ($q) => $q->where('check_in', Local::today())->where('status', Booking::CONFIRMED))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$v}%")->orWhereHas('guest', fn ($g) => $g->where('full_name', 'like', "%{$v}%"))))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        $rows->getCollection()->transform(fn ($b) => [
            'id' => $b->id,
            'reference' => $b->reference,
            'guest' => $b->guest->full_name,
            'rooms' => $b->activeRooms->pluck('room.name')->join(', '),
            'source' => $b->source->name,
            'status' => $b->status,
            'check_in' => $b->check_in->toDateString(),
            'check_out' => $b->check_out->toDateString(),
            'total_cents' => (int) $b->total_cents,
            'hold_expires_at' => $b->hold_expires_at,
        ]);

        return Inertia::render('Admin/BookingList', [
            'bookings' => $rows,
            'filters' => $f,
            'sources' => BookingSource::orderBy('name')->get(['id', 'name']),
            'statuses' => [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT, Booking::CANCELLED, Booking::NO_SHOW, Booking::EXPIRED],
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Admin/BookingForm', [
            'rooms' => Room::where('operational_status', '!=', 'retired')->with('property:id,name')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'label' => "{$r->name} ({$r->unit_code}), {$r->property->name}", 'max' => $r->max_guests, 'included' => $r->included_guests]),
            'sources' => BookingSource::where('is_active', true)->where('is_legacy_only', false)->orderBy('id')->get(['id', 'name']),
            'guests' => Guest::orderByDesc('id')->limit(200)->get(['id', 'full_name', 'phone']),
            'prefill' => $request->only(['room_id', 'check_in', 'check_out']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'guest_id' => 'nullable|exists:guests,id',
            'guest_name' => 'required_without:guest_id|nullable|string|max:120',
            'guest_phone' => 'nullable|string|max:40',
            'guest_email' => 'nullable|email|max:120',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'booking_source_id' => 'required|exists:booking_sources,id',
            'source_reference' => 'nullable|string|max:120',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'adults' => 'required|integer|min:1|max:30',
            'children' => 'nullable|integer|min:0|max:30',
            'approved_early_time' => 'nullable|date_format:H:i',
            'special_requests' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:60',
            'then' => 'nullable|in:hold',
        ]);

        if (empty($data['guest_id'])) {
            $data['guest_id'] = Guest::create(['full_name' => $data['guest_name'], 'phone' => $data['guest_phone'] ?? null, 'email' => $data['guest_email'] ?? null])->id;
        }

        $booking = $this->bookings->createInquiry($data);

        if (($data['then'] ?? null) === 'hold') {
            $this->bookings->hold($booking);
        }

        return redirect("/admin/bookings/{$booking->id}")->with('success', "Booking {$booking->reference} created.");
    }

    public function show(Request $request, Booking $booking)
    {
        $booking->load(['guest', 'source:id,name', 'property:id,name', 'activeRooms.room:id,name,unit_code,housekeeping_status,included_guests,max_guests', 'statusEvents.user:id,name', 'snapshots']);
        $user = $request->user();
        $canPay = $user->can('payments.view');

        return Inertia::render('Admin/BookingShow', [
            'booking' => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
                'guest' => $booking->guest->only(['id', 'full_name', 'phone', 'email', 'messenger_handle']),
                'source' => $booking->source->name,
                'source_reference' => $booking->source_reference,
                'property' => $booking->property->name,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'adults' => $booking->adults,
                'children' => $booking->children,
                'guest_count' => $booking->guest_count,
                'approved_early_time' => $booking->approved_early_time ? substr($booking->approved_early_time, 0, 5) : null,
                'actual_check_in_at' => $booking->actual_check_in_at,
                'actual_check_out_at' => $booking->actual_check_out_at,
                'hold_expires_at' => $booking->hold_expires_at,
                'special_requests' => $booking->special_requests,
                'internal_notes' => $booking->internal_notes,
                'total_cents' => (int) $booking->total_cents,
                'pricing_version' => $booking->pricing_version,
                'rooms' => $booking->activeRooms->map(fn ($r) => ['id' => $r->room_id, 'name' => $r->room->name, 'unit' => $r->room->unit_code, 'housekeeping' => $r->room->housekeeping_status]),
            ],
            'charges' => $booking->charges()->orderByDesc('id')->get(['id', 'type', 'description', 'quantity', 'amount_cents', 'pricing_version', 'voided_at', 'void_reason']),
            'balance' => $canPay ? $this->finance->balance($booking) : null,
            'receipts' => $canPay ? $booking->receipts()->orderByDesc('id')->get(['id', 'reference', 'receipt_date', 'amount_cents', 'method', 'external_reference', 'verification_status', 'evidence_path']) : [],
            'settlements' => $canPay ? $booking->settlements()->orderByDesc('id')->get() : [],
            'refunds' => $canPay ? $booking->refunds()->orderByDesc('id')->get() : [],
            'events' => $booking->statusEvents->map(fn ($e) => ['from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note, 'by' => $e->user?->name, 'at' => $e->created_at]),
            'snapshots' => $booking->snapshots->map(fn ($s) => ['version' => $s->version, 'total_cents' => (int) $s->total_cents, 'reason' => $s->reason, 'at' => $s->created_at]),
            'parking' => ParkingAssignment::where('booking_id', $booking->id)->with('space:id,code')->get()->map(fn ($p) => ['code' => $p->space->code, 'start' => $p->start_date->toDateString(), 'end' => $p->end_date->toDateString(), 'status' => $p->status]),
            'messages' => $this->messages->renderAll($booking),
            'rooms' => Room::where('operational_status', '!=', 'retired')->orderBy('id')->get(['id', 'name', 'unit_code']),
        ]);
    }

    public function hold(Request $request, Booking $booking)
    {
        $hours = $request->validate(['hours' => 'nullable|integer|min:1|max:336'])['hours'] ?? null;
        $this->bookings->hold($booking, $hours);

        return back()->with('success', 'Hold placed. The dates are blocked until it expires.');
    }

    public function confirm(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'discount' => ['nullable', Money::RULE],
            'discount_reason' => 'required_with:discount|nullable|string|max:200',
            'parking' => ['nullable', Money::RULE],
        ]);
        $discount = Money::cents($data['discount'] ?? null);
        if ($discount && ! $request->user()->can('bookings.discount')) {
            abort(403, 'You are not authorized to apply discounts.');
        }

        $this->bookings->confirm($booking, [
            'discount_cents' => $discount ?? 0,
            'discount_reason' => $data['discount_reason'] ?? null,
            'parking_cents' => Money::cents($data['parking'] ?? null) ?? 0,
        ]);

        return back()->with('success', 'Booking confirmed and price locked.');
    }

    public function cancel(Request $request, Booking $booking)
    {
        $this->bookings->cancel($booking, $request->validate(['reason' => 'required|string|max:255'])['reason']);

        return back()->with('success', 'Booking cancelled; its dates are released.');
    }

    public function noShow(Request $request, Booking $booking)
    {
        $this->bookings->markNoShow($booking, $request->validate(['note' => 'nullable|string|max:255'])['note'] ?? null);

        return back()->with('success', 'Marked as no-show.');
    }

    public function checkIn(Request $request, Booking $booking)
    {
        $data = $request->validate(['override' => 'nullable|boolean', 'override_note' => 'required_if:override,true|nullable|string|max:255']);
        $override = (bool) ($data['override'] ?? false);
        if ($override && ! $request->user()->can('bookings.override_readiness')) {
            abort(403, 'You are not authorized to override room readiness.');
        }
        $this->bookings->checkIn($booking, $override, $data['override_note'] ?? null);

        return back()->with('success', 'Guest checked in.');
    }

    public function checkOut(Booking $booking)
    {
        $this->bookings->checkOut($booking);

        return back()->with('success', 'Checked out. Turnover work has been created; the room is not ready until inspected.');
    }

    public function amend(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'room_ids' => 'nullable|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'adults' => 'nullable|integer|min:1|max:30',
            'children' => 'nullable|integer|min:0|max:30',
            'approved_early_time' => 'nullable|date_format:H:i',
            'room_id_primary' => 'nullable|integer|exists:rooms,id',
            'reason' => 'required|string|max:255',
        ]);
        $data = array_filter($data, fn ($v) => $v !== null);
        if (isset($data['room_id_primary'])) {
            $data['room_ids'] = [(int) $data['room_id_primary']];
        }
        unset($data['room_id_primary']);
        $this->bookings->amend($booking, $data);

        return back()->with('success', 'Booking updated. Conflicts were rechecked and the price re-versioned.');
    }

    public function addCharge(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'type' => 'required|in:parking,service,late_check_out,adjustment,discount',
            'description' => 'required|string|max:200',
            'amount' => ['required', Money::RULE],
        ]);
        if (in_array($data['type'], ['discount', 'adjustment'], true) && ! $request->user()->can('bookings.discount')) {
            abort(403, 'You are not authorized to add discounts or adjustments.');
        }
        $this->finance->assertOpen(Local::today());

        $cents = Money::cents($data['amount']);
        $cents = $data['type'] === 'discount' ? -$cents : $cents;
        $charge = $booking->charges()->create([
            'type' => $data['type'],
            'description' => $data['description'],
            'unit_cents' => $cents,
            'amount_cents' => $cents,
            'charge_date' => Local::today(),
            'pricing_version' => $booking->pricing_version,
            'approved_by' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);
        $this->bookings->syncTotal($booking);
        Audit::log('charge.added', $charge, ['booking' => $booking->reference, 'amount_cents' => $cents]);

        return back()->with('success', 'Charge added. The guest balance has changed.');
    }

    public function voidCharge(Request $request, BookingCharge $charge)
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];
        if ($charge->charge_date) {
            $this->finance->assertOpen($charge->charge_date->toDateString());
        }
        $charge->update(['voided_at' => now(), 'void_reason' => $reason]);
        $this->bookings->syncTotal($charge->booking);
        Audit::log('charge.voided', $charge, ['reason' => $reason]);

        return back()->with('success', 'Charge voided.');
    }
}
