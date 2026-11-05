<?php

namespace App\Domain\Tasks;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\InventoryMovement;
use App\Models\LaundryJob;
use App\Models\Room;
use App\Models\RoomStatusEvent;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Settings;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TurnoverService
{
    public const CHECKLIST = ['Bed linen changed', 'Bathroom cleaned', 'Floors cleaned', 'Trash removed', 'Refills restocked', 'Appliances checked', 'Before/after photos taken'];

    /** Guests due out that day whose room has no cleaning task yet. Only ever carries the room, never the guest. */
    public function plannedTurnovers(string $date): Collection
    {
        if ($date < Local::today()) {
            return collect();
        }

        return DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->join('rooms as r', 'r.id', '=', 'br.room_id')
            ->whereNull('br.released_at')
            ->where('br.check_out', $date)
            ->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('housekeeping_tasks as t')
                ->whereRaw("t.idempotency_key = CONCAT('turnover:', br.booking_id, ':', br.room_id)")
                ->where('t.status', '!=', 'cancelled'))
            ->orderBy('r.name')
            ->get(['br.booking_id', 'br.room_id', 'r.name as room', 'r.unit_code as unit']);
    }

    /**
     * A task for a guest who has not left yet, so a cleaner can be lined up in advance. The room
     * stays as it is, and the work cannot start until checkout turns this into a real task.
     */
    public function plan(Booking $booking, Room $room, string $date): HousekeepingTask
    {
        $task = HousekeepingTask::firstOrNew(['idempotency_key' => "turnover:{$booking->id}:{$room->id}"]);
        if ($task->exists && $task->status !== 'cancelled') {
            return $task;
        }

        $task->fill([
            'type' => 'turnover',
            'room_id' => $room->id,
            'booking_id' => $booking->id,
            'status' => 'waiting',
            'due_at' => CarbonImmutable::parse($date.' '.Settings::get('pricing.standard_check_in'), Local::tz())->utc(),
            'checklist' => $this->blankChecklist(),
        ])->save();
        $this->taskEvent($task, 'planned', 'Guest due out '.$date);

        return $task;
    }

    /** A planned task follows its booking: if the stay was cancelled or moved, the cleaner is told it is off. */
    public function syncWaiting(Booking $booking): void
    {
        foreach (HousekeepingTask::with('room')->where('booking_id', $booking->id)->where('status', 'waiting')->get() as $task) {
            $stay = $booking->activeRooms()->where('room_id', $task->room_id)->first();
            $due = $task->due_at?->copy()->setTimezone(Local::tz())->toDateString();
            $stillDue = $stay && in_array($booking->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)
                && substr((string) $stay->check_out, 0, 10) === $due;
            if ($stillDue) {
                continue;
            }

            $assignee = $task->assignee;
            $task->update(['status' => 'cancelled', 'assigned_to' => null]);
            $this->taskEvent($task, 'cancelled', 'The booking changed');
            $this->tell($assignee, "Cleaning cancelled: {$task->room->name}. The booking changed.", $task);
        }
    }

    /** Idempotent on the booking+room key, so a retried checkout creates exactly one job. */
    public function onCheckout(Booking $booking, Room $room): HousekeepingTask
    {
        $task = HousekeepingTask::firstOrCreate(
            ['idempotency_key' => "turnover:{$booking->id}:{$room->id}"],
            [
                'type' => 'turnover',
                'room_id' => $room->id,
                'booking_id' => $booking->id,
                'status' => 'pending',
                'due_at' => now()->addHours((int) Settings::get('housekeeping.turnover_due_hours')),
                'checklist' => $this->blankChecklist(),
            ],
        );

        $planned = in_array($task->status, ['waiting', 'cancelled'], true);
        if ($planned) {
            $task->update(['status' => $task->assigned_to ? 'assigned' : 'pending', 'due_at' => now()->addHours((int) Settings::get('housekeeping.turnover_due_hours'))]);
        }

        if ($task->wasRecentlyCreated || $planned) {
            $this->setHousekeeping($room, 'dirty', 'Checkout '.$booking->reference);
            $this->taskEvent($task, 'created', 'Created by checkout');
            if ($task->assigned_to) {
                $this->setHousekeeping($room, 'assigned', 'Task #'.$task->id);
                $this->tell($task->assignee, "The guest has left {$room->name}. You can start cleaning.", $task);
            } else {
                SystemNotice::toPermission('housekeeping.manage', "{$room->name} has checked out and needs a cleaner.", '/admin/housekeeping', 'housekeeping');
            }
        }

        if (Settings::get('laundry.auto_create')) {
            LaundryJob::firstOrCreate(
                ['idempotency_key' => "laundry:{$booking->id}:{$room->id}"],
                ['room_id' => $room->id, 'booking_id' => $booking->id, 'status' => 'suggested'],
            );
        }

        return $task;
    }

    /** Every way of giving someone work comes through here, so they are always told. */
    public function assign(HousekeepingTask $task, User $user): void
    {
        $this->assertStatus($task, ['waiting', 'pending', 'assigned', 'rejected']);
        $previous = $task->assignee;
        if ($previous?->is($user)) {
            return;
        }

        $waiting = $task->status === 'waiting';
        $task->update(['assigned_to' => $user->id, 'status' => $waiting ? 'waiting' : 'assigned']);
        // A planned room still has its guest in it, so its status does not change yet.
        if (! $waiting && $task->affectsRoom()) {
            $this->setHousekeeping($task->room, 'assigned', 'Task #'.$task->id);
        }
        $this->taskEvent($task, 'assigned', $user->name);

        $name = $task->room->name;
        if ($task->title) {
            $this->tell($user, "New task for you: {$task->title} at {$name}, due ".$task->due_at->copy()->setTimezone(Local::tz())->format('M j, g:i A').'.', $task);
            $this->tell($previous, "Task moved to someone else: {$task->title} at {$name}.", $task);

            return;
        }
        $when = $waiting ? ', after the guest checks out on '.$task->due_at->copy()->setTimezone(Local::tz())->format('M j') : '';
        $this->tell($user, "New cleaning for you: {$name}{$when}.", $task);
        $this->tell($previous, "Cleaning moved to someone else: {$name}.", $task);
    }

    /** Work a manager adds to any room, whether or not a guest has just left. */
    public function createTask(array $data, ?User $assignee): HousekeepingTask
    {
        $blocks = (bool) ($data['blocks_room'] ?? false);
        $task = HousekeepingTask::create([
            'type' => $data['type'],
            'title' => $data['title'],
            'room_id' => $data['room_id'],
            'status' => 'pending',
            'priority' => $data['priority'] ?? 'normal',
            'due_at' => $data['due_at'],
            'instructions' => $data['instructions'] ?? null,
            'blocks_room' => $blocks,
            // Someone has to clear a room that is held back, so a blocking task is always inspected.
            'requires_inspection' => $blocks || ($data['requires_inspection'] ?? true),
            'created_by' => auth()->id(),
            'checklist' => in_array($data['type'], ['cleaning', 'deep_clean'], true) ? $this->blankChecklist() : [],
        ]);
        $this->taskEvent($task, 'created', 'Added by a manager');
        if ($blocks) {
            $this->setHousekeeping($task->room, 'dirty', 'Task #'.$task->id.': '.$task->title);
        }
        if ($assignee) {
            $this->assign($task, $assignee);
        }

        return $task;
    }

    /** Checkout turnovers are not cancelled by hand: the room really does need cleaning. */
    public function cancelTask(HousekeepingTask $task): void
    {
        if ($task->type === 'turnover') {
            throw new RoomConflict('A checkout cleaning cannot be cancelled. The room needs it before the next guest.');
        }
        $this->assertStatus($task, ['pending', 'assigned', 'in_progress', 'for_inspection', 'rejected']);
        $assignee = $task->assignee;
        $task->update(['status' => 'cancelled', 'assigned_to' => null]);
        $this->taskEvent($task, 'cancelled', 'Cancelled by a manager');
        if ($task->blocks_room) {
            $this->setHousekeeping($task->room, $this->nextRoomStatus($task), 'Task #'.$task->id.' cancelled');
        }
        $this->tell($assignee, "Task cancelled: {$task->title} at {$task->room->name}.", $task);
    }

    /** A room is only ready once nothing else is still holding it back. */
    private function nextRoomStatus(HousekeepingTask $finished): string
    {
        $stillOpen = HousekeepingTask::where('room_id', $finished->room_id)->whereKeyNot($finished->id)
            ->whereIn('status', ['pending', 'assigned', 'in_progress', 'for_inspection', 'rejected'])
            ->where(fn ($q) => $q->where('type', 'turnover')->orWhere('blocks_room', true))
            ->exists();

        return $stillOpen ? 'dirty' : 'ready';
    }

    public function start(HousekeepingTask $task): void
    {
        if ($task->status === 'waiting') {
            throw new RoomConflict("The guest has not checked out of {$task->room->name} yet.");
        }
        $this->assertStatus($task, ['pending', 'assigned', 'rejected']);
        $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? auth()->id()]);
        if ($task->affectsRoom()) {
            $this->setHousekeeping($task->room, 'cleaning', 'Task #'.$task->id);
        }
        $this->taskEvent($task, 'started');
    }

    /**
     * Spreads unassigned work to the housekeeping staff with the fewest open tasks, including
     * guests due out on the given day. Returns how many were assigned.
     */
    public function assignByWorkload(?string $date = null): int
    {
        // People who do the cleaning, not those who manage it or have full access.
        $staff = User::permission('housekeeping.own')->where('is_active', true)->orderBy('id')->get()->reject(fn ($u) => $u->can('housekeeping.manage'))->values();
        if ($staff->isEmpty()) {
            throw new RoomConflict("Tick 'Housekeeping: own cleaning tasks' for an active team member first.");
        }

        if ($date) {
            foreach ($this->plannedTurnovers($date) as $turnover) {
                $this->plan(Booking::findOrFail($turnover->booking_id), Room::findOrFail($turnover->room_id), $date);
            }
        }

        $open = ['waiting', 'assigned', 'in_progress', 'pending', 'rejected'];
        $load = $staff->mapWithKeys(fn ($u) => [$u->id => HousekeepingTask::where('assigned_to', $u->id)->whereIn('status', $open)->count()])->all();
        $count = 0;
        foreach (HousekeepingTask::whereNull('assigned_to')->whereIn('status', ['waiting', 'pending', 'rejected'])->orderBy('due_at')->orderBy('id')->get() as $task) {
            $user = $staff->sortBy(fn ($u) => $load[$u->id])->first();
            $this->assign($task, $user);
            $load[$user->id]++;
            $count++;
        }

        return $count;
    }

    /** Stock only falls because use was recorded here. Runs with the completion, so a retry cannot count it twice. */
    public function recordSupplies(HousekeepingTask $task, array $supplies, int $userId): void
    {
        foreach ($supplies as $line) {
            $movement = InventoryMovement::create([
                'inventory_item_id' => $line['inventory_item_id'],
                'property_id' => $task->room->property_id,
                'room_id' => $task->room_id,
                'housekeeping_task_id' => $task->id,
                'type' => 'issue',
                'quantity' => -abs((int) $line['quantity']),
                'note' => 'Used on task #'.$task->id,
                'user_id' => $userId,
            ]);
            Audit::log('inventory.moved', $movement, ['quantity' => $movement->quantity, 'task' => $task->id]);
        }
    }

    /** Completing a clean queues inspection; it never makes the room ready. */
    public function complete(HousekeepingTask $task, array $checklist, ?string $notes): void
    {
        $this->assertStatus($task, ['in_progress']);
        if (! $task->requires_inspection) {
            $task->update(['status' => 'accepted', 'completed_at' => now(), 'inspected_at' => now(), 'checklist' => $checklist, 'completion_notes' => $notes]);
            $this->taskEvent($task, 'completed', $notes);

            return;
        }
        $task->update(['status' => 'for_inspection', 'completed_at' => now(), 'checklist' => $checklist, 'completion_notes' => $notes]);
        if ($task->affectsRoom()) {
            $this->setHousekeeping($task->room, 'for_inspection', 'Task #'.$task->id);
        }
        $this->taskEvent($task, 'completed', $notes);
    }

    public function inspect(HousekeepingTask $task, bool $accepted, ?string $note): void
    {
        $this->assertStatus($task, ['for_inspection']);
        $room = $task->room;
        $affectsRoom = $task->affectsRoom();

        if ($accepted && $affectsRoom && $room->hasOpenSafetyBlock()) {
            throw new RoomConflict("{$room->name} has an unresolved safety-critical work order and cannot be marked ready.");
        }

        $task->update([
            'status' => $accepted ? 'accepted' : 'rejected',
            'inspected_by' => auth()->id(),
            'inspected_at' => now(),
        ]);
        if ($affectsRoom) {
            $this->setHousekeeping($room, $accepted ? $this->nextRoomStatus($task) : 'dirty', 'Inspection of task #'.$task->id);
        }
        $this->taskEvent($task, $accepted ? 'inspection_passed' : 'inspection_failed', $note);
        if (! $accepted) {
            $what = $task->title ? "{$task->title} at {$room->name} needs redoing" : "{$room->name} needs re-cleaning";
            $this->tell($task->assignee, $what.($note ? ": {$note}" : '.'), $task);
        }
    }

    /** Nobody needs telling about what they just did themselves. */
    private function tell(?User $user, string $title, HousekeepingTask $task): void
    {
        if (! $user || $user->id === auth()->id()) {
            return;
        }
        // Work due on a later day opens that day, or it would not be on the list they land on.
        $due = $task->due_at?->copy()->setTimezone(Local::tz())->toDateString();
        $date = $due && $due > Local::today() ? $due : null;
        $user->notify(new SystemNotice($title, '/admin/housekeeping'.($date ? "?date={$date}" : '')));
    }

    private function blankChecklist(): array
    {
        return array_map(fn ($label) => ['label' => $label, 'done' => false], self::CHECKLIST);
    }

    public function setHousekeeping(Room $room, string $status, ?string $note = null): void
    {
        if ($room->housekeeping_status === $status) {
            return;
        }
        RoomStatusEvent::create([
            'room_id' => $room->id,
            'dimension' => 'housekeeping',
            'from_status' => $room->housekeeping_status,
            'to_status' => $status,
            'note' => $note,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
        $room->update(['housekeeping_status' => $status]);
    }

    private function assertStatus(HousekeepingTask $task, array $allowed): void
    {
        if (! in_array($task->status, $allowed, true)) {
            throw new RoomConflict("Task is {$task->status}; this action is not allowed.");
        }
    }

    private function taskEvent(HousekeepingTask $task, string $event, ?string $note = null): void
    {
        TaskEvent::create([
            'housekeeping_task_id' => $task->id,
            'event' => $event,
            'note' => $note,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
