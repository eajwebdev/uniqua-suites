<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tasks\TurnoverService;
use App\Http\Controllers\Controller;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\Money;
use App\Support\SystemNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HousekeepingController extends Controller
{
    public function __construct(private TurnoverService $turnover)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $manager = $user->can('housekeeping.manage');

        // Serialized field by field: no guest name, payment or balance reaches housekeeping staff.
        $tasks = HousekeepingTask::with(['room:id,name,unit_code,housekeeping_status', 'assignee:id,name', 'attachments:id,housekeeping_task_id,stage'])
            ->whereNotIn('status', ['accepted'])
            ->when(! $manager, fn ($q) => $q->where(function ($w) use ($user) {
                // Cleaners see their own and unclaimed work; inspectors see what is waiting for them.
                $w->whereRaw('1 = 0')
                    ->when($user->can('housekeeping.own'), fn ($own) => $own->orWhere('assigned_to', $user->id)->orWhereNull('assigned_to'))
                    ->when($user->can('housekeeping.inspect'), fn ($inspect) => $inspect->orWhere('status', 'for_inspection'));
            }))
            ->orderBy('due_at')->limit(100)->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'status' => $t->status,
                'priority' => $t->priority,
                'room' => $t->room->name,
                'unit' => $t->room->unit_code,
                'due_at' => $t->due_at,
                'overdue' => $t->due_at && $t->due_at->isPast() && in_array($t->status, ['pending', 'assigned', 'in_progress'], true),
                'assignee' => $t->assignee?->name,
                'assigned_to' => $t->assigned_to,
                'instructions' => $t->instructions,
                'checklist' => $t->checklist ?? [],
                'notes' => $t->completion_notes,
                'photos' => $t->attachments->count(),
                'started_at' => $t->started_at,
                'completed_at' => $t->completed_at,
            ]);

        return Inertia::render('Admin/Housekeeping', [
            'tasks' => $tasks,
            'staff' => $manager ? User::permission('housekeeping.own')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'items' => $user->can('inventory.consume') ? InventoryItem::orderBy('name')->get(['id', 'name', 'unit']) : [],
            'can' => ['manage' => $manager, 'work' => $manager || $user->can('housekeeping.own'), 'inspect' => $user->can('housekeeping.inspect')],
            'tabs' => $manager ? [['label' => 'Tasks', 'href' => '/admin/housekeeping'], ['label' => 'Laundry', 'href' => '/admin/laundry']] : [],
        ]);
    }

    public function assign(Request $request, HousekeepingTask $task)
    {
        $user = User::findOrFail($request->validate(['user_id' => 'required|exists:users,id'])['user_id']);
        $this->turnover->assign($task, $user);
        $user->notify(new SystemNotice("Cleaning assigned: {$task->room->name}", '/admin/housekeeping'));

        return back()->with('success', "Assigned to {$user->name}.");
    }

    public function autoAssign()
    {
        $count = $this->turnover->assignByWorkload();

        return back()->with('success', $count ? "{$count} task(s) assigned by current workload." : 'Nothing is waiting to be assigned.');
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
            'checklist' => 'required|array',
            'checklist.*.label' => 'required|string|max:120',
            'checklist.*.done' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:8192',
            'supplies' => 'nullable|array|max:20',
            'supplies.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'supplies.*.quantity' => 'required|integer|min:1|max:1000',
        ]);
        abort_if(! empty($data['supplies']) && ! $request->user()->can('inventory.consume'), 403);

        DB::transaction(function () use ($request, $task, $data) {
            $this->turnover->complete($task, $data['checklist'], $data['notes'] ?? null);
            $this->turnover->recordSupplies($task, $data['supplies'] ?? [], $request->user()->id);
        });
        foreach ($request->file('photos', []) as $photo) {
            TaskAttachment::create(['housekeeping_task_id' => $task->id, 'path' => $photo->store('evidence/tasks', 'local'), 'user_id' => $request->user()->id]);
        }
        SystemNotice::toPermission('housekeeping.inspect', "{$task->room->name} is ready for inspection.", '/admin/housekeeping', 'housekeeping');

        return back()->with('success', 'Sent for inspection. The room is not ready until it passes.');
    }

    public function inspect(Request $request, HousekeepingTask $task)
    {
        $data = $request->validate(['accepted' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $this->turnover->inspect($task, (bool) $data['accepted'], $data['note'] ?? null);

        return back()->with('success', $data['accepted'] ? "{$task->room->name} is now ready." : 'Sent back for re-cleaning.');
    }

    public function laundry()
    {
        $rows = LaundryJob::with(['room:id,name', 'booking:id,reference'])->orderByDesc('id')->paginate(30);
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
            'tabs' => [['label' => 'Tasks', 'href' => '/admin/housekeeping'], ['label' => 'Laundry', 'href' => '/admin/laundry']],
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
