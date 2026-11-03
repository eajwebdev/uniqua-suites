<?php

namespace App\Domain\Tasks;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\LaundryJob;
use App\Models\Room;
use App\Models\RoomStatusEvent;
use App\Models\TaskEvent;
use App\Models\User;
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
