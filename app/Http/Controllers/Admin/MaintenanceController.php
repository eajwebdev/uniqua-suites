<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Incident;
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
use Inertia\Inertia;

class MaintenanceController extends Controller
{
    public function workOrders(Request $request)
    {
        $user = $request->user();
        $manager = $user->can('maintenance.manage');
        $rows = WorkOrder::with(['room:id,name', 'assignee:id,name', 'vendor:id,name'])
            ->when(! $manager, fn ($q) => $q->where('assigned_to', $user->id))
            ->orderByRaw("FIELD(status,'open','in_progress','resolved','closed')")->orderByDesc('safety_block')->orderByDesc('id')->paginate(30);

        return Inertia::render('Admin/Resource', [
            'title' => 'Maintenance',
            'subtitle' => 'A safety block stops the room being assigned or marked ready until resolved. Capital vs routine is a review label for the owner or accountant, not an accounting entry.',
            'tabs' => $user->can('incidents.view') ? [['label' => 'Work orders', 'href' => '/admin/work-orders'], ['label' => 'Damage & incidents', 'href' => '/admin/incidents']] : [],
            'columns' => [
                ['key' => 'title', 'label' => 'Work order'], ['key' => 'room', 'label' => 'Room'], ['key' => 'severity', 'label' => 'Severity', 'type' => 'status'],
                ['key' => 'safety', 'label' => 'Safety block'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'assignee', 'label' => 'Assigned'],
                ['key' => 'classification', 'label' => 'Review class'], ['key' => 'cost_cents', 'label' => 'Cost', 'type' => 'money'], ['key' => 'due_on', 'label' => 'Due', 'type' => 'date'],
            ],
            'rows' => $rows->through(fn ($w) => [
                'id' => $w->id,
                'title' => $w->title,
                'room' => $w->room?->name ?? 'Property-wide',
                'severity' => $w->severity,
                'safety' => $w->safety_block ? 'Yes' : 'No',
                'status' => $w->status,
                'assignee' => $w->assignee?->name,
                'classification' => str_replace('_', ' ', $w->classification),
                'cost_cents' => $w->cost_cents === null ? null : (int) $w->cost_cents,
                'due_on' => $w->due_on?->toDateString(),
                '_actions' => [[
                    'label' => 'Update',
                    'url' => "/admin/work-orders/{$w->id}/status",
                    'fields' => array_values(array_filter([
                        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => $w->status, 'options' => $this->opts(['open', 'in_progress', 'resolved', 'closed'])],
                        ['name' => 'cost', 'label' => 'Cost (PHP)', 'type' => 'money', 'default' => Money::pesos($w->cost_cents === null ? null : (int) $w->cost_cents)],
                        $manager ? ['name' => 'classification', 'label' => 'Review class', 'type' => 'select', 'default' => $w->classification, 'options' => $this->opts(['unclassified', 'routine', 'capex_candidate'])] : null,
                    ])),
                ]],
            ]),
            'create' => $manager ? ['label' => 'New work order', 'url' => '/admin/work-orders', 'fields' => [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'options' => $this->rooms()],
                ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'default' => 'general', 'options' => $this->opts(['general', 'plumbing', 'ac', 'appliance', 'furniture', 'electrical', 'preventive', 'renovation'])],
                ['name' => 'severity', 'label' => 'Severity', 'type' => 'select', 'default' => 'normal', 'options' => $this->opts(['low', 'normal', 'high', 'critical'])],
                ['name' => 'safety_block', 'label' => 'Safety block: stop this room being assigned or marked ready', 'type' => 'checkbox'],
                ['name' => 'assigned_to', 'label' => 'Assign to', 'type' => 'select', 'options' => User::permission('maintenance.own')->where('is_active', true)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all()],
                ['name' => 'due_on', 'label' => 'Due', 'type' => 'date'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
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
            'safety_block' => 'nullable|boolean',
            'assigned_to' => 'nullable|exists:users,id',
            'due_on' => 'nullable|date_format:Y-m-d',
            'description' => 'nullable|string|max:2000',
        ]);
        $data['safety_block'] = (bool) ($data['safety_block'] ?? false);
        if ($data['safety_block'] && empty($data['room_id'])) {
            throw new RoomConflict('A safety block must name a room.');
        }
        $order = WorkOrder::create($data + ['created_by' => $request->user()->id, 'category' => $data['category'] ?? 'general']);
        Audit::log('work_order.created', $order, ['safety_block' => $order->safety_block]);
        if ($order->assigned_to) {
            User::find($order->assigned_to)?->notify(new SystemNotice("Work order assigned: {$order->title}", '/admin/work-orders'));
        }

        return back()->with('success', 'Work order created.');
    }

    public function updateWorkOrder(Request $request, WorkOrder $order)
    {
        $user = $request->user();
        abort_unless($user->can('maintenance.manage') || $order->assigned_to === $user->id, 403);
        $data = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed', 'cost' => ['nullable', Money::RULE], 'classification' => 'nullable|in:unclassified,routine,capex_candidate']);

        $update = ['status' => $data['status'], 'cost_cents' => Money::cents($data['cost'] ?? null) ?? $order->cost_cents];
        if ($user->can('maintenance.manage') && ! empty($data['classification'])) {
            $update['classification'] = $data['classification'];
        }
        if (in_array($data['status'], ['resolved', 'closed'], true) && ! $order->resolved_at) {
            $update['resolved_at'] = now();
        }
        $order->update($update);
        Audit::log('work_order.updated', $order, ['status' => $data['status']]);

        return back()->with('success', 'Work order updated.');
    }

    public function incidents(Request $request)
    {
        $user = $request->user();
        $rows = Incident::with(['room:id,name', 'booking:id,reference', 'guest:id,full_name', 'evidence:id,incident_id'])->orderByDesc('id')->paginate(30);

        return Inertia::render('Admin/Resource', [
            'title' => 'Damage & incidents',
            'subtitle' => 'A report never charges a guest. Only an owner decision can create a charge, and it can still be reversed.',
            'tabs' => [['label' => 'Work orders', 'href' => '/admin/work-orders'], ['label' => 'Damage & incidents', 'href' => '/admin/incidents']],
            'columns' => [
                ['key' => 'discovered_on', 'label' => 'Discovered', 'type' => 'date'], ['key' => 'room', 'label' => 'Room'], ['key' => 'booking', 'label' => 'Booking', 'type' => 'link'],
                ['key' => 'guest', 'label' => 'Guest'], ['key' => 'item', 'label' => 'Item'], ['key' => 'estimated_cents', 'label' => 'Estimated', 'type' => 'money'],
                ['key' => 'approved_cents', 'label' => 'Approved', 'type' => 'money'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'evidence', 'label' => 'Evidence'],
            ],
            'rows' => $rows->through(function ($i) use ($user) {
                $actions = [];
                if ($user->can('incidents.manage') && ! in_array($i->status, ['closed'], true)) {
                    $actions[] = ['label' => 'Update', 'url' => "/admin/incidents/{$i->id}/status", 'fields' => [
                        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => in_array($i->status, ['reported', 'evidence_pending', 'under_review', 'disputed', 'resolved'], true) ? $i->status : null, 'options' => $this->opts(['reported', 'evidence_pending', 'under_review', 'disputed', 'resolved', 'closed'])],
                        ['name' => 'repair_cost', 'label' => 'Repair cost (PHP)', 'type' => 'money'],
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
        SystemNotice::toPermission('incidents.approve', "Incident reported: {$data['item']}", '/admin/incidents');

        return back()->with('success', 'Incident recorded. No charge has been made.');
    }

    public function updateIncident(Request $request, Incident $incident)
    {
        $data = $request->validate(['status' => 'required|in:reported,evidence_pending,under_review,disputed,resolved,closed', 'repair_cost' => ['nullable', Money::RULE], 'closure_notes' => 'nullable|string|max:2000', 'evidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        $incident->update([
            'status' => $data['status'],
            'repair_cost_cents' => Money::cents($data['repair_cost'] ?? null) ?? $incident->repair_cost_cents,
            'closure_notes' => $data['closure_notes'] ?? $incident->closure_notes,
        ]);
        $this->saveEvidence($request, $incident);
        Audit::log('incident.updated', $incident, ['status' => $data['status']]);

        return back()->with('success', 'Incident updated.');
    }

    /** The only path that turns an incident into money, and it can be undone with history kept. */
    public function decideIncident(Request $request, Incident $incident, BookingService $bookings)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reverse', 'approved' => ['required_if:decision,approve', 'nullable', Money::RULE], 'owner_resolution' => 'required|string|max:2000']);

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
        return Room::orderBy('id')->get()->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->name} ({$r->unit_code})"])->all();
    }

    private function opts(array $values): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))], $values);
    }
}
