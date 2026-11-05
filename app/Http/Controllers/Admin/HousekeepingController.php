<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Domain\Tasks\TurnoverService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\Room;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\ImageCompressor;
use App\Support\Local;
use App\Support\Money;
use App\Support\Settings;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HousekeepingController extends Controller
{
    private const MAX_PHOTOS = 10;

    private const DEFAULT_DUE_TIME = '17:00';

    // Generous on purpose: a full-size phone photo is accepted, then saved small.
    private const PHOTO_RULE = 'image|mimes:jpg,jpeg,png,webp|max:15360';

    public function __construct(private TurnoverService $turnover)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $manager = $user->can('housekeeping.manage');
        $today = Local::today();
        $date = $this->requestedDate($request);
        $tz = Local::tz();
        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay();
        $dayEnd = $dayStart->endOfDay();
        $between = [$dayStart->utc(), $dayEnd->utc()];
        $arrivals = $this->arrivalTimes($date);
        $checkOut = Settings::get('pricing.standard_check_out');
        $checkIn = (string) Settings::get('pricing.standard_check_in');
        $occupied = $this->occupiedRooms($date);

        // Serialized field by field: no guest name, payment or balance reaches housekeeping staff.
        $tasks = HousekeepingTask::with(['room:id,name,unit_code,housekeeping_status', 'assignee:id,name', 'attachments:id,housekeeping_task_id,stage,path,byte_size,mime_type', 'booking:id,actual_check_out_at', 'events:id,housekeeping_task_id,event,note'])
            ->where('status', '!=', 'cancelled')
            ->where(function ($day) use ($between, $date, $today) {
                $day->whereBetween('due_at', $between)
                    ->orWhere(fn ($done) => $done->where('status', 'accepted')->whereBetween('inspected_at', $between));
                // Today also carries everything still open, so late work cannot drop out of sight.
                if ($date === $today) {
                    $day->orWhereNotIn('status', ['accepted', 'waiting']);
                }
            })
            ->when(! $manager, fn ($q) => $q->where(function ($w) use ($user) {
                // Cleaners see their own and unclaimed work; inspectors see what is waiting for them.
                $w->whereRaw('1 = 0')
                    ->when($user->can('housekeeping.own'), fn ($own) => $own->orWhere('assigned_to', $user->id)->orWhereNull('assigned_to'))
                    ->when($user->can('housekeeping.inspect'), fn ($inspect) => $inspect->orWhere('status', 'for_inspection'));
            }))
            ->orderBy('due_at')->limit(200)->get()
            ->map(function ($t) use ($arrivals, $date, $tz, $dayStart, $dayEnd, $occupied) {
                // Only work that holds a room back has to beat the next guest's arrival.
                $arrival = $t->affectsRoom() ? ($arrivals[$t->room_id] ?? null) : null;
                $readyBy = $arrival ? CarbonImmutable::parse("{$date} {$arrival}", $tz) : $t->due_at?->toImmutable();
                $leftAt = $t->type !== 'turnover' ? null : ($t->booking?->actual_check_out_at ?? ($t->status === 'waiting' ? null : $t->created_at));

                return [
                    'id' => $t->id,
                    'key' => "task-{$t->id}",
                    'type' => $t->type,
                    'type_label' => HousekeepingTask::TYPES[$t->type] ?? null,
                    'title' => $t->title,
                    'manual' => $t->type !== 'turnover',
                    'blocks_room' => $t->blocks_room,
                    'requires_inspection' => $t->requires_inspection,
                    'guest_in' => isset($occupied[$t->room_id]),
                    'status' => $t->status,
                    'bucket' => $this->bucket($t->status, $t->started_at !== null, $readyBy),
                    'urgency' => $this->urgency($t->status, $t->started_at !== null, $readyBy, $arrival !== null),
                    'priority' => $t->priority,
                    'room' => $t->room->name,
                    'unit' => $t->room->unit_code,
                    'due_at' => $t->due_at,
                    'ready_by' => $readyBy?->toIso8601String(),
                    'has_arrival' => $arrival !== null,
                    'left_at' => $leftAt,
                    'bar' => $this->bar($t, $leftAt, $readyBy, $dayStart, $dayEnd),
                    'assignee' => $t->assignee?->name,
                    'assigned_to' => $t->assigned_to,
                    'instructions' => $t->instructions,
                    'redo_note' => $t->status === 'rejected' ? $t->events->where('event', 'inspection_failed')->last()?->note : null,
                    'checklist' => $t->checklist ?? [],
                    'notes' => $t->completion_notes,
                    'photos' => $t->attachments->count(),
                    'photo_ids' => $t->attachments->pluck('id')->values(),
                    // Sizes only: the stored path stays on the server.
                    'photo_sizes' => $t->attachments->map(fn ($a) => $a->byte_size ?? (Storage::disk('local')->exists($a->path) ? Storage::disk('local')->size($a->path) : null))->values(),
                    'started_at' => $t->started_at,
                    'completed_at' => $t->completed_at,
                ];
            });

        // Guests due out with no task yet. Only managers line up cleaners ahead of time.
        $planned = $manager ? $this->turnover->plannedTurnovers($date)->map(function ($p) use ($arrivals, $date, $tz, $checkIn, $dayStart) {
            $readyBy = CarbonImmutable::parse("{$date} ".($arrivals[$p->room_id] ?? $checkIn), $tz);

            return [
                'id' => null,
                'key' => "plan-{$p->booking_id}-{$p->room_id}",
                'booking_id' => $p->booking_id,
                'room_id' => $p->room_id,
                'status' => 'waiting',
                'bucket' => 'waiting',
                'room' => $p->room,
                'unit' => $p->unit,
                'ready_by' => $readyBy->toIso8601String(),
                'has_arrival' => isset($arrivals[$p->room_id]),
                'bar' => ['start' => $this->minutes($dayStart->setTimeFromTimeString(Settings::get('pricing.standard_check_out') ?? '12:00'), $dayStart), 'end' => $this->minutes($readyBy, $dayStart)],
                'photos' => 0,
                'photo_ids' => [],
                'checklist' => [],
            ];
        })->values() : [];

        return Inertia::render('Admin/Housekeeping', [
            'tasks' => $tasks,
            'planned' => $planned,
            'date' => $date,
            'isToday' => $date === $today,
            'prevDate' => $dayStart->subDay()->toDateString(),
            'nextDate' => $dayStart->addDay()->toDateString(),
            'nowMinute' => $date === $today ? $this->minutes(Local::now(), $dayStart) : null,
            'window' => ['check_out' => $checkOut, 'check_in' => $checkIn],
            // `cleaner` separates the people who do the work from managers who could step in, as auto-assign does.
            'staff' => $manager ? User::permission('housekeeping.own')->where('is_active', true)->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'cleaner' => ! $u->can('housekeeping.manage')])->values() : [],
            'items' => $user->can('inventory.consume') ? InventoryItem::orderBy('name')->get(['id', 'name', 'unit']) : [],
            'rooms' => $manager ? Room::where('operational_status', '!=', 'retired')->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->values() : [],
            'taskTypes' => collect(HousekeepingTask::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'can' => ['manage' => $manager, 'work' => $manager || $user->can('housekeeping.own'), 'inspect' => $user->can('housekeeping.inspect'), 'invite' => $user->can('team.manage')],
            'tabs' => $manager ? [['label' => 'Cleaning', 'href' => '/admin/housekeeping'], ['label' => 'Laundry', 'href' => '/admin/laundry']] : [],
        ]);
    }

    public function assign(Request $request, HousekeepingTask $task)
    {
        $user = $this->cleaner($request->validate(['user_id' => 'required|exists:users,id'])['user_id']);
        $this->turnover->assign($task, $user);

        return back()->with('success', "Assigned to {$user->name}. They have been notified.");
    }

    /** Any task a manager or owner wants done, on any room, with or without a checkout. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'title' => 'required|string|max:120',
            'type' => ['required', Rule::in(array_keys(HousekeepingTask::TYPES))],
            'date' => 'required|date_format:Y-m-d|after_or_equal:'.Local::today(),
            'time' => 'nullable|date_format:H:i',
            'priority' => 'nullable|in:normal,high',
            'instructions' => 'nullable|string|max:1000',
            'user_id' => 'nullable|exists:users,id',
            'requires_inspection' => 'nullable|boolean',
            'blocks_room' => 'nullable|boolean',
        ], ['date.after_or_equal' => 'Choose today or a later date.']);

        $room = Room::findOrFail($data['room_id']);
        if ($room->operational_status === 'retired') {
            throw new RoomConflict("{$room->name} is retired.");
        }
        // Holding a room back from a later date would refuse check-ins today for no reason.
        if (($data['blocks_room'] ?? false) && $data['date'] !== Local::today()) {
            throw new RoomConflict('Only a task due today can hold a room back from check-in.');
        }

        $assignee = empty($data['user_id']) ? null : $this->cleaner($data['user_id']);
        $data['due_at'] = CarbonImmutable::parse($data['date'].' '.($data['time'] ?: self::DEFAULT_DUE_TIME), Local::tz())->utc();
        DB::transaction(fn () => $this->turnover->createTask($data, $assignee));

        return back()->with('success', $assignee ? "Task added and {$assignee->name} has been notified." : 'Task added. Assign someone from the list.');
    }

    public function cancel(HousekeepingTask $task)
    {
        $this->turnover->cancelTask($task);

        return back()->with('success', 'Task cancelled.');
    }

    /** Lines up a cleaner for a guest who has not left yet. */
    public function plan(Request $request)
    {
        $data = $request->validate(['booking_id' => 'required|exists:bookings,id', 'room_id' => 'required|exists:rooms,id', 'user_id' => 'required|exists:users,id']);
        $user = $this->cleaner($data['user_id']);
        $stay = BookingRoom::where('booking_id', $data['booking_id'])->where('room_id', $data['room_id'])->whereNull('released_at')->first();
        $booking = Booking::findOrFail($data['booking_id']);
        if (! $stay || ! in_array($booking->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)) {
            throw new RoomConflict('This stay has changed. Refresh the page and try again.');
        }

        $task = $this->turnover->plan($booking, Room::findOrFail($data['room_id']), substr((string) $stay->check_out, 0, 10));
        $this->turnover->assign($task, $user);

        return back()->with('success', "Assigned to {$user->name}. They have been notified.");
    }

    public function autoAssign(Request $request)
    {
        $count = $this->turnover->assignByWorkload($this->requestedDate($request));

        return back()->with('success', $count ? "{$count} task(s) assigned by current workload. Each person has been notified." : 'Nothing is waiting to be assigned.');
    }

    private function requestedDate(Request $request): string
    {
        $date = (string) $request->input('date');
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, Local::tz()) : false;

        return $parsed && $parsed->toDateString() === $date ? $date : Local::today();
    }

    private function cleaner(int|string $id): User
    {
        $user = User::findOrFail($id);
        if (! $user->is_active || ! $user->can('housekeeping.own')) {
            throw new RoomConflict("{$user->name} cannot take cleaning work. Tick 'Housekeeping: own cleaning tasks' for them under Team first.");
        }

        return $user;
    }

    /** When each room next has to be ready that day, earliest first. Times only: nothing about the guest. */
    private function arrivalTimes(string $date): array
    {
        $standard = substr((string) Settings::get('pricing.standard_check_in'), 0, 5);

        return DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereNull('br.released_at')->where('br.check_in', $date)
            ->whereIn('b.status', [Booking::TENTATIVE, Booking::CONFIRMED])
            ->get(['br.room_id', 'b.approved_early_time'])
            ->groupBy('room_id')
            ->map(fn ($stays) => $stays->map(fn ($s) => $s->approved_early_time ? substr($s->approved_early_time, 0, 5) : $standard)->min())
            ->all();
    }

    /** Rooms with a guest staying that night, so nobody walks in unprepared. Carries no guest details. */
    private function occupiedRooms(string $date): array
    {
        return DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereNull('br.released_at')->where('b.status', Booking::CHECKED_IN)
            ->where('br.check_in', '<=', $date)->where('br.check_out', '>', $date)
            ->pluck('br.room_id')->flip()->all();
    }

    private function bucket(string $status, bool $started, ?CarbonImmutable $readyBy): string
    {
        if ($status === 'accepted') {
            return 'done';
        }
        if ($status === 'waiting') {
            return 'waiting';
        }
        // Late, or an hour from the deadline with nobody on it yet.
        $deadline = $readyBy?->subMinutes($started ? 0 : 60);
        if ($deadline && $deadline->isPast()) {
            return 'at_risk';
        }

        return $started ? 'in_progress' : 'not_started';
    }

    /** The plain answer staff read on a task: late, due soon, or nothing to worry about. */
    private function urgency(string $status, bool $started, ?CarbonImmutable $readyBy, bool $hasArrival): ?string
    {
        // Waiting for a check is only pressing when a guest is coming. The cleaner's part is already done.
        if (! $readyBy || in_array($status, ['accepted', 'waiting'], true) || ($status === 'for_inspection' && ! $hasArrival)) {
            return null;
        }
        if ($readyBy->isPast()) {
            return 'late';
        }

        return $this->bucket($status, $started, $readyBy) === 'at_risk' ? 'soon' : null;
    }

    /** Where a room's bar sits on the day's timeline, in minutes after midnight. */
    private function bar(HousekeepingTask $task, $leftAt, ?CarbonImmutable $readyBy, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $fallback = $dayStart->setTimeFromTimeString(Settings::get('pricing.standard_check_out') ?? '12:00');
        // A task with no checkout behind it sits just before its due time.
        $start = $task->started_at ?? $leftAt ?? ($task->type !== 'turnover' && $readyBy ? $readyBy->subHour() : $fallback);
        $end = $task->completed_at ?? $readyBy;
        $startMinute = $this->minutes($start, $dayStart);
        $endMinute = $end ? $this->minutes($end, $dayStart) : $startMinute + 120;

        return ['start' => $startMinute, 'end' => max($endMinute, $startMinute)];
    }

    /** Anything on another day sits at the edge of this one. */
    private function minutes($moment, CarbonImmutable $dayStart): int
    {
        return (int) max(0, min(1440, $dayStart->diffInMinutes($moment, false)));
    }

    /** Photos are shrunk first, so a bad file stops the action before anything has changed. */
    private function storePhotos(Request $request): array
    {
        $stored = [];
        try {
            foreach ($request->file('photos', []) as $photo) {
                $stored[] = ImageCompressor::storeWithMetadata($photo, 'evidence/tasks');
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_column($stored, 'path'));
            throw $e;
        }

        return $stored;
    }

    private function attach(HousekeepingTask $task, array $photos, int $userId): void
    {
        foreach ($photos as $photo) {
            TaskAttachment::create(['housekeeping_task_id' => $task->id, 'user_id' => $userId] + $photo);
        }
    }

    /** Cleaners can still add a photo after finishing, until the room has passed inspection. */
    public function addPhotos(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($request, $task);
        if (! in_array($task->status, ['in_progress', 'for_inspection'], true)) {
            throw new RoomConflict('Photos can only be added while the room is being cleaned or waiting for inspection.');
        }
        $request->validate([
            'photos' => 'required|array|min:1|max:'.max(1, self::MAX_PHOTOS - $task->attachments()->count()),
            'photos.*' => self::PHOTO_RULE,
        ], ['photos.max' => 'A room can have up to '.self::MAX_PHOTOS.' photos.']);
        $photos = $this->storePhotos($request);
        try {
            DB::transaction(fn () => $this->attach($task, $photos, $request->user()->id));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_column($photos, 'path'));
            throw $e;
        }

        return back()->with('success', 'Photo saved.');
    }

    public function start(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($request, $task);
        $this->turnover->start($task);

        return back()->with('success', 'Task started.');
    }

    public function complete(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($request, $task);
        $data = $request->validate([
            'checklist' => ($task->type === 'turnover' ? 'required' : 'nullable').'|array',
            'checklist.*.label' => 'required|string|max:120',
            'checklist.*.done' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
            'photos' => 'nullable|array|max:'.self::MAX_PHOTOS,
            'photos.*' => self::PHOTO_RULE,
            'supplies' => 'nullable|array|max:20',
            'supplies.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'supplies.*.quantity' => 'required|integer|min:1|max:1000',
        ]);
        abort_if(! empty($data['supplies']) && ! $request->user()->can('inventory.consume'), 403);

        $photos = $this->storePhotos($request);
        try {
            DB::transaction(function () use ($request, $task, $data, $photos) {
                $this->turnover->complete($task, $data['checklist'] ?? [], $data['notes'] ?? null);
                $this->turnover->recordSupplies($task, $data['supplies'] ?? [], $request->user()->id);
                $this->attach($task, $photos, $request->user()->id);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_column($photos, 'path'));
            throw $e;
        }
        if (! $task->requires_inspection) {
            return back()->with('success', 'Task finished.');
        }
        SystemNotice::toPermission('housekeeping.inspect', $task->title ? "{$task->title} at {$task->room->name} is ready for inspection." : "{$task->room->name} is ready for inspection.", '/admin/housekeeping', 'housekeeping');

        return back()->with('success', $task->affectsRoom() ? 'Sent for inspection. The room is not ready until it passes.' : 'Sent for inspection.');
    }

    public function inspect(Request $request, HousekeepingTask $task)
    {
        $data = $request->validate(['accepted' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $this->turnover->inspect($task, (bool) $data['accepted'], $data['note'] ?? null);

        return back()->with('success', $data['accepted'] ? "{$task->room->name} is now ready." : 'Sent back for re-cleaning.');
    }

    public function laundry()
    {
        $rows = LaundryJob::with(['room:id,name', 'booking:id,reference'])->orderByDesc('id')->paginate(15);
        $fields = [
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => array_map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)], ['suggested', 'collected', 'processing', 'delivered', 'cancelled'])],
            ['name' => 'quantity', 'label' => 'Sets or items', 'type' => 'number'],
            ['name' => 'provider', 'label' => 'Provider or person', 'type' => 'text'],
            ['name' => 'pickup_on', 'label' => 'Pickup', 'type' => 'date'],
            ['name' => 'delivered_on', 'label' => 'Delivered', 'type' => 'date'],
            ['name' => 'cost', 'label' => 'Cost (PHP)', 'type' => 'money'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
        ];

        return Inertia::render('Admin/Resource', [
            'title' => 'Laundry',
            'subtitle' => 'Suggested at checkout. Quantity and cost are entered per job, never assumed.',
            'tabs' => [['label' => 'Cleaning', 'href' => '/admin/housekeeping'], ['label' => 'Laundry', 'href' => '/admin/laundry']],
            'columns' => [
                ['key' => 'room', 'label' => 'Room'], ['key' => 'booking', 'label' => 'Booking'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'quantity', 'label' => 'Qty'], ['key' => 'provider', 'label' => 'Provider'], ['key' => 'pickup_on', 'label' => 'Pickup', 'type' => 'date'],
                ['key' => 'delivered_on', 'label' => 'Delivered', 'type' => 'date'], ['key' => 'cost_cents', 'label' => 'Cost', 'type' => 'money'],
            ],
            'rows' => $rows->through(fn ($j) => [
                'id' => $j->id,
                'room' => $j->room->name,
                'booking' => $j->booking?->reference,
                'status' => $j->status,
                'quantity' => $j->quantity,
                'provider' => $j->provider,
                'pickup_on' => $j->pickup_on?->toDateString(),
                'delivered_on' => $j->delivered_on?->toDateString(),
                'cost_cents' => $j->cost_cents === null ? null : (int) $j->cost_cents,
                '_actions' => [['label' => 'Update', 'url' => "/admin/laundry/{$j->id}", 'fields' => array_map(fn ($f) => $f + ['default' => $f['name'] === 'cost' ? Money::pesos($j->cost_cents === null ? null : (int) $j->cost_cents) : ($j->{$f['name']} instanceof \DateTimeInterface ? $j->{$f['name']}->format('Y-m-d') : $j->{$f['name']})], $fields)]],
            ]),
        ]);
    }

    public function updateLaundry(Request $request, LaundryJob $job)
    {
        $data = $request->validate([
            'status' => 'required|in:suggested,collected,processing,delivered,cancelled',
            'quantity' => 'nullable|integer|min:0|max:1000',
            'provider' => 'nullable|string|max:120',
            'pickup_on' => 'nullable|date_format:Y-m-d',
            'delivered_on' => 'nullable|date_format:Y-m-d',
            'cost' => ['nullable', Money::RULE],
            'notes' => 'nullable|string|max:255',
        ]);
        $data['cost_cents'] = Money::cents($data['cost'] ?? null);
        unset($data['cost']);
        $job->update($data);

        return back()->with('success', 'Laundry job updated.');
    }

    /** Own assigned or unassigned tasks only, unless the user manages housekeeping. */
    private function authorizeTask(Request $request, HousekeepingTask $task): void
    {
        $user = $request->user();
        abort_unless($user->can('housekeeping.manage') || $task->assigned_to === null || $task->assigned_to === $user->id, 403);
    }
}
