<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\MessageService;
use App\Domain\Finance\FinanceService;
use App\Domain\Parking\ParkingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\ParkingAssignment;
use App\Models\Property;
use App\Models\Room;
use App\Support\AdminCategory;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use App\Domain\Bookings\VacancySearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings, private FinanceService $finance, private MessageService $messages, private ParkingService $parking)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => 'nullable|string|max:30',
            'source' => 'nullable|integer',
            'q' => 'nullable|string|max:80',
            'arrivals' => 'nullable|string|max:10',
            'room' => 'nullable|integer',
            'view' => 'nullable|in:all,open,arriving,in_house,unpaid,past',
            'payment' => 'nullable|in:unpaid,review,paid,overpaid',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|in:newest,check_in,check_in_desc,balance',
        ]);
        $canPay = $request->user()->can('payments.view');
        // Money filters and sorting only for people who may see payments.
        if (! $canPay) {
            unset($f['payment']);
            if (($f['view'] ?? null) === 'unpaid') {
                unset($f['view']);
            }
            if (($f['sort'] ?? null) === 'balance') {
                unset($f['sort']);
            }
        }

        $today = Local::today();
        $base = fn () => $this->withMoney(AdminCategory::bookings(Booking::query()));
        $filtered = $this->applyListFilters($base(), $f, $today);

        $sorted = match ($f['sort'] ?? null) {
            'check_in' => $filtered->orderBy('check_in')->orderBy('id'),
            'check_in_desc' => $filtered->orderByDesc('check_in')->orderByDesc('id'),
            'balance' => $filtered->orderByDesc('balance_cents')->orderBy('check_in'),
            default => $filtered->orderByDesc('id'),
        };

        $rows = $sorted->with(['guest:id,full_name,phone', 'source:id,name', 'activeRooms.room:id,name'])->paginate(20)->withQueryString();

        $rows->getCollection()->transform(fn ($b) => [
            'id' => $b->id,
            'reference' => $b->reference,
            'guest' => $b->guest->full_name,
            'phone' => $b->guest->phone,
            'rooms' => $b->activeRooms->pluck('room.name')->join(', '),
            'source' => $b->source->name,
            'status' => $b->status,
            'check_in' => $b->check_in->toDateString(),
            'check_out' => $b->check_out->toDateString(),
            'nights' => (int) $b->check_in->diffInDays($b->check_out),
            'total_cents' => (int) $b->total_cents,
            'hold_expires_at' => $b->hold_expires_at,
            'charges_cents' => $canPay ? (int) $b->charges_cents : null,
            'paid_cents' => $canPay ? (int) $b->paid_cents : null,
            'pending_cents' => $canPay ? (int) $b->pending_cents : null,
            'balance_cents' => $canPay ? (int) $b->balance_cents : null,
        ]);

        // Counts for the quick views, so staff can see at a glance where work is waiting.
        $views = ['all', 'open', 'arriving', 'in_house', 'past'];
        if ($canPay) {
            $views[] = 'unpaid';
        }
        $counts = [];
        foreach ($views as $v) {
            $counts[$v] = $this->applyListFilters($base(), ['view' => $v], $today)->count();
        }

        $summary = null;
        if ($canPay) {
            $owed = $this->applyListFilters($base(), ['view' => 'unpaid'], $today);
            $summary = [
                'unpaid_count' => $counts['unpaid'],
                'unpaid_cents' => (int) DB::query()->fromSub($owed, 'b')->sum('balance_cents'),
                'pending_cents' => (int) DB::query()->fromSub($this->applyListFilters($base(), ['view' => 'unpaid'], $today), 'b')->sum('pending_cents'),
                'filtered_balance_cents' => (int) DB::query()->fromSub($this->applyListFilters($base(), $f, $today), 'b')->where('balance_cents', '>', 0)->whereNotIn('status', self::UNPRICED)->sum('balance_cents'),
            ];
        }

        return Inertia::render('Admin/BookingList', [
            'bookings' => $rows,
            'filters' => $f,
            'counts' => $counts,
            'summary' => $summary,
            'canPay' => $canPay,
            'sources' => BookingSource::orderBy('name')->get(['id', 'name']),
            'statuses' => [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT, Booking::CANCELLED, Booking::NO_SHOW, Booking::EXPIRED],
        ]);
    }

    /** Not priced yet, so there is nothing to collect. */
    private const UNPRICED = [Booking::INQUIRY, Booking::TENTATIVE, Booking::EXPIRED];

    /**
     * Adds charges, paid, pending and balance as columns, using the same rules as FinanceService::balance(),
     * so the list can be filtered and sorted by money owed without loading every booking.
     */
    private function money(): array
    {
        $charges = '(select coalesce(sum(c.amount_cents), 0) from booking_charges c where c.booking_id = bookings.id and c.voided_at is null)';
        $verified = "(select coalesce(sum(r.amount_cents), 0) from receipts r where r.booking_id = bookings.id and r.verification_status = 'verified')";
        $pending = "(select coalesce(sum(r.amount_cents), 0) from receipts r where r.booking_id = bookings.id and r.verification_status = 'pending')";
        $refunded = "(select coalesce(sum(f.amount_cents), 0) from refunds f where f.booking_id = bookings.id and f.status = 'executed')";
        $settled = '(select coalesce(sum(s.gross_cents), 0) from external_settlements s where s.booking_id = bookings.id and s.actual_payout_cents is not null)';
        $paid = "({$verified} + {$settled} - {$refunded})";

        return ['charges' => $charges, 'paid' => $paid, 'pending' => $pending, 'balance' => "({$charges} - {$paid})"];
    }

    private function withMoney($query)
    {
        $m = $this->money();

        return $query->select('bookings.*')
            ->selectRaw("{$m['charges']} as charges_cents")
            ->selectRaw("{$m['paid']} as paid_cents")
            ->selectRaw("{$m['pending']} as pending_cents")
            ->selectRaw("{$m['balance']} as balance_cents");
    }

    private function applyListFilters($query, array $f, string $today)
    {
        $m = $this->money();
        $live = [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN];
        $owing = fn ($q) => $q->whereNotIn('status', self::UNPRICED)->whereRaw("{$m['balance']} > 0");

        $query = match ($f['view'] ?? null) {
            // Still needs action: inquiries, holds and confirmed stays that have not ended.
            'open' => $query->whereIn('status', [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED])->where('check_out', '>=', $today),
            'arriving' => $query->where('status', Booking::CONFIRMED)->whereBetween('check_in', [$today, Local::now()->addDays(7)->toDateString()]),
            'in_house' => $query->where('status', Booking::CHECKED_IN),
            'past' => $query->where(fn ($w) => $w->whereNotIn('status', $live)->orWhere(fn ($o) => $o->whereIn('status', $live)->where('check_out', '<', $today))),
            'unpaid' => $owing($query),
            default => $query,
        };

        $payment = $f['payment'] ?? null;

        return $query
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('booking_source_id', $v))
            ->when($f['room'] ?? null, fn ($q, $v) => $q->whereHas('activeRooms', fn ($r) => $r->where('room_id', $v)))
            ->when(($f['arrivals'] ?? null) === 'today', fn ($q) => $q->where('check_in', $today)->where('status', Booking::CONFIRMED))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('check_out', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('check_in', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$v}%")->orWhereHas('guest', fn ($g) => $g->where('full_name', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%"))))
            ->when($payment === 'unpaid', $owing)
            ->when($payment === 'review', fn ($q) => $q->whereRaw("{$m['pending']} > 0"))
            ->when($payment === 'paid', fn ($q) => $q->whereRaw("{$m['charges']} > 0")->whereRaw("{$m['balance']} = 0"))
            ->when($payment === 'overpaid', fn ($q) => $q->whereRaw("{$m['balance']} < 0"));
    }

    /** The form is a modal now. Old links and bookmarks open it on Reservations. */
    public function create(Request $request)
    {
        return redirect('/admin/bookings?'.http_build_query(['new' => 1] + $request->only(['room_id', 'check_in', 'check_out'])));
    }

    public function formOptions()
    {
        return response()->json([
            'rooms' => AdminCategory::rooms(Room::where('operational_status', '!=', 'retired'))->with('property:id,name,category')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'label' => "{$r->name} ({$r->unit_code}), {$r->property->name}", 'property_id' => $r->property_id, 'property' => $r->property->name, 'category' => $r->property->category, 'max' => $r->max_guests, 'included' => $r->included_guests]),
            'sources' => BookingSource::where('is_active', true)->where('is_legacy_only', false)->orderBy('id')->get(['id', 'code', 'name']),
            'guests' => Guest::where(fn ($w) => $w->whereHas('bookings', fn ($b) => AdminCategory::bookings($b))->orWhereDoesntHave('bookings'))->orderByDesc('id')->limit(200)->get(['id', 'full_name', 'phone']),
            'today' => Local::today(),
            'standard_check_in' => (string) Settings::get('pricing.standard_check_in'),
            'parking_rate' => Money::pesos((int) Settings::get('pricing.parking_night_cents')),
        ])->header('Cache-Control', 'no-store');
    }

    /** Which rooms cannot take these dates, and why. Capacity is left out: a group can share several rooms. */
    public function availability(Request $request, VacancySearch $vacancies)
    {
        $data = $request->validate(['check_in' => 'required|date_format:Y-m-d|after_or_equal:'.Local::today(), 'check_out' => 'required|date_format:Y-m-d|after:check_in', 'ignore_booking' => 'nullable|integer|exists:bookings,id']);

        $taken = [];
        foreach (Room::where('operational_status', '!=', 'retired')->orderBy('id')->get() as $room) {
            $reason = $vacancies->reasonUnavailable($room, $data['check_in'], $data['check_out'], 1, $data['ignore_booking'] ?? null);
            if ($reason) {
                $taken[$room->id] = $reason;
            }
        }

        return response()->json(['taken' => (object) $taken])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, VacancySearch $vacancies)
    {
        $data = $request->validate([
            'guest_id' => 'nullable|exists:guests,id',
            'guest_name' => 'required_without:guest_id|nullable|string|max:120',
            'guest_phone' => 'nullable|string|max:40',
            'guest_email' => 'nullable|email|max:120',
            'guest_messenger_handle' => 'nullable|string|max:120',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'booking_source_id' => 'required|exists:booking_sources,id',
            'source_reference' => 'nullable|string|max:120',
            'check_in' => 'required|date_format:Y-m-d|after_or_equal:'.Local::today(),
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'adults' => 'required|integer|min:1|max:30',
            'children' => 'nullable|integer|min:0|max:30',
            'approved_early_time' => 'nullable|date_format:H:i',
            'pickup_time' => 'nullable|date_format:H:i',
            'return_time' => 'nullable|date_format:H:i',
            'special_requests' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:60',
            'then' => 'nullable|in:hold,confirm',
            'quote_id' => 'nullable|integer|exists:quotes,id',
            'channel_total' => ['nullable', Money::RULE],
            // Parking asked for with the reservation: recorded as a request (no stall yet) with its own fee.
            'parking' => 'nullable|boolean',
            'parking_start' => 'required_if:parking,true|nullable|date_format:Y-m-d',
            'parking_end' => 'required_if:parking,true|nullable|date_format:Y-m-d|after:parking_start',
            'parking_rate' => ['required_if:parking,true', 'nullable', Money::RULE],
            'parking_vehicle' => 'nullable|string|max:80',
        ], ['channel_total.regex' => 'Enter the Airbnb total as an amount, for example 4500 or 4500.50.', 'parking_end.after' => 'Parking ends after it starts.']);
        abort_if(($data['then'] ?? null) === 'confirm' && ! $request->user()->can('bookings.confirm'), 403);

        if (empty($data['guest_id']) && empty($data['guest_phone']) && empty($data['guest_email']) && empty($data['guest_messenger_handle'])) {
            throw ValidationException::withMessages(['guest_phone' => 'Enter at least one contact method: phone, email, or Messenger.']);
        }

        $standardCheckIn = (string) Settings::get('pricing.standard_check_in');
        if (! empty($data['approved_early_time']) && $data['approved_early_time'] >= $standardCheckIn) {
            throw ValidationException::withMessages(['approved_early_time' => "Early check-in must be before {$standardCheckIn}."]);
        }

        $existing = ! empty($data['idempotency_key']) ? Booking::where('idempotency_key', $data['idempotency_key'])->first() : null;
        if ($existing) {
            return back()->with('success', "Booking {$existing->reference} is already saved.")
                ->with('flash_action', ['label' => 'View booking', 'href' => "/admin/bookings/{$existing->id}"]);
        }

        $rooms = Room::whereIn('id', $data['room_ids'])->orderBy('id')->get();
        if ($rooms->pluck('property_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['room_ids' => 'All rooms in one booking must be at the same property. Create a separate booking for another property.']);
        }
        // Airbnb sets its own price: the stay costs the total shown on Airbnb, not the nightly rates.
        $data['channel_total_cents'] = null;
        if ($this->isAirbnb((int) $data['booking_source_id']) && ! $rooms->first()?->isCamera()) {
            $data['channel_total_cents'] = Money::cents($data['channel_total'] ?? null);
            if ($data['channel_total_cents'] === null || $data['channel_total_cents'] <= 0) {
                throw ValidationException::withMessages(['channel_total' => 'Enter the total the guest pays on Airbnb.']);
            }
        }
        $party = (int) $data['adults'] + (int) ($data['children'] ?? 0);
        $capacity = (int) $rooms->sum('max_guests');
        if ($party > $capacity) {
            throw ValidationException::withMessages(['room_ids' => "The selected room(s) allow at most {$capacity} guests; {$party} requested."]);
        }

        // The dates and rooms are checked again immediately before creating anything.
        foreach ($rooms as $room) {
            $reason = $vacancies->reasonUnavailable($room, $data['check_in'], $data['check_out'], 1);
            if ($reason) {
                throw ValidationException::withMessages(['room_ids' => "{$room->name} cannot be booked for these dates. {$reason}"]);
            }
        }

        try {
            $booking = DB::transaction(function () use (&$data) {
                if (empty($data['guest_id'])) {
                    $data['guest_id'] = Guest::create(['full_name' => $data['guest_name'], 'phone' => $data['guest_phone'] ?? null, 'email' => $data['guest_email'] ?? null, 'messenger_handle' => $data['guest_messenger_handle'] ?? null])->id;
                }

                $booking = $this->bookings->createInquiry($data);
                if (! empty($data['parking'])) {
                    $this->parking->save(null, [
                        'booking_id' => $booking->id, 'holder_name' => $booking->guest->full_name, 'vehicle' => $data['parking_vehicle'] ?? null,
                        'status' => \App\Models\ParkingAssignment::REQUESTED, 'start_date' => $data['parking_start'], 'end_date' => $data['parking_end'],
                        'rate_cents' => Money::cents($data['parking_rate'] ?? null),
                    ], auth()->id());
                }
                // A booking made from a quote is linked to it, so the quote no longer offers Create booking.
                if (! empty($data['quote_id'])) {
                    \App\Models\Quote::whereKey($data['quote_id'])->whereNull('booking_id')->update(['booking_id' => $booking->id, 'guest_id' => $booking->guest_id]);
                }

                return $booking;
            });
        } catch (RoomConflict $e) {
            throw ValidationException::withMessages(['room_ids' => $e->getMessage()]);
        }

        // Stays on the page it was opened from. A repeated click finds the booking already done.
        $view = ['label' => 'View booking', 'href' => "/admin/bookings/{$booking->id}"];
        try {
            match ($data['then'] ?? null) {
                'hold' => $this->bookings->hold($booking),
                'confirm' => $this->bookings->confirm($booking),
                default => null,
            };
        } catch (RoomConflict $e) {
            return back()->with('error', "Booking {$booking->reference} was saved as an inquiry, but not ".(($data['then'] ?? null) === 'hold' ? 'held' : 'confirmed').": {$e->getMessage()}")->with('flash_action', $view);
        }

        $done = ['hold' => 'saved and on hold', 'confirm' => 'confirmed'][$data['then'] ?? ''] ?? 'saved as an inquiry';

        return back()->with('success', "Booking {$booking->reference} {$done}.")->with('flash_action', $view);
    }

    public function show(Request $request, Booking $booking)
    {
        // A link to a rental (or a stay) opens in its own business, so the menu and lists match it.
        $request->session()->put('admin_category', $booking->property()->value('category'));
        $booking->load(['guest', 'source:id,name,code', 'property:id,name,category', 'activeRooms.room:id,name,unit_code,housekeeping_status,included_guests,max_guests', 'statusEvents.user:id,name', 'snapshots']);
        $user = $request->user();
        $canPay = $user->can('payments.view');

        return Inertia::render('Admin/BookingShow', [
            'booking' => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
                'guest' => $booking->guest->only(['id', 'full_name', 'phone', 'email', 'messenger_handle']),
                'source' => $booking->source->name,
                'source_code' => $booking->source->code,
                'booking_source_id' => $booking->booking_source_id,
                'source_reference' => $booking->source_reference,
                'cancellation_reason' => $booking->cancellation_reason,
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
                'channel_total_cents' => $booking->channel_total_cents !== null ? (int) $booking->channel_total_cents : null,
                'is_camera' => $booking->property->category === Property::CAMERAS,
                'pickup_time' => $booking->requested_arrival_time ? substr($booking->requested_arrival_time, 0, 5) : null,
                'return_time' => $booking->return_time ? substr($booking->return_time, 0, 5) : null,
                'deposit_cents' => (int) $booking->deposit_cents,
                'deposit_status' => $booking->deposit_status,
                'deposit_note' => $booking->deposit_note,
                'id_type' => $booking->id_type,
                'id_status' => $booking->id_status,
                'rooms' => $booking->activeRooms->map(fn ($r) => ['id' => $r->room_id, 'name' => $r->room->name, 'unit' => $r->room->unit_code, 'housekeeping' => $r->room->housekeeping_status]),
                // A cancelled or no-show booking has released its rooms; show the ones it was for.
                'released_rooms' => $booking->activeRooms->isEmpty() ? $this->lastRooms($booking) : [],
            ],
            'sources' => BookingSource::where('is_active', true)->where('is_legacy_only', false)->orWhere('id', $booking->booking_source_id)->orderBy('id')->get(['id', 'code', 'name']),
            'charges' => $booking->charges()->orderByDesc('id')->get(['id', 'type', 'description', 'quantity', 'amount_cents', 'pricing_version', 'voided_at', 'void_reason']),
            'balance' => $canPay ? $this->finance->balance($booking) : null,
            'estimate' => $this->bookings->estimate($booking),
            'receipts' => $canPay ? $this->receiptRows($booking) : [],
            'checkout' => $this->checkoutWarning($booking, $canPay),
            'nowLocal' => Local::now()->format('Y-m-d\TH:i'),
            'today' => Local::today(),
            'standardCheckIn' => (string) Settings::get('pricing.standard_check_in'),
            'downpaymentPercent' => (int) Settings::get('finance.downpayment_percent') ?: null,
            'settlements' => $canPay ? $booking->settlements()->orderByDesc('id')->get() : [],
            'refunds' => $canPay ? $booking->refunds()->orderByDesc('id')->get() : [],
            'events' => $booking->statusEvents->map(fn ($e) => ['from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note, 'by' => $e->user?->name, 'at' => $e->created_at]),
            'snapshots' => $booking->snapshots->map(fn ($s) => ['version' => $s->version, 'total_cents' => (int) $s->total_cents, 'reason' => $s->reason, 'at' => $s->created_at]),
            'parking' => $this->parkingRows($booking),
            'parkingSplit' => $canPay && $booking->property->category !== Property::CAMERAS ? $this->parking->bookingSplit($booking, $this->finance->balance($booking)) : null,
            'parkingForm' => $booking->property->category === Property::CAMERAS ? null : [
                'reservation' => ParkingController::reservationOption($booking->loadMissing('activeRooms.room')),
                'defaultRate' => Money::pesos((int) Settings::get('pricing.parking_night_cents')),
                'methods' => [['value' => 'cash', 'label' => 'Cash'], ['value' => 'gcash', 'label' => 'GCash'], ['value' => 'bank_transfer', 'label' => 'Bank transfer'], ['value' => 'other', 'label' => 'Other']],
            ],
            'messages' => $this->messages->renderAll($booking),
            'rooms' => Room::where('operational_status', '!=', 'retired')->whereHas('property', fn ($p) => $p->where('category', $booking->property->category))->orderBy('id')->get(['id', 'name', 'unit_code', 'max_guests']),
        ]);
    }

    /** The rooms a booking last held before they were released. */
    private function lastRooms(Booking $booking): array
    {
        $last = $booking->bookingRooms()->max('released_at');

        return $last === null ? [] : $booking->bookingRooms()->with('room:id,name,unit_code')->where('released_at', $last)->orderBy('room_id')->get()
            ->map(fn ($r) => ['id' => $r->room_id, 'name' => $r->room->name, 'unit' => $r->room->unit_code])->all();
    }

    /**
     * Everything about a booking that is not dates, rooms or party: where it came from, its reference and notes.
     * Notes and the reference can be corrected at any time. A source change reprices a confirmed stay,
     * because Airbnb stays are priced from the Airbnb total and the others from the rates.
     */
    public function details(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'booking_source_id' => 'required|exists:booking_sources,id',
            'source_reference' => 'nullable|string|max:120',
            'special_requests' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
            'channel_total' => ['nullable', Money::RULE],
        ], ['channel_total.regex' => 'Enter the Airbnb total as an amount, for example 4500 or 4500.50.']);

        $sourceId = (int) $data['booking_source_id'];
        $camera = $booking->property->category === Property::CAMERAS;
        $channelTotal = null;
        if ($this->isAirbnb($sourceId) && ! $camera) {
            $channelTotal = Money::cents($data['channel_total'] ?? null);
            if ($channelTotal === null || $channelTotal <= 0) {
                throw ValidationException::withMessages(['channel_total' => 'Enter the total the guest pays on Airbnb.']);
            }
        }

        $priceChanges = $sourceId !== (int) $booking->booking_source_id || $channelTotal !== ($booking->channel_total_cents === null ? null : (int) $booking->channel_total_cents);
        if ($priceChanges && ! in_array($booking->status, [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN], true)) {
            throw ValidationException::withMessages(['booking_source_id' => 'The source and Airbnb total cannot change once a booking is '.str_replace('_', ' ', $booking->status).'. Notes and the reference can still be edited.']);
        }

        DB::transaction(function () use ($booking, $data, $sourceId, $channelTotal, $priceChanges) {
            $booking->update([
                'source_reference' => trim((string) ($data['source_reference'] ?? '')) ?: null,
                'special_requests' => trim((string) ($data['special_requests'] ?? '')) ?: null,
                'internal_notes' => trim((string) ($data['internal_notes'] ?? '')) ?: null,
            ]);
            if ($priceChanges) {
                // Goes through amend, so a priced stay is repriced and a closed month is protected.
                $this->bookings->amend($booking, ['booking_source_id' => $sourceId, 'channel_total_cents' => $channelTotal, 'reason' => 'Booking source or Airbnb total changed']);
            }
            Audit::log('booking.details_updated', $booking, ['booking_source_id' => $sourceId, 'channel_total_cents' => $channelTotal]);
        });

        return back()->with('success', $priceChanges && in_array($booking->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)
            ? 'Details saved. The price was recalculated for the new source.'
            : 'Details saved.');
    }

    private function receiptRows(Booking $booking): array
    {
        $reversed = $booking->receipts()->whereNotNull('reverses_receipt_id')->pluck('reverses_receipt_id');

        return $booking->receipts()->orderByDesc('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'reference' => $r->reference,
            'receipt_date' => $r->receipt_date->toDateString(),
            'amount_cents' => (int) $r->amount_cents,
            'method' => $r->method,
            'external_reference' => $r->external_reference,
            'verification_status' => $r->verification_status,
            'review_note' => $r->review_note,
            'reverses_receipt_id' => $r->reverses_receipt_id,
            'reversed' => $reversed->contains($r->id),
            'has_proof' => $r->evidence_path !== null,
        ])->all();
    }

    /** Amounts only for people who may see payments. Everyone who checks a guest out is told there is a balance. */
    private function checkoutWarning(Booking $booking, bool $canPay): array
    {
        $unpaid = $booking->status === Booking::CHECKED_IN ? $this->unpaidAtCheckOut($booking) : 0;

        return [
            'unpaid' => $unpaid > 0,
            'balance_cents' => $canPay && $unpaid > 0 ? $unpaid : null,
            'pending_cents' => $canPay && $unpaid > 0 ? (int) $this->finance->balance($booking)['pending_cents'] : 0,
        ];
    }

    /** Each parking on the booking with its own fee, payment and assignment status. */
    private function parkingRows(Booking $booking): array
    {
        $rows = ParkingAssignment::where('booking_id', $booking->id)->with('space:id,code,name,ownership,vendor_name')->orderBy('start_date')->get();
        $pay = $this->parking->payments($rows);

        return $rows->map(fn (ParkingAssignment $p) => [
            'id' => $p->id, 'slot' => $p->space?->label(), 'space_id' => $p->parking_space_id, 'start' => $p->start_date->toDateString(), 'end' => $p->end_date->toDateString(),
            'nights' => $p->nights, 'rate' => Money::pesos($p->rate_cents), 'status' => $p->status, 'vehicle' => $p->vehicle, 'notes' => $p->notes, 'guest' => $p->holder_name,
            'borrowed' => (bool) $p->space?->isBorrowed(), 'owner' => $p->space?->vendor_name, 'remitted' => $p->remitted_on !== null,
        ] + $pay[$p->id])->all();
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

        return back()->with('success', $booking->property->category === Property::CAMERAS ? 'Camera marked as picked up.' : 'Guest checked in.');
    }

    /** Unpaid money never stops a guest leaving, but it needs a reason, so it cannot be forgotten by accident. */
    public function checkOut(Request $request, Booking $booking)
    {
        $unpaid = $booking->status === Booking::CHECKED_IN ? $this->unpaidAtCheckOut($booking) : 0;
        if ($unpaid <= 0) {
            $this->bookings->checkOut($booking);

            return back()->with('success', $booking->property->category === Property::CAMERAS
                ? 'Camera marked as returned. Hand back the renter\'s ID if you kept it.'
                : 'Checked out. Turnover work has been created; the room is not ready until inspected.');
        }

        $reason = $request->validate(['unpaid_reason' => 'required|string|max:200'], ['unpaid_reason.required' => 'This booking still has a balance. Say why it is not fully paid.'])['unpaid_reason'];
        $this->bookings->checkOut($booking, 'Checked out with '.Money::format($unpaid)." unpaid: {$reason}");

        return back()->with('success', $request->user()->can('payments.view')
            ? 'Checked out with '.Money::format($unpaid).' unpaid. It stays on your list of money to collect.'
            : 'Checked out. The unpaid balance stays on the booking.');
    }

    private function isAirbnb(int $sourceId): bool
    {
        return BookingSource::whereKey($sourceId)->value('code') === 'airbnb';
    }

    /** What the guest still owes here, regardless of where the booking came from. */
    private function unpaidAtCheckOut(Booking $booking): int
    {
        return max(0, $this->finance->balance($booking)['balance_cents']);
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
            'channel_total' => ['nullable', Money::RULE],
            'reason' => 'required|string|max:255',
        ], ['channel_total.regex' => 'Enter the Airbnb total as an amount, for example 4500 or 4500.50.']);
        // An Airbnb booking keeps a fixed total; changing dates on Airbnb usually changes it, so it can be corrected here.
        unset($data['channel_total']);
        if ($booking->channel_total_cents !== null && $request->filled('channel_total')) {
            $cents = Money::cents($request->input('channel_total'));
            if ($cents <= 0) {
                throw ValidationException::withMessages(['channel_total' => 'Enter the total the guest pays on Airbnb.']);
            }
            $data['channel_total_cents'] = $cents;
        }
        if ($booking->status !== Booking::CHECKED_IN && isset($data['check_in']) && $data['check_in'] < Local::today()) {
            throw ValidationException::withMessages(['check_in' => 'Check-in cannot be before today.']);
        }
        $standardCheckIn = substr((string) Settings::get('pricing.standard_check_in'), 0, 5);
        if (! empty($data['approved_early_time']) && $data['approved_early_time'] >= $standardCheckIn) {
            throw ValidationException::withMessages(['approved_early_time' => "Early check-in must be before {$standardCheckIn}."]);
        }
        $data = array_filter($data, fn ($v) => $v !== null);
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

    /** Camera rentals: the renter's ID, checked at pickup and handed back on return. Downpayments are agreed in Messenger and recorded as payments. */
    public function rentalSecurity(Request $request, Booking $booking)
    {
        abort_unless($booking->property->category === Property::CAMERAS, 422, 'The ID check applies to camera rentals only.');
        $data = $request->validate([
            'id_type' => 'nullable|string|max:80',
            'id_status' => 'required|in:none,held,returned',
        ]);

        $booking->update($data);
        Audit::log('booking.rental_security', $booking, $data);

        return back()->with('success', 'ID check updated.');
    }
}
