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
use App\Support\Settings;

class TurnoverService
{
    public const CHECKLIST = ['Bed linen changed', 'Bathroom cleaned', 'Floors cleaned', 'Trash removed', 'Refills restocked', 'Appliances checked', 'Before/after photos taken'];

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
                'checklist' => array_map(fn ($label) => ['label' => $label, 'done' => false], self::CHECKLIST),
            ],
        );

        if ($task->wasRecentlyCreated) {
            $this->setHousekeeping($room, 'dirty', 'Checkout '.$booking->reference);
            $this->taskEvent($task, 'created', 'Created by checkout');
        }

        if (Settings::get('laundry.auto_create')) {
            LaundryJob::firstOrCreate(
                ['idempotency_key' => "laundry:{$booking->id}:{$room->id}"],
                ['room_id' => $room->id, 'booking_id' => $booking->id, 'status' => 'suggested'],
            );
        }

        return $task;
    }

    public function assign(HousekeepingTask $task, User $user): void
    {
        $this->assertStatus($task, ['pending', 'assigned', 'rejected']);
        $task->update(['assigned_to' => $user->id, 'status' => 'assigned']);
        $this->setHousekeeping($task->room, 'assigned', 'Task #'.$task->id);
        $this->taskEvent($task, 'assigned', $user->name);
    }

    public function start(HousekeepingTask $task): void
    {
        $this->assertStatus($task, ['pending', 'assigned', 'rejected']);
        $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? auth()->id()]);
        $this->setHousekeeping($task->room, 'cleaning', 'Task #'.$task->id);
        $this->taskEvent($task, 'started');
    }

    /** Spreads unassigned work to the housekeeping staff with the fewest open tasks. Returns how many were assigned. */
    public function assignByWorkload(): int
    {
        // People who do the cleaning, not those who manage it or have full access.
        $staff = User::permission('housekeeping.own')->where('is_active', true)->orderBy('id')->get()->reject(fn ($u) => $u->can('housekeeping.manage'))->values();
        if ($staff->isEmpty()) {
            throw new RoomConflict("Tick 'Housekeeping: own cleaning tasks' for an active team member first.");
        }

        $open = ['assigned', 'in_progress', 'pending', 'rejected'];
        $load = $staff->mapWithKeys(fn ($u) => [$u->id => HousekeepingTask::where('assigned_to', $u->id)->whereIn('status', $open)->count()])->all();
        $count = 0;
        foreach (HousekeepingTask::whereNull('assigned_to')->whereIn('status', ['pending', 'rejected'])->orderBy('due_at')->orderBy('id')->get() as $task) {
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
        $task->update(['status' => 'for_inspection', 'completed_at' => now(), 'checklist' => $checklist, 'completion_notes' => $notes]);
        $this->setHousekeeping($task->room, 'for_inspection', 'Task #'.$task->id);
        $this->taskEvent($task, 'completed', $notes);
    }

    public function inspect(HousekeepingTask $task, bool $accepted, ?string $note): void
    {
        $this->assertStatus($task, ['for_inspection']);
        $room = $task->room;

        if ($accepted && $room->hasOpenSafetyBlock()) {
            throw new RoomConflict("{$room->name} has an unresolved safety-critical work order and cannot be marked ready.");
        }

        $task->update([
            'status' => $accepted ? 'accepted' : 'rejected',
            'inspected_by' => auth()->id(),
            'inspected_at' => now(),
        ]);
        $this->setHousekeeping($room, $accepted ? 'ready' : 'dirty', 'Inspection of task #'.$task->id);
        $this->taskEvent($task, $accepted ? 'inspection_passed' : 'inspection_failed', $note);
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
