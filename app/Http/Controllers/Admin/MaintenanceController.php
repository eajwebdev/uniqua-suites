<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCategory;
use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Domain\Finance\FinanceService;
use App\Domain\Finance\RoomExpenses;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Incident;
use App\Models\IncidentNote;
use App\Models\IncidentEvidence;
use App\Models\Room;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use App\Support\SystemNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MaintenanceController extends Controller
{
    /** Priority labels and their order: red for High and Urgent so staff see what needs action first. */
    public const PRIORITIES = ['critical' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];

    private const PENDING = ['open', 'in_progress'];

    private const SOURCES = ['staff' => 'Staff', 'guest_complaint' => 'Guest complaint', 'inspection' => 'Inspection'];

    public function workOrders(Request $request)
    {
        $user = $request->user();
        $manager = $user->can('maintenance.manage');
        $f = $request->validate([
            'view' => 'nullable|in:pending,completed,all',
            'priority' => 'nullable|in:critical,high,normal,low,urgent',
            'room' => 'nullable|integer',
        ]);
        $view = $f['view'] ?? 'pending';
        $mine = fn ($q) => AdminCategory::workOrders($q)->when(! $manager, fn ($w) => $w->where('assigned_to', $user->id));

        $rows = $mine(WorkOrder::query())->with(['room:id,name,unit_code', 'assignee:id,name', 'vendor:id,name', 'booking:id,reference'])
            ->when($view === 'pending', fn ($q) => $q->whereIn('status', self::PENDING))
            ->when($view === 'completed', fn ($q) => $q->whereNotIn('status', self::PENDING))
            ->when(($f['priority'] ?? null) === 'urgent', fn ($q) => $q->whereIn('severity', ['critical', 'high']))
            ->when(in_array($f['priority'] ?? null, ['critical', 'high', 'normal', 'low'], true), fn ($q) => $q->where('severity', $f['priority']))
            ->when($f['room'] ?? null, fn ($q, $v) => $q->where('room_id', $v))
            // Pending first, then the reddest, then what is due soonest.
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("FIELD(severity,'critical','high','normal','low')")
            ->orderByDesc('safety_block')
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $pending = $mine(WorkOrder::query())->whereIn('status', self::PENDING);
        $monthStart = Local::now()->startOfMonth();
        // Which jobs are already in Room costs, in a few queries rather than one per row.
        $expenses = app(RoomExpenses::class);
        $pageIds = $rows->getCollection()->pluck('id')->all();
        $recordedIds = $expenses->recordedIds('work_order_id', $pageIds);
        $byIncident = Incident::whereIn('work_order_id', $pageIds)->pluck('work_order_id', 'id')->all();
        $incidentRecorded = [];
        foreach (array_keys($expenses->recordedIds('incident_id', array_keys($byIncident))) as $incidentId) {
            $incidentRecorded[(int) $byIncident[$incidentId]] = true;
        }
        $categories = User::permission('maintenance.own')->where('is_active', true)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all();

        return Inertia::render('Admin/Resource', [
            'title' => 'Maintenance',
            'subtitle' => 'Room issues and guest complaints. Red means High or Urgent: handle these first. Mark a job completed when it is fixed, and record what it cost so it counts in Room costs. A safety block stops the room being assigned or marked ready until resolved.',
            'tabs' => $user->can('incidents.view') ? [['label' => 'Work orders', 'href' => '/admin/work-orders'], ['label' => 'Damage & incidents', 'href' => '/admin/incidents']] : [],
            'stats' => [
                ['label' => 'Urgent & high, pending', 'value' => (clone $pending)->whereIn('severity', ['critical', 'high'])->count()],
                ['label' => 'Pending', 'value' => (clone $pending)->count()],
                ['label' => 'Completed this month', 'value' => $mine(WorkOrder::query())->whereNotIn('status', self::PENDING)->where('resolved_at', '>=', $monthStart->utc())->count()],
                ['label' => 'Overdue', 'value' => (clone $pending)->whereNotNull('due_on')->where('due_on', '<', Local::today())->count()],
            ],
            'filters' => [
                ['name' => 'view', 'label' => 'Show', 'value' => $view, 'options' => [['value' => 'pending', 'label' => 'Pending'], ['value' => 'completed', 'label' => 'Completed'], ['value' => 'all', 'label' => 'All']]],
                ['name' => 'priority', 'label' => 'Priority', 'value' => $f['priority'] ?? null, 'options' => [['value' => 'urgent', 'label' => 'Red only (Urgent + High)'], ...collect(self::PRIORITIES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
                ['name' => 'room', 'label' => 'Room', 'value' => $f['room'] ?? null, 'options' => $this->rooms()],
            ],
            'columns' => [
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'status'], ['key' => 'title', 'label' => 'Issue'], ['key' => 'room', 'label' => 'Room'],
                ['key' => 'source', 'label' => 'Reported by'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'assignee', 'label' => 'Assigned'],
                ['key' => 'due_on', 'label' => 'Due', 'type' => 'date'], ['key' => 'cost_cents', 'label' => 'Cost', 'type' => 'money'], ['key' => 'expense', 'label' => 'In room costs'],
            ],
            'rows' => $rows->through(function ($w) use ($manager, $user, $recordedIds, $incidentRecorded) {
                $pending = in_array($w->status, self::PENDING, true);
                $recorded = isset($recordedIds[$w->id]) || isset($incidentRecorded[$w->id]);
                $canAct = $manager || $w->assigned_to === $user->id;
                $actions = [];
                if ($canAct && $pending) {
                    $actions[] = [
                        'label' => 'Mark completed', 'url' => "/admin/work-orders/{$w->id}/complete", 'variant' => 'primary',
                        'fields' => array_values(array_filter([
                            ['name' => 'note', 'label' => 'What was done', 'type' => 'text', 'help' => 'For example: cleaned the AC filter and coils.'],
                            ['name' => 'cost', 'label' => 'Cost (PHP)', 'type' => 'money', 'default' => Money::pesos($w->cost_cents === null ? null : (int) $w->cost_cents), 'help' => 'Leave blank if it cost nothing.'],
                            $user->can('expenses.manage') && ! $recorded && $w->room_id ? ['name' => 'record_expense', 'label' => 'Add this cost to the room\'s expenses (Room costs)', 'type' => 'checkbox', 'default' => true] : null,
                            $user->can('expenses.manage') && ! $recorded && $w->room_id ? ['name' => 'vendor', 'label' => 'Paid to (optional)', 'type' => 'text'] : null,
                        ])),
                    ];
                }
                if ($canAct) {
                    $actions[] = [
                        'label' => $pending ? 'Update' : 'Reopen or edit',
                        'url' => "/admin/work-orders/{$w->id}/status",
                        'fields' => array_values(array_filter([
                            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => $w->status, 'options' => $this->opts(['open', 'in_progress', 'resolved', 'closed'])],
                            $manager ? ['name' => 'severity', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => $w->severity, 'options' => $this->priorityOptions()] : null,
                            ['name' => 'cost', 'label' => 'Cost (PHP)', 'type' => 'money', 'default' => Money::pesos($w->cost_cents === null ? null : (int) $w->cost_cents)],
                            $manager ? ['name' => 'classification', 'label' => 'Review class', 'type' => 'select', 'default' => $w->classification, 'options' => $this->opts(['unclassified', 'routine', 'capex_candidate'])] : null,
                        ])),
                    ];
                }

                return [
                    'id' => $w->id,
                    'priority' => self::PRIORITIES[$w->severity] ?? ucfirst($w->severity),
                    'title' => $w->title.($w->description ? ' — '.$w->description : '').($w->safety_block ? ' (safety block)' : ''),
                    'room' => $w->room ? "{$w->room->name} ({$w->room->unit_code})" : 'Property-wide',
                    'source' => (self::SOURCES[$w->source] ?? 'Staff').($w->booking ? " · {$w->booking->reference}" : ''),
                    'status' => $w->status,
                    'assignee' => $w->assignee?->name,
                    'due_on' => $w->due_on?->toDateString(),
                    'cost_cents' => $w->cost_cents === null ? null : (int) $w->cost_cents,
                    'expense' => $recorded ? 'Yes' : ($w->cost_cents ? 'Not yet' : '—'),
                    '_actions' => $actions,
                ];
            }),
            'create' => $manager ? ['label' => 'Report an issue', 'url' => '/admin/work-orders', 'fields' => [
                ['name' => 'title', 'label' => 'Issue', 'type' => 'text', 'required' => true, 'help' => 'For example: AC not cooling, needs cleaning.'],
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'options' => $this->rooms()],
                ['name' => 'severity', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => 'normal', 'options' => $this->priorityOptions(), 'help' => 'High and Urgent show in red at the top of the list.'],
                ['name' => 'source', 'label' => 'Reported by', 'type' => 'select', 'required' => true, 'default' => 'staff', 'options' => collect(self::SOURCES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()],
                ['name' => 'booking_reference', 'label' => 'Booking reference (for a guest complaint)', 'type' => 'text', 'help' => 'Optional, for example UQ-2611-AB12C. Links the complaint to the stay.'],
                ['name' => 'category', 'label' => 'Type', 'type' => 'select', 'default' => 'general', 'options' => $this->opts(['general', 'ac', 'plumbing', 'electrical', 'appliance', 'furniture', 'cleaning', 'preventive', 'renovation'])],
                ['name' => 'assigned_to', 'label' => 'Assign to', 'type' => 'select', 'options' => $categories],
                ['name' => 'due_on', 'label' => 'Due', 'type' => 'date'],
                ['name' => 'safety_block', 'label' => 'Safety block: stop this room being assigned or marked ready', 'type' => 'checkbox'],
                ['name' => 'description', 'label' => 'Details', 'type' => 'textarea'],
            ]] : null,
        ]);
    }

    public function storeWorkOrder(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'room_id' => 'nullable|exists:rooms,id',
            'category' => 'nullable|string|max:40',
            'severity' => 'required|in:low,normal,high,critical',
            'source' => 'nullable|in:staff,guest_complaint,inspection',
            'booking_reference' => 'nullable|string|max:40',
            'safety_block' => 'nullable|boolean',
            'assigned_to' => 'nullable|exists:users,id',
            'due_on' => 'nullable|date_format:Y-m-d',
            'description' => 'nullable|string|max:2000',
        ]);
        $data['safety_block'] = (bool) ($data['safety_block'] ?? false);
        if ($data['safety_block'] && empty($data['room_id'])) {
            throw new RoomConflict('A safety block must name a room.');
        }
        // A guest complaint names the stay it came from; its room fills in when none was chosen.
        $booking = null;
        if (! empty($data['booking_reference'])) {
            $booking = Booking::with('activeRooms')->where('reference', trim($data['booking_reference']))->first();
            if (! $booking) {
                throw ValidationException::withMessages(['booking_reference' => 'No booking has that reference.']);
            }
            $data['room_id'] ??= $booking->activeRooms->first()?->room_id ?? $this->lastRoomId($booking);
            $data['source'] ??= 'guest_complaint';
        }
        unset($data['booking_reference']);
        $order = WorkOrder::create($data + [
            'created_by' => $request->user()->id,
            'category' => $data['category'] ?? 'general',
            'source' => $data['source'] ?? 'staff',
            'booking_id' => $booking?->id,
        ]);
        Audit::log('work_order.created', $order, ['safety_block' => $order->safety_block, 'severity' => $order->severity, 'source' => $order->source]);
        $label = self::PRIORITIES[$order->severity];
        if ($order->assigned_to) {
            User::find($order->assigned_to)?->notify(new SystemNotice("{$label} work order assigned: {$order->title}", '/admin/work-orders'));
        }
        if (in_array($order->severity, ['critical', 'high'], true)) {
            SystemNotice::toPermission('maintenance.manage', "{$label}: {$order->title}", '/admin/work-orders?priority=urgent', 'maintenance');
        }

        return back()->with('success', in_array($order->severity, ['critical', 'high'], true) ? "{$label} issue reported. It shows in red at the top of Maintenance." : 'Issue reported.');
    }

    public function updateWorkOrder(Request $request, WorkOrder $order)
    {
        $user = $request->user();
        abort_unless($user->can('maintenance.manage') || $order->assigned_to === $user->id, 403);
        $data = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed', 'severity' => 'nullable|in:low,normal,high,critical', 'cost' => ['nullable', Money::RULE], 'classification' => 'nullable|in:unclassified,routine,capex_candidate']);

        $update = ['status' => $data['status'], 'cost_cents' => Money::cents($data['cost'] ?? null) ?? $order->cost_cents];
        if ($user->can('maintenance.manage') && ! empty($data['classification'])) {
            $update['classification'] = $data['classification'];
        }
        if ($user->can('maintenance.manage') && ! empty($data['severity'])) {
            $update['severity'] = $data['severity'];
        }
        if (in_array($data['status'], ['resolved', 'closed'], true) && ! $order->resolved_at) {
            $update += ['resolved_at' => now(), 'completed_by' => $user->id];
        }
        // Reopened: it is pending again, so it is not counted as completed.
        if (in_array($data['status'], self::PENDING, true)) {
            $update += ['resolved_at' => null, 'completed_by' => null];
        }
        $order->update($update);
        Audit::log('work_order.updated', $order, ['status' => $data['status'], 'severity' => $order->severity]);

        return back()->with('success', 'Work order updated.');
    }

    /** One step from pending to done: what was done, what it cost, and optionally the room expense. */
    public function completeWorkOrder(Request $request, WorkOrder $order, RoomExpenses $expenses)
    {
        $user = $request->user();
        abort_unless($user->can('maintenance.manage') || $order->assigned_to === $user->id, 403);
        $data = $request->validate(['note' => 'nullable|string|max:255', 'cost' => ['nullable', Money::RULE], 'record_expense' => 'nullable|boolean', 'vendor' => 'nullable|string|max:120']);

        // Locked and re-read, so a double click cannot complete it, or record its cost, twice.
        $outcome = DB::transaction(function () use ($order, $data, $user, $expenses) {
            $order = WorkOrder::lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, self::PENDING, true)) {
                return 'done';
            }
            $cents = Money::cents($data['cost'] ?? null) ?? ($order->cost_cents === null ? null : (int) $order->cost_cents);
            $record = ! empty($data['record_expense']) && $cents > 0 && $order->room_id && $user->can('expenses.manage') && ! $expenses->alreadyCounted($order);
            $order->update([
                'status' => 'resolved', 'resolved_at' => now(), 'completed_by' => $user->id,
                'completion_note' => $data['note'] ?? null, 'cost_cents' => $cents,
            ]);
            $expense = $record ? $expenses->record($order, $cents, 'paid', null, $data['vendor'] ?? null) : null;
            Audit::log('work_order.completed', $order, ['cost_cents' => $cents, 'expense' => $record]);

            return $expense ? $expense->status : 'plain';
        });

        return back()->with('success', match ($outcome) {
            'done' => 'This work order is already completed.',
            'paid' => 'Completed. The cost was added to the room expenses.',
            'incurred' => 'Completed. The cost was added to the room expenses as incurred: it needs approval before it is marked paid.',
            default => 'Completed.',
        });
    }

    private function priorityOptions(): array
    {
        return collect(self::PRIORITIES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all();
    }

    private function lastRoomId(Booking $booking): ?int
    {
        return $booking->bookingRooms()->orderByDesc('id')->value('room_id');
    }

    public function incidents(Request $request)
    {
        $user = $request->user();
        $rows = AdminCategory::whereHasProperty(Incident::query(), 'room')->with(['room:id,name', 'booking:id,reference', 'guest:id,full_name', 'evidence:id,incident_id'])->orderByDesc('id')->paginate(15);
        // Already in Room costs, through the incident or the work order made for it: one query each, not one per row.
        $expenses = app(RoomExpenses::class);
        $incidentRecorded = $expenses->recordedIds('incident_id', $rows->getCollection()->pluck('id')->all());
        $orderRecorded = $expenses->recordedIds('work_order_id', $rows->getCollection()->pluck('work_order_id')->filter()->all());
        foreach ($rows->getCollection() as $i) {
            if ($i->work_order_id && isset($orderRecorded[$i->work_order_id])) {
                $incidentRecorded[$i->id] = true;
            }
        }

        return Inertia::render('Admin/Resource', [
            'title' => 'Damage & incidents',
            'subtitle' => 'A report never charges a guest. Only an owner decision can create a charge, and it can still be reversed.',
            'tabs' => [['label' => 'Work orders', 'href' => '/admin/work-orders'], ['label' => 'Damage & incidents', 'href' => '/admin/incidents']],
            'columns' => [
                ['key' => 'discovered_on', 'label' => 'Discovered', 'type' => 'date'], ['key' => 'room', 'label' => 'Room'], ['key' => 'timeline', 'label' => 'History', 'type' => 'link'], ['key' => 'booking', 'label' => 'Booking', 'type' => 'link'],
                ['key' => 'guest', 'label' => 'Guest'], ['key' => 'item', 'label' => 'Item'], ['key' => 'estimated_cents', 'label' => 'Estimated', 'type' => 'money'],
                ['key' => 'approved_cents', 'label' => 'Approved', 'type' => 'money'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'evidence', 'label' => 'Evidence'],
            ],
            'rows' => $rows->through(function ($i) use ($user, $incidentRecorded) {
                $actions = [];
                if ($user->can('incidents.manage') && ! in_array($i->status, ['closed'], true)) {
                    $actions[] = ['label' => 'Update', 'url' => "/admin/incidents/{$i->id}/status", 'fields' => [
                        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => in_array($i->status, ['reported', 'evidence_pending', 'under_review', 'disputed', 'resolved'], true) ? $i->status : null, 'options' => $this->opts(['reported', 'evidence_pending', 'under_review', 'disputed', 'resolved', 'closed'])],
                        ['name' => 'repair_cost', 'label' => 'Repair or replacement cost (PHP)', 'type' => 'money', 'default' => Money::pesos($i->repair_cost_cents === null ? null : (int) $i->repair_cost_cents)],
                        ...($user->can('expenses.manage') && ! isset($incidentRecorded[$i->id]) ? [['name' => 'record_expense', 'label' => 'Add this cost to the room expenses (Room costs)', 'type' => 'checkbox', 'default' => true]] : []),
                        ['name' => 'closure_notes', 'label' => 'Notes', 'type' => 'textarea'],
                        ['name' => 'evidence', 'label' => 'Add evidence', 'type' => 'file'],
                    ]];
                }
                if ($user->can('incidents.approve') && ! $i->charge_id && $i->booking_id && in_array($i->status, ['reported', 'evidence_pending', 'under_review', 'disputed'], true)) {
                    $actions[] = ['label' => 'Approve charge', 'url' => "/admin/incidents/{$i->id}/decide", 'variant' => 'primary', 'data' => ['decision' => 'approve'], 'fields' => [
                        ['name' => 'approved', 'label' => 'Approved amount (PHP)', 'type' => 'money', 'required' => true, 'default' => Money::pesos($i->estimated_cents === null ? null : (int) $i->estimated_cents)],
                        ['name' => 'owner_resolution', 'label' => 'Owner decision', 'type' => 'textarea', 'required' => true],
                    ]];
                }
                if ($user->can('incidents.approve') && $i->charge_id) {
                    $actions[] = ['label' => 'Reverse charge', 'url' => "/admin/incidents/{$i->id}/decide", 'variant' => 'danger', 'data' => ['decision' => 'reverse'], 'fields' => [['name' => 'owner_resolution', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]]];
                }

                return [
                    'id' => $i->id,
                    'discovered_on' => $i->discovered_on->toDateString(),
                    'room' => $i->room->name,
                    'timeline' => ['label' => 'Open', 'href' => "/admin/incidents/{$i->id}"],
                    'booking' => $i->booking ? ['label' => $i->booking->reference, 'href' => "/admin/bookings/{$i->booking_id}"] : ['label' => 'None'],
                    'guest' => $i->guest?->full_name,
                    'item' => $i->item,
                    'estimated_cents' => $i->estimated_cents === null ? null : (int) $i->estimated_cents,
                    'approved_cents' => $i->approved_cents === null ? null : (int) $i->approved_cents,
                    'status' => $i->status,
                    'evidence' => $i->evidence->count().' file(s)',
                    '_actions' => $actions,
                ];
            }),
            'create' => $user->can('incidents.manage') ? ['label' => 'Report incident', 'url' => '/admin/incidents', 'fields' => [
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'required' => true, 'options' => $this->rooms()],
                ['name' => 'booking_reference', 'label' => 'Booking reference', 'type' => 'text'],
                ['name' => 'item', 'label' => 'Item damaged or issue', 'type' => 'text', 'required' => true],
                ['name' => 'discovered_on', 'label' => 'Discovered on', 'type' => 'date', 'required' => true, 'default' => Local::today()],
                ['name' => 'estimated', 'label' => 'Estimated amount (PHP)', 'type' => 'money'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'evidence', 'label' => 'Evidence (image or PDF)', 'type' => 'file'],
                ['name' => 'safety_block', 'label' => 'Create a safety-block work order for this room', 'type' => 'checkbox'],
            ]] : null,
        ]);
    }

    /** One chronological record: logged communications, status changes and evidence. */
    public function showIncident(Request $request, Incident $incident)
    {
        $incident->load(['room:id,name', 'guest:id,full_name', 'booking:id,reference', 'evidence']);

        $notes = IncidentNote::where('incident_id', $incident->id)->with('user:id,name')->get()->map(fn ($n) => [
            'at' => $n->created_at, 'kind' => ucfirst(str_replace('_', ' ', $n->channel)), 'by' => $n->user?->name, 'detail' => $n->body, 'file' => ['label' => '—'],
        ]);
        $evidence = $incident->evidence->map(fn ($e) => [
            'at' => $e->created_at, 'kind' => 'Evidence added', 'by' => null, 'detail' => null, 'file' => ['label' => 'View', 'href' => "/admin/files/incident/{$e->id}", 'external' => true],
        ]);
        $history = DB::table('activity_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.subject_type', Incident::class)->where('a.subject_id', $incident->id)
            ->get(['a.created_at', 'a.action', 'a.meta', 'u.name'])->map(function ($a) {
                $meta = json_decode((string) $a->meta, true) ?? [];

                return ['at' => $a->created_at, 'kind' => ucfirst(str_replace(['incident.', '_'], ['', ' '], $a->action)), 'by' => $a->name, 'detail' => $meta['resolution'] ?? (isset($meta['status']) ? 'Status: '.str_replace('_', ' ', $meta['status']) : null), 'file' => ['label' => '—']];
            });

        return Inertia::render('Admin/Resource', [
            'title' => "Incident: {$incident->item}",
            'subtitle' => implode(' · ', array_filter([$incident->room->name, $incident->guest?->full_name, $incident->booking?->reference, 'Status: '.str_replace('_', ' ', $incident->status)])),
            'note' => $incident->description,
            'backHref' => '/admin/incidents',
            'columns' => [['key' => 'at', 'label' => 'When', 'type' => 'datetime'], ['key' => 'kind', 'label' => 'What'], ['key' => 'by', 'label' => 'By'], ['key' => 'detail', 'label' => 'Detail'], ['key' => 'file', 'label' => 'File', 'type' => 'link']],
            'rows' => collect($notes)->concat($evidence)->concat($history)->sortBy(fn ($r) => (string) $r['at'])->values()->map(fn ($r, $i) => $r + ['id' => $i]),
            'create' => $request->user()->can('incidents.manage') ? ['label' => 'Log communication', 'url' => "/admin/incidents/{$incident->id}/notes", 'fields' => [
                ['name' => 'channel', 'label' => 'How', 'type' => 'select', 'required' => true, 'options' => $this->opts(['internal', 'messenger', 'instagram', 'phone', 'email', 'in_person'])],
                ['name' => 'body', 'label' => 'What was said or agreed', 'type' => 'textarea', 'required' => true],
            ]] : null,
        ]);
    }

    public function storeIncidentNote(Request $request, Incident $incident)
    {
        $data = $request->validate(['channel' => 'required|in:internal,messenger,instagram,phone,email,in_person', 'body' => 'required|string|max:2000']);
        IncidentNote::create($data + ['incident_id' => $incident->id, 'user_id' => $request->user()->id, 'created_at' => now()]);

        return back()->with('success', 'Communication logged.');
    }

    public function storeIncident(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'booking_reference' => 'nullable|exists:bookings,reference',
            'item' => 'required|string|max:160',
            'discovered_on' => 'required|date_format:Y-m-d',
            'estimated' => ['nullable', Money::RULE],
            'description' => 'nullable|string|max:2000',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'safety_block' => 'nullable|boolean',
        ]);
        $booking = ! empty($data['booking_reference']) ? Booking::where('reference', $data['booking_reference'])->first() : null;

        DB::transaction(function () use ($data, $booking, $request) {
            $workOrder = ! empty($data['safety_block']) ? WorkOrder::create([
                'room_id' => $data['room_id'], 'title' => 'Incident: '.$data['item'], 'severity' => 'critical', 'safety_block' => true, 'created_by' => $request->user()->id,
            ]) : null;
            $incident = Incident::create([
                'room_id' => $data['room_id'],
                'booking_id' => $booking?->id,
                'guest_id' => $booking?->guest_id,
                'work_order_id' => $workOrder?->id,
                'item' => $data['item'],
                'description' => $data['description'] ?? null,
                'discovered_on' => $data['discovered_on'],
                'estimated_cents' => Money::cents($data['estimated'] ?? null),
                'status' => $request->hasFile('evidence') ? 'under_review' : 'evidence_pending',
                'reported_by' => $request->user()->id,
            ]);
            $this->saveEvidence($request, $incident);
            Audit::log('incident.reported', $incident);
        });
        SystemNotice::toPermission('incidents.approve', "Incident reported: {$data['item']}", '/admin/incidents', 'incidents');

        return back()->with('success', 'Incident recorded. No charge has been made.');
    }

    public function updateIncident(Request $request, Incident $incident)
    {
        $data = $request->validate(['status' => 'required|in:reported,evidence_pending,under_review,disputed,resolved,closed', 'repair_cost' => ['nullable', Money::RULE], 'record_expense' => 'nullable|boolean', 'closure_notes' => 'nullable|string|max:2000', 'evidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        $expenses = app(RoomExpenses::class);
        // The replacement cost becomes the room's expense once; what the guest is charged stays a separate decision.
        $record = DB::transaction(function () use ($incident, $data, $expenses, $request) {
            $incident = Incident::lockForUpdate()->findOrFail($incident->id);
            $cost = Money::cents($data['repair_cost'] ?? null) ?? ($incident->repair_cost_cents === null ? null : (int) $incident->repair_cost_cents);
            $record = ! empty($data['record_expense']) && $cost > 0 && $request->user()->can('expenses.manage') && ! $expenses->alreadyCounted($incident);
            $incident->update([
                'status' => $data['status'],
                'repair_cost_cents' => $cost,
                'closure_notes' => $data['closure_notes'] ?? $incident->closure_notes,
            ]);
            if ($record) {
                $expenses->record($incident, $cost);
            }
            $this->saveEvidence($request, $incident);
            Audit::log('incident.updated', $incident, ['status' => $data['status'], 'expense' => $record]);

            return $record;
        });

        return back()->with('success', $record ? 'Incident updated. The cost was added to the room expenses.' : 'Incident updated.');
    }

    /** The only path that turns an incident into money, and it can be undone with history kept. */
    public function decideIncident(Request $request, Incident $incident, BookingService $bookings)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reverse', 'approved' => ['required_if:decision,approve', 'nullable', Money::RULE], 'owner_resolution' => 'required|string|max:2000']);

        app(FinanceService::class)->assertOpen(Local::today());

        DB::transaction(function () use ($data, $incident, $request, $bookings) {
            $booking = $incident->booking;
            if ($data['decision'] === 'approve') {
                if (! $booking) {
                    throw new RoomConflict('Link a booking before approving a charge.');
                }
                if ($incident->charge_id) {
                    throw new RoomConflict('A charge already exists for this incident.');
                }
                $cents = Money::cents($data['approved']);
                $charge = BookingCharge::create([
                    'booking_id' => $booking->id, 'type' => 'damage', 'description' => 'Damage: '.$incident->item, 'unit_cents' => $cents, 'amount_cents' => $cents,
                    'charge_date' => Local::today(), 'pricing_version' => $booking->pricing_version, 'approved_by' => $request->user()->id, 'created_by' => $request->user()->id,
                ]);
                $incident->update(['status' => 'approved_for_charge', 'approved_cents' => $cents, 'approved_by' => $request->user()->id, 'owner_resolution' => $data['owner_resolution'], 'charge_id' => $charge->id]);
            } else {
                BookingCharge::whereKey($incident->charge_id)->update(['voided_at' => now(), 'void_reason' => 'Incident charge reversed: '.$data['owner_resolution']]);
                $incident->update(['status' => 'disputed', 'charge_id' => null, 'approved_cents' => null, 'owner_resolution' => $data['owner_resolution']]);
            }
            if ($booking) {
                $bookings->syncTotal($booking);
            }
            Audit::log('incident.'.$data['decision'], $incident, ['resolution' => $data['owner_resolution']]);
        });

        return back()->with('success', $data['decision'] === 'approve' ? 'Charge added to the booking.' : 'Charge reversed.');
    }

    private function saveEvidence(Request $request, Incident $incident): void
    {
        if ($request->hasFile('evidence')) {
            IncidentEvidence::create(['incident_id' => $incident->id, 'path' => $request->file('evidence')->store('evidence/incidents', 'local'), 'user_id' => $request->user()->id]);
        }
    }

    private function rooms(): array
    {
        return AdminCategory::rooms(Room::query())->orderBy('id')->get()->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->name} ({$r->unit_code})"])->all();
    }

    private function opts(array $values): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))], $values);
    }
}
