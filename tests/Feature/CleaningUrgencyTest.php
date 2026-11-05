<?php

namespace Tests\Feature;

use App\Domain\Tasks\TurnoverService;
use App\Models\HousekeepingTask;
use Tests\DomainTestCase;

/** "Late" and "Due soon" are what staff act on, so they must never cry wolf. */
class CleaningUrgencyTest extends DomainTestCase
{
    private function task(array $extra = []): HousekeepingTask
    {
        return app(TurnoverService::class)->createTask($extra + ['type' => 'cleaning', 'title' => 'Synthetic', 'room_id' => $this->room('rio')->id, 'due_at' => now()->addHours(6)], null);
    }

    private function urgency(HousekeepingTask $task): ?string
    {
        $row = collect($this->get('/admin/housekeeping')->assertOk()->original->getData()['page']['props']['tasks'])->firstWhere('id', $task->id);

        return $row['urgency'];
    }

    public function test_plenty_of_time_says_nothing(): void
    {
        $this->assertNull($this->urgency($this->task()));
    }

    public function test_past_its_time_is_late(): void
    {
        $this->assertSame('late', $this->urgency($this->task(['due_at' => now()->subMinutes(5)])));
    }

    public function test_within_the_hour_with_nobody_on_it_is_due_soon(): void
    {
        $this->assertSame('soon', $this->urgency($this->task(['due_at' => now()->addMinutes(30)])));
    }

    public function test_within_the_hour_with_someone_on_it_is_fine(): void
    {
        $task = $this->task(['due_at' => now()->addMinutes(30)]);
        app(TurnoverService::class)->start($task);

        $this->assertNull($this->urgency($task));
    }

    public function test_someone_still_working_past_the_time_is_late(): void
    {
        $task = $this->task(['due_at' => now()->subMinutes(5)]);
        app(TurnoverService::class)->start($task);

        $this->assertSame('late', $this->urgency($task));
    }

    public function test_finished_work_waiting_for_a_check_does_not_blame_the_cleaner(): void
    {
        $task = $this->task(['due_at' => now()->subMinutes(5), 'requires_inspection' => true]);
        $service = app(TurnoverService::class);
        $service->start($task);
        $service->complete($task->fresh(), [], null);
        $this->assertSame('for_inspection', $task->fresh()->status);

        $this->assertNull($this->urgency($task));
    }

    public function test_a_passed_task_is_never_late(): void
    {
        $task = $this->task(['due_at' => now()->subMinutes(5), 'requires_inspection' => true]);
        $service = app(TurnoverService::class);
        $service->start($task);
        $service->complete($task->fresh(), [], null);
        $service->inspect($task->fresh(), true, null);

        $date = now()->setTimezone('Asia/Manila')->toDateString();
        $row = collect($this->get("/admin/housekeeping?date={$date}")->assertOk()->original->getData()['page']['props']['tasks'])->firstWhere('id', $task->id);

        $this->assertNull($row['urgency']);
    }
}
