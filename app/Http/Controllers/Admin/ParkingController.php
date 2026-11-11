<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\FinanceService;
use App\Domain\Parking\ParkingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ParkingAssignment;
use App\Models\ParkingBlock;
use App\Models\ParkingSpace;
use App\Models\Property;
use App\Models\Room;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ParkingController extends Controller
{
    private const TABS = [
        ['label' => 'Calendar & reservations', 'href' => '/admin/parking'],
        ['label' => 'Spaces', 'href' => '/admin/parking/spaces'],
        ['label' => 'Remittances', 'href' => '/admin/parking/remittances'],
    ];

    private const OWNERSHIP = [
        ['value' => ParkingSpace::OWN, 'label' => 'Condo-owned (managed by us; fees are our sales)'],
        ['value' => ParkingSpace::BORROWED, 'label' => "Third-party (another owner's stall; we remit the guest's payment)"],
    ];

    private const SPACE_STATUS = [
        ['value' => ParkingSpace::ACTIVE, 'label' => 'Active'],
        ['value' => ParkingSpace::UNAVAILABLE, 'label' => 'Unavailable for now'],
        ['value' => ParkingSpace::INACTIVE, 'label' => 'Inactive (no longer used)'],
    ];

    private const METHODS = [['value' => 'cash', 'label' => 'Cash'], ['value' => 'gcash', 'label' => 'GCash'], ['value' => 'bank_transfer', 'label' => 'Bank transfer'], ['value' => 'other', 'label' => 'Other']];

    public function __construct(private ParkingService $parking)
    {
    }

    /**
     * The daily parking page: a month per stall (availability at a glance) and, below it, every reservation
     * with its fee, payment and assignment status, searchable and editable in place.
     */
    public function index(Request $request)
    {
        $f = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'q' => 'nullable|string|max:80',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'space' => 'nullable|string|max:20',
            'room' => 'nullable|integer',
            'payment' => 'nullable|in:to_collect,partially_paid,paid,no_fee',
            'assignment' => ['nullable', Rule::in(array_keys(ParkingAssignment::STATUSES))],
            'type' => 'nullable|in:airbnb,direct,none',
            'ownership' => 'nullable|in:own,borrowed,tba',
            'view' => 'nullable|in:open,past,all',
        ]);
        $user = $request->user();
        $today = Local::today();
        $start = CarbonImmutable::parse(($f['month'] ?? Local::now()->format('Y-m')).'-01');
        $end = $start->addMonth();
        $spaces = ParkingSpace::with('property:id,name')->where('status', '!=', ParkingSpace::INACTIVE)->orderBy('property_id')->orderBy('code')->get();
        $inMonth = fn ($q) => $q->where('start_date', '<', $end->toDateString())->where('end_date', '>', $start->toDateString());

        $bars = ParkingAssignment::live()->tap($inMonth)->with('booking:id,reference')->orderBy('start_date')->get();
        $state = fn (ParkingAssignment $a) => $this->timing($a, $today);

        return Inertia::render('Admin/ParkingCalendar', [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'prev' => $start->subMonth()->format('Y-m'),
            'next' => $end->format('Y-m'),
            'days' => $start->daysInMonth,
            'today' => $today,
            'nowLocal' => Local::now()->format('Y-m-d\TH:i'),
            'tabs' => self::TABS,
            'spaces' => $spaces->map(fn ($s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'property' => $s->property->name, 'borrowed' => $s->isBorrowed(), 'ownership' => $s->ownershipLabel(),
                'owner' => $s->vendor_name, 'contact' => $user->can('parking.manage') ? $s->vendor_contact : null, 'status' => $s->status,
            ]),
            'assignments' => $bars->map(fn ($a) => [
                'id' => $a->id, 'space_id' => $a->parking_space_id, 'holder' => $a->holder_name, 'vehicle' => $a->vehicle, 'start' => $a->start_date->toDateString(), 'end' => $a->end_date->toDateString(),
                'booking' => $a->booking?->reference, 'booking_id' => $a->booking_id, 'status' => $a->status, 'state' => $state($a),
            ]),
            'blocks' => ParkingBlock::tap($inMonth)->orderBy('start_date')->get()->map(fn ($b) => [
                'id' => $b->id, 'space_id' => $b->parking_space_id, 'start' => $b->start_date->toDateString(), 'end' => $b->end_date->toDateString(), 'reason' => $b->reason,
                'reason_label' => ParkingBlock::REASONS[$b->reason] ?? $b->reason, 'note' => $b->note,
            ]),
            'summary' => $this->summary($f, $today),
            'filters' => array_diff_key($f, ['month' => true]) + ['view' => 'open'],
            'options' => [
                'spaces' => $spaces->map(fn ($s) => ['value' => $s->id, 'label' => $s->label()])->prepend(['value' => 'tba', 'label' => 'To be assigned'])->values(),
                'rooms' => Room::suites()->where('operational_status', '!=', 'retired')->orderBy('id')->get(['id', 'name', 'unit_code'])->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->name} ({$r->unit_code})"]),
                'payment' => collect(ParkingService::PAYMENT_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'assignment' => collect(ParkingAssignment::STATUSES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'type' => collect(ParkingService::BOOKING_TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'ownership' => [['value' => 'own', 'label' => 'Condo-owned'], ['value' => 'borrowed', 'label' => 'Third-party'], ['value' => 'tba', 'label' => 'Stall to be assigned']],
                'blockReasons' => collect(ParkingBlock::REASONS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'methods' => self::METHODS,
            ],
            'reservations' => $user->can('parking.manage') ? $this->reservationOptions($today) : [],
            'defaultRate' => Money::pesos((int) Settings::get('pricing.parking_night_cents')),
        ]);
    }

    /** Old links to the calendar land on the main parking page, same month. */
    public function calendar(Request $request)
    {
        return redirect('/admin/parking'.($request->filled('month') ? '?'.http_build_query(['month' => $request->input('month')]) : ''));
    }

    /** occupied | upcoming | pending_slot | ended | cancelled: what staff need to tell apart at a glance. */
    private function timing(ParkingAssignment $a, string $today): string
    {
        return match (true) {
            $a->status === ParkingAssignment::CANCELLED => 'cancelled',
            $a->end_date->toDateString() <= $today => 'ended',
            $a->status !== ParkingAssignment::ASSIGNED => 'pending_slot',
            $a->start_date->toDateString() <= $today => 'occupied',
            default => 'upcoming',
        };
    }

    private function summary(array $f, string $today): array
    {
        $view = $f['view'] ?? 'open';
        $rows = ParkingAssignment::with(['space:id,code,name,ownership,vendor_name', 'booking:id,reference,guest_id,booking_source_id,check_in,check_out,status', 'booking.guest:id,full_name', 'booking.source:id,code,name', 'booking.activeRooms.room:id,name,unit_code', 'booking.bookingRooms.room:id,name,unit_code'])
            ->when($view === 'open', fn ($q) => $q->live()->where('end_date', '>', $today))
            ->when($view === 'past', fn ($q) => $q->where(fn ($w) => $w->where('end_date', '<=', $today)->orWhere('status', ParkingAssignment::CANCELLED)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('end_date', '>', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('start_date', '<=', $v))
            ->when($f['assignment'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($f['space'] ?? null) === 'tba', fn ($q) => $q->whereNull('parking_space_id'))
            ->when(ctype_digit((string) ($f['space'] ?? '')), fn ($q) => $q->where('parking_space_id', (int) $f['space']))
            ->when($f['ownership'] ?? null, fn ($q, $v) => $v === 'tba' ? $q->whereNull('parking_space_id') : $q->whereHas('space', fn ($s) => $s->where('ownership', $v)))
            ->when($f['room'] ?? null, fn ($q, $v) => $q->whereHas('booking.bookingRooms', fn ($r) => $r->where('room_id', $v)))
            ->when($f['type'] ?? null, fn ($q, $v) => match ($v) {
                'none' => $q->whereNull('booking_id'),
                'airbnb' => $q->whereHas('booking.source', fn ($s) => $s->where('code', 'airbnb')),
                default => $q->whereNotNull('booking_id')->whereDoesntHave('booking.source', fn ($s) => $s->where('code', 'airbnb')),
            })
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('holder_name', 'like', "%{$v}%")->orWhere('vehicle', 'like', "%{$v}%")
                ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', "%{$v}%")->orWhereHas('guest', fn ($g) => $g->where('full_name', 'like', "%{$v}%")))))
            ->orderByRaw($view === 'past' ? 'start_date DESC' : 'start_date ASC')->orderBy('id')->limit(500)->get();

        $pay = $this->parking->payments($rows);
        if ($f['payment'] ?? null) {
            $rows = $rows->filter(fn ($a) => $pay[$a->id]['payment_status'] === $f['payment'])->values();
        }

        return $rows->map(function (ParkingAssignment $a) use ($pay, $today) {
            $b = $a->booking;
            $rooms = $b ? ($b->activeRooms->isNotEmpty() ? $b->activeRooms : $b->bookingRooms)->map(fn ($r) => $r->room->name)->unique()->join(', ') : null;

            return [
                'id' => $a->id,
                'guest' => $a->holder_name,
                'booking' => $b ? ['id' => $b->id, 'reference' => $b->reference, 'guest' => $b->guest?->full_name, 'check_in' => $b->check_in->toDateString(), 'check_out' => $b->check_out->toDateString(), 'source' => $b->source?->name] : null,
                'rooms' => $rooms,
                'space_id' => $a->parking_space_id,
                'slot' => $a->space?->label(),
                'ownership' => $a->space ? $a->space->ownership : 'tba',
                'owner' => $a->space?->isBorrowed() ? $a->space->vendor_name : null,
                'start' => $a->start_date->toDateString(),
                'end' => $a->end_date->toDateString(),
                'nights' => $a->nights,
                'rate' => Money::pesos($a->rate_cents),
                'vehicle' => $a->vehicle,
                'notes' => $a->notes,
                'status' => $a->status,
                'timing' => $this->timing($a, $today),
                'booking_type' => ParkingService::bookingType($b),
                'remitted' => $a->remitted_on !== null,
            ] + $pay[$a->id];
        })->values()->all();
    }

    /** Reservations a parking request can be attached to: current and upcoming, newest check-in last. */
    private function reservationOptions(string $today): array
    {
        return Booking::with(['guest:id,full_name', 'source:id,code,name', 'activeRooms.room:id,name,unit_code'])
            ->whereIn('status', [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN])
            ->where('check_out', '>=', $today)->whereHas('property', fn ($p) => $p->where('category', Property::SUITES))
            ->orderBy('check_in')->limit(400)->get()
            ->map(fn (Booking $b) => self::reservationOption($b))->all();
    }

    public static function reservationOption(Booking $b): array
    {
        return [
            'id' => $b->id, 'reference' => $b->reference, 'guest' => $b->guest?->full_name, 'rooms' => $b->activeRooms->map(fn ($r) => $r->room->name)->join(', '),
            'check_in' => $b->check_in->toDateString(), 'check_out' => $b->check_out->toDateString(), 'status' => $b->status, 'airbnb' => $b->source?->code === 'airbnb',
        ];
    }

    /** Which stalls are free for the dates, live, so staff only pick one that can take them. */
    public function availability(Request $request)
    {
        $d = $request->validate(['start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d', 'ignore' => 'nullable|integer']);

        return response()->json(['spaces' => $this->parking->availability($d['start'] ?? null, $d['end'] ?? null, $d['ignore'] ?? null)])->header('Cache-Control', 'no-store');
    }

    public function storeAssignment(Request $request)
    {
        $assignment = $this->parking->save(null, $this->assignmentData($request), $request->user()->id);

        return back()->with('success', $assignment->status === ParkingAssignment::ASSIGNED ? 'Parking assigned. The stall is blocked for those dates.' : 'Parking request saved. It does not hold a stall until it is assigned.');
    }

    public function updateAssignment(Request $request, ParkingAssignment $assignment)
    {
        abort_if($assignment->status === ParkingAssignment::CANCELLED, 422, 'This parking was cancelled. Add a new one instead.');
        $this->parking->save($assignment, $this->assignmentData($request), $request->user()->id);

        return back()->with('success', 'Parking updated.');
    }

    private function assignmentData(Request $request): array
    {
        $d = $request->validate([
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'holder_name' => 'required_without:booking_id|nullable|string|max:120',
            'parking_space_id' => 'nullable|integer|exists:parking_spaces,id',
            'status' => ['required', Rule::in([ParkingAssignment::REQUESTED, ParkingAssignment::PENDING, ParkingAssignment::ASSIGNED])],
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'rate' => ['nullable', Money::RULE],
            'vehicle' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
            'owner_confirmed' => 'nullable|boolean',
        ], ['end_date.after' => 'Parking ends after it starts: the end date is the morning the car leaves.']);
        $booking = ! empty($d['booking_id']) ? Booking::with('guest:id,full_name')->find($d['booking_id']) : null;

        return [
            'booking_id' => $booking?->id,
            'holder_name' => trim((string) ($d['holder_name'] ?? '')) ?: $booking?->guest?->full_name,
            'parking_space_id' => $d['parking_space_id'] ?? null,
            'status' => $d['status'],
            'start_date' => $d['start_date'],
            'end_date' => $d['end_date'],
            'rate_cents' => Money::cents($d['rate'] ?? null),
            'vehicle' => $d['vehicle'] ?? null,
            'notes' => $d['notes'] ?? null,
            'owner_confirmed' => (bool) ($d['owner_confirmed'] ?? false),
        ];
    }

    public function cancelAssignment(Request $request, ParkingAssignment $assignment)
    {
        $this->parking->cancel($assignment, $request->user()->id);

        return back()->with('success', 'Parking cancelled. Its stall and charge were released.');
    }

    public function storeBlock(Request $request)
    {
        $this->parking->saveBlock(null, $this->blockData($request), $request->user()->id);

        return back()->with('success', 'Stall blocked for those dates.');
    }

    public function updateBlock(Request $request, ParkingBlock $block)
    {
        $this->parking->saveBlock($block, $this->blockData($request), $request->user()->id);

        return back()->with('success', 'Block updated.');
    }

    public function destroyBlock(ParkingBlock $block)
    {
        Audit::log('parking.unblocked', $block, ['space' => $block->parking_space_id, 'from' => $block->start_date->toDateString(), 'to' => $block->end_date->toDateString()]);
        $block->delete();

        return back()->with('success', 'Block removed. The stall is available again for those dates.');
    }

    private function blockData(Request $request): array
    {
        return $request->validate([
            'parking_space_id' => 'required|integer|exists:parking_spaces,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'reason' => ['required', Rule::in(array_keys(ParkingBlock::REASONS))],
            'note' => 'nullable|string|max:255',
        ], ['end_date.after' => 'The block ends after it starts: the end date is the first day the stall is free again.']);
    }

    /** The stalls themselves: who owns each, its rate, and whether it is in use. */
    public function spaces(Request $request)
    {
        $today = Local::today();
        $spaces = ParkingSpace::with(['property:id,name', 'assignments' => fn ($q) => $q->blocking()->where('end_date', '>', $today)->orderBy('start_date')])->orderByRaw("status = 'inactive'")->orderBy('property_id')->orderBy('code')->get();
        $canManage = $request->user()->can('parking.manage');
        $properties = Property::where('category', Property::SUITES)->orderBy('id')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all();
        $fields = fn (?ParkingSpace $s) => [
            ...($s ? [] : [['name' => 'property_id', 'label' => 'Property', 'type' => 'select', 'required' => true, 'options' => $properties]]),
            ['name' => 'code', 'label' => 'Slot number', 'type' => 'text', 'required' => true, 'default' => $s?->code],
            ['name' => 'name', 'label' => 'Parking name (optional)', 'type' => 'text', 'default' => $s?->name, 'help' => 'For example Basement 2, near elevator.'],
            ['name' => 'ownership', 'label' => 'Ownership', 'type' => 'select', 'required' => true, 'options' => self::OWNERSHIP, 'default' => $s?->ownership ?? ParkingSpace::OWN],
            ['name' => 'vendor_name', 'label' => 'Owner name', 'type' => 'text', 'default' => $s?->vendor_name, 'help' => 'Required for a third-party stall: the owner who receives the parking money.'],
            ['name' => 'vendor_contact', 'label' => 'Owner contact (staff only)', 'type' => 'text', 'default' => $s?->vendor_contact],
            ['name' => 'rate', 'label' => 'Rate per night (PHP)', 'type' => 'money', 'default' => Money::pesos($s?->rate_cents)],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => self::SPACE_STATUS, 'default' => $s?->status ?? ParkingSpace::ACTIVE],
            ['name' => 'restrictions', 'label' => 'Restrictions', 'type' => 'text', 'default' => $s?->restrictions, 'help' => 'For example: sedan only, no SUVs.'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'default' => $s?->notes],
        ];

        return Inertia::render('Admin/Resource', [
            'title' => 'Parking spaces',
            'tabs' => self::TABS,
            'subtitle' => 'Condo-owned stalls are ours to rent out. A third-party stall belongs to another owner: the guest still pays us, and that money is remitted to the owner under Remittances and not counted as sales. Owner contacts stay staff-only.',
            'columns' => [
                ['key' => 'space', 'label' => 'Slot'], ['key' => 'name', 'label' => 'Name'], ['key' => 'property', 'label' => 'Property'], ['key' => 'ownership', 'label' => 'Ownership', 'type' => 'status'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'today', 'label' => 'Today'],
                ['key' => 'vendor', 'label' => 'Owner'], ['key' => 'contact', 'label' => 'Contact'], ['key' => 'rate_cents', 'label' => 'Rate / night', 'type' => 'money'], ['key' => 'upcoming', 'label' => 'Assigned'],
            ],
            'rows' => $spaces->map(fn (ParkingSpace $s) => [
                'id' => 's'.$s->id,
                'space' => $s->code,
                'name' => $s->name ?: '—',
                'property' => $s->property->name,
                'ownership' => $s->isBorrowed() ? 'third_party' : 'condo_owned',
                'status' => $s->status,
                'today' => $this->todayFor($s, $today, $canManage),
                'vendor' => $s->vendor_name ?: '—',
                'contact' => $canManage ? $s->vendor_contact : null,
                'rate_cents' => $s->rate_cents === null ? null : (int) $s->rate_cents,
                'upcoming' => $s->assignments->map(fn ($a) => "{$a->start_date->format('M j')} to {$a->end_date->format('M j')}")->join('; ') ?: '—',
                '_actions' => $canManage ? [['label' => 'Edit', 'url' => "/admin/parking/spaces/{$s->id}", 'fields' => $fields($s)]] : [],
            ])->values(),
            'create' => $canManage ? ['label' => 'Add space', 'url' => '/admin/parking/spaces', 'fields' => $fields(null)] : null,
        ]);
    }

    /**
     * Whether a stall is free today. Condo-owned: we know from our own assignments and blocks.
     * Third-party: we only know about our guests; the owner may be using it, so staff must call to check.
     */
    private function todayFor(ParkingSpace $space, string $today, bool $showContact): string
    {
        if (! $space->isActive()) {
            return $space->status === ParkingSpace::INACTIVE ? 'Inactive' : 'Unavailable';
        }
        $now = $space->assignments->first(fn ($a) => $a->start_date->toDateString() <= $today && $a->end_date->toDateString() > $today);
        if ($now) {
            return "In use: {$now->holder_name} until {$now->end_date->format('M j')}";
        }
        if (ParkingBlock::where('parking_space_id', $space->id)->where('start_date', '<=', $today)->where('end_date', '>', $today)->exists()) {
            return 'Blocked today';
        }
        if (! $space->isBorrowed()) {
            return 'Free';
        }
        $contact = $showContact && $space->vendor_contact ? " ({$space->vendor_contact})" : '';

        return 'Not ours: call '.($space->vendor_name ?: 'the owner').$contact.' to check';
    }

    public function storeSpace(Request $request)
    {
        $data = $this->spaceData($request, null);
        $space = ParkingSpace::create($data);
        Audit::log('parking.space_added', $space, ['code' => $space->code]);

        return back()->with('success', "Parking space {$space->code} added.");
    }

    /** Changing a stall's owner only affects new assignments; ones already made keep what they owe. */
    public function updateSpace(Request $request, ParkingSpace $space)
    {
        $data = $this->spaceData($request, $space);
        if ($data['status'] !== ParkingSpace::ACTIVE && $space->status === ParkingSpace::ACTIVE
            && ($next = ParkingAssignment::blocking()->where('parking_space_id', $space->id)->where('end_date', '>', Local::today())->orderBy('start_date')->first())) {
            throw ValidationException::withMessages(['status' => "{$space->code} is assigned to {$next->holder_name} from {$next->start_date->format('M j')}. Move that parking to another stall first."]);
        }
        $space->update($data);
        Audit::log('parking.space_updated', $space, ['ownership' => $space->ownership, 'status' => $space->status]);

        return back()->with('success', "{$space->code} saved.");
    }

    private function spaceData(Request $request, ?ParkingSpace $space): array
    {
        $propertyId = $space?->property_id ?? $request->input('property_id');
        $d = $request->validate([
            'property_id' => $space ? 'nullable' : 'required|exists:properties,id',
            'code' => ['required', 'string', 'max:60', Rule::unique('parking_spaces', 'code')->where('property_id', $propertyId)->ignore($space?->id)],
            'name' => 'nullable|string|max:120',
            'ownership' => 'required|in:own,borrowed',
            'vendor_name' => 'nullable|string|max:120',
            'vendor_contact' => 'nullable|string|max:60',
            'rate' => ['nullable', Money::RULE],
            'cost' => ['nullable', Money::RULE],
            'status' => 'nullable|in:active,unavailable,inactive',
            'restrictions' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:255',
        ], ['code.unique' => 'This property already has a stall with that number.']);
        // Remittances need a name: who the money goes to.
        if ($d['ownership'] === ParkingSpace::BORROWED && trim((string) ($d['vendor_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['vendor_name' => 'Enter the name of the owner who lends this stall.']);
        }

        return array_filter([
            'property_id' => $space ? null : $d['property_id'],
        ]) + [
            'code' => trim($d['code']), 'name' => $d['name'] ?? null, 'ownership' => $d['ownership'], 'vendor_name' => $d['vendor_name'] ?? null, 'vendor_contact' => $d['vendor_contact'] ?? null,
            'rate_cents' => Money::cents($d['rate'] ?? null), 'status' => $d['status'] ?? ($space?->status ?? ParkingSpace::ACTIVE),
            'restrictions' => $d['restrictions'] ?? null, 'notes' => $d['notes'] ?? null,
        ] + (array_key_exists('cost', $d) ? ['cost_cents' => Money::cents($d['cost'])] : []);
    }

    /** What is owed to owners who lent a stall, and what was already paid to them. */
    public function remittances(Request $request)
    {
        $canManage = $request->user()->can('parking.manage');
        $today = Local::today();
        $rows = ParkingAssignment::with(['space.property:id,name', 'booking:id,reference', 'charge:id,voided_at'])
            ->whereNotNull('remit_cents')->orderByRaw('remitted_on IS NOT NULL')->orderByDesc('start_date')->limit(300)->get();

        $state = fn (ParkingAssignment $a) => match (true) {
            $a->remitted_on !== null => 'remitted',
            ! $a->isLive() || $a->charge === null || $a->charge->voided_at !== null => 'not_owed',
            default => 'to_remit',
        };
        $owed = $rows->filter(fn ($a) => $state($a) === 'to_remit');

        return Inertia::render('Admin/Resource', [
            'title' => 'Parking remittances',
            'tabs' => self::TABS,
            'subtitle' => 'Parking on a third-party stall: the guest pays us, and we pass the money to the owner who lent it. It is not counted as sales. Mark it remitted once the owner is paid; it then shows in the Cashbook as money out.',
            'stats' => [
                ['label' => 'To remit', 'value' => (int) $owed->sum('remit_cents'), 'type' => 'money'],
                ['label' => 'Owners waiting', 'value' => $owed->pluck('remit_to')->unique()->count()],
                ['label' => 'Remitted this month', 'value' => (int) $rows->filter(fn ($a) => $a->remitted_on && $a->remitted_on->format('Y-m') === substr($today, 0, 7))->sum('remitted_cents'), 'type' => 'money'],
            ],
            'columns' => [
                ['key' => 'owner', 'label' => 'Stall owner'], ['key' => 'space', 'label' => 'Space'], ['key' => 'booking', 'label' => 'Booking', 'type' => 'link'], ['key' => 'holder', 'label' => 'Guest'],
                ['key' => 'dates', 'label' => 'Dates'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'state', 'label' => 'Status', 'type' => 'status'], ['key' => 'paid', 'label' => 'Remitted'],
            ],
            'rows' => $rows->map(fn (ParkingAssignment $a) => [
                'id' => $a->id,
                'owner' => $a->remit_to ?: 'Stall owner',
                'space' => $a->space ? "{$a->space->code} ({$a->space->property->name})" : 'To be assigned',
                'booking' => $a->booking ? ['label' => $a->booking->reference, 'href' => "/admin/bookings/{$a->booking_id}"] : null,
                'holder' => $a->holder_name,
                'dates' => "{$a->start_date->format('M j')} to {$a->end_date->format('M j, Y')}",
                'amount' => (int) ($a->remitted_cents ?? $a->remit_cents),
                'state' => $state($a),
                'paid' => $a->remitted_on ? $a->remitted_on->format('M j, Y').' · '.ucfirst(str_replace('_', ' ', (string) $a->remit_method)).($a->remit_reference ? " · {$a->remit_reference}" : '') : ($state($a) === 'not_owed' ? 'Nothing owed: cancelled or charge voided' : '—'),
                '_actions' => ! $canManage ? [] : match ($state($a)) {
                    'to_remit' => [['label' => 'Mark remitted', 'variant' => 'primary', 'url' => "/admin/parking/assignments/{$a->id}/remit", 'fields' => [
                        ['name' => 'remitted_on', 'label' => 'Paid on', 'type' => 'date', 'required' => true, 'default' => $today],
                        ['name' => 'amount', 'label' => 'Amount paid to '.($a->remit_to ?: 'the owner').' (PHP)', 'type' => 'money', 'required' => true, 'default' => Money::pesos((int) $a->remit_cents)],
                        ['name' => 'method', 'label' => 'How', 'type' => 'select', 'required' => true, 'options' => self::METHODS, 'default' => 'cash'],
                        ['name' => 'reference', 'label' => 'Reference (optional)', 'type' => 'text', 'help' => 'GCash or bank reference, or a note.'],
                    ]]],
                    'remitted' => [['label' => 'Undo', 'url' => "/admin/parking/assignments/{$a->id}/unremit", 'confirm' => 'Mark this as not yet remitted? It will leave the Cashbook.', 'variant' => 'danger']],
                    default => [],
                },
            ]),
        ]);
    }

    public function remit(Request $request, ParkingAssignment $assignment, FinanceService $finance)
    {
        $data = $request->validate([
            'remitted_on' => 'required|date_format:Y-m-d|before_or_equal:'.Local::today(),
            'amount' => ['required', Money::RULE],
            'method' => 'required|in:cash,gcash,bank_transfer,other',
            'reference' => 'nullable|string|max:120',
        ]);
        $cents = Money::cents($data['amount']);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount paid to the stall owner.']);
        }
        if (! ParkingAssignment::toRemit()->whereKey($assignment->id)->exists()) {
            throw ValidationException::withMessages(['amount' => 'Nothing is owed for this parking: it was already remitted, cancelled, or its charge was voided.']);
        }
        $finance->assertOpen($data['remitted_on']);

        $assignment->update([
            'remitted_on' => $data['remitted_on'], 'remitted_cents' => $cents, 'remit_method' => $data['method'],
            'remit_reference' => trim((string) ($data['reference'] ?? '')) ?: null, 'remitted_by' => $request->user()->id,
        ]);
        Audit::log('parking.remitted', $assignment, ['cents' => $cents, 'to' => $assignment->remit_to]);

        return back()->with('success', 'Remitted '.Money::format($cents).' to '.($assignment->remit_to ?: 'the stall owner').'. It now shows in the Cashbook.');
    }

    public function unremit(ParkingAssignment $assignment, FinanceService $finance)
    {
        abort_unless($assignment->remitted_on !== null, 422, 'This parking is not marked remitted.');
        $finance->assertOpen($assignment->remitted_on->toDateString());
        Audit::log('parking.remit_undone', $assignment, ['cents' => $assignment->remitted_cents]);
        $assignment->update(['remitted_on' => null, 'remitted_cents' => null, 'remit_method' => null, 'remit_reference' => null, 'remitted_by' => null]);

        return back()->with('success', 'Marked as not yet remitted.');
    }
}
