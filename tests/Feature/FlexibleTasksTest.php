<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Local;
use App\Support\Permissions;
use Tests\DomainTestCase;

class FlexibleTasksTest extends DomainTestCase
{
    private function cleaner(string $name = 'Synthetic Cleaner'): User
    {
        $user = User::factory()->create(['name' => $name]);
        Permissions::apply($user, 'Staff', ['housekeeping_own']);

        return $user->fresh();
    }

    private function day(int $offset = 0): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function add(array $overrides = [])
    {
        return $this->post('/admin/tasks', $overrides + [
            'room_id' => $this->room('venice')->id,
            'title' => 'Replace the bathroom curtain',
            'type' => 'other',
            'date' => $this->day(),
            'time' => '15:00',
            'priority' => 'normal',
        ]);
    }

    private function tasksOn(?string $date = null, ?User $as = null): array
    {
        if ($as) {
            $this->actingAs($as);
        }

        return $this->get('/admin/housekeeping'.($date ? "?date={$date}" : ''))->assertOk()->original->getData()['page']['props']['tasks'];
    }

    public function test_a_manager_adds_a_task_to_a_room_with_no_checkout_and_the_cleaner_is_notified(): void
    {
        $cleaner = $this->cleaner();
        $this->room('venice')->update(['housekeeping_status' => 'ready']);

        $this->add(['user_id' => $cleaner->id])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $task = HousekeepingTask::sole();
        $this->assertSame('other', $task->type);
        $this->assertSame('assigned', $task->status);
        $this->assertNull($task->booking_id);
        $this->assertStringContainsString('New task for you: Replace the bathroom curtain at VENICE', $cleaner->notifications()->sole()->data['title']);
        $this->assertSame('15:00', $task->due_at->copy()->setTimezone(Local::tz())->format('H:i'));
    }

    public function test_an_ordinary_task_never_stops_a_room_being_booked_or_checked_in(): void
    {
        $this->room('venice')->update(['housekeeping_status' => 'ready']);
        $cleaner = $this->cleaner();
        $this->add(['user_id' => $cleaner->id]);
        $task = HousekeepingTask::sole();

        $this->assertSame('ready', $this->room('venice')->housekeeping_status);

        $booking = $this->confirmed('venice', $this->day(), $this->day(2));
        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start")->assertSessionMissing('error');
        $this->assertSame('ready', $this->room('venice')->housekeeping_status, 'starting it changes nothing about the room');

        $this->actingAs($this->owner);
        $this->assertSame(Booking::CHECKED_IN, $this->bookings->checkIn($booking)->status);
    }

    public function test_it_can_be_done_while_a_guest_is_staying_and_says_so_without_naming_them(): void
    {
        $booking = $this->confirmed('venice', $this->day(), $this->day(2));
        $this->room('venice')->update(['housekeeping_status' => 'ready']);
        $this->bookings->checkIn($booking);
        $this->add();

        $row = collect($this->tasksOn())->firstWhere('title', 'Replace the bathroom curtain');
        $this->assertTrue($row['guest_in']);
        $this->assertStringNotContainsString($booking->guest->full_name, $this->get('/admin/housekeeping')->getContent());
    }

    public function test_a_held_room_cannot_check_in_until_the_task_passes_inspection(): void
    {
        $room = $this->room('venice');
        $room->update(['housekeeping_status' => 'ready']);
        $booking = $this->confirmed('venice', $this->day(), $this->day(2));
        $this->add(['blocks_room' => true, 'requires_inspection' => false]);
        $task = HousekeepingTask::sole();

        $this->assertTrue($task->requires_inspection, 'a hold always needs someone to clear it');
        $this->assertSame('dirty', $room->fresh()->housekeeping_status);

        try {
            $this->bookings->checkIn($booking);
            $this->fail('A held room must refuse check-in');
        } catch (RoomConflict) {
            $this->assertTrue(true);
        }

        $this->post("/admin/tasks/{$task->id}/start")->assertSessionMissing('error');
        $this->post("/admin/tasks/{$task->id}/complete", [])->assertSessionHasNoErrors();
        $this->assertSame('for_inspection', $room->fresh()->housekeeping_status);
        $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => true])->assertSessionMissing('error');

        $this->assertSame('ready', $room->fresh()->housekeeping_status);
        $this->assertSame(Booking::CHECKED_IN, $this->bookings->checkIn($booking->fresh())->status);
    }

    public function test_a_room_stays_not_ready_until_its_last_holding_task_is_finished(): void
    {
        $room = $this->room('venice');
        $room->update(['housekeeping_status' => 'ready']);
        $this->add(['title' => 'First', 'blocks_room' => true]);
        $this->add(['title' => 'Second', 'blocks_room' => true]);
        [$first, $second] = HousekeepingTask::orderBy('id')->get()->all();

        foreach ([$first, $second] as $i => $task) {
            $this->post("/admin/tasks/{$task->id}/start");
            $this->post("/admin/tasks/{$task->id}/complete", []);
            $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => true]);
            $this->assertSame($i === 0 ? 'dirty' : 'ready', $room->fresh()->housekeeping_status);
        }
    }

    public function test_passing_a_checkout_cleaning_does_not_clear_a_holding_task_on_the_same_room(): void
    {
        $booking = $this->confirmed('venice', $this->day(-1), $this->day());
        $this->bookings->checkIn($booking, true, 'test');
        $this->bookings->checkOut($booking);
        $this->add(['title' => 'Fix the lock', 'blocks_room' => true]);
        $turnover = HousekeepingTask::where('type', 'turnover')->sole();

        $this->post("/admin/tasks/{$turnover->id}/start");
        $this->post("/admin/tasks/{$turnover->id}/complete", ['checklist' => [['label' => 'Floors cleaned', 'done' => true]]]);
        $this->post("/admin/tasks/{$turnover->id}/inspect", ['accepted' => true])->assertSessionMissing('error');

        $this->assertSame('dirty', $this->room('venice')->housekeeping_status);
    }

    public function test_a_task_without_inspection_finishes_straight_away_and_tells_no_inspector(): void
    {
        $cleaner = $this->cleaner();
        $this->add(['user_id' => $cleaner->id, 'requires_inspection' => false]);
        $task = HousekeepingTask::sole();
        $this->owner->notifications()->delete();

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start")->assertSessionMissing('error');
        $this->post("/admin/tasks/{$task->id}/complete", [])->assertSessionHasNoErrors()->assertSessionHas('success', 'Task finished.');

        $this->assertSame('accepted', $task->fresh()->status);
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame('done', collect($this->tasksOn(null, $this->owner))->firstWhere('id', $task->id)['bucket']);
    }

    public function test_a_future_task_shows_on_its_day_and_the_notice_opens_that_day(): void
    {
        $cleaner = $this->cleaner();
        $this->add(['user_id' => $cleaner->id, 'date' => $this->day(5)]);
        $task = HousekeepingTask::sole();

        $this->assertStringContainsString('date='.$this->day(5), $cleaner->notifications()->sole()->data['url']);
        $this->assertFalse(collect($this->tasksOn())->contains('id', $task->id) && $task->status === 'waiting');
        $this->assertTrue(collect($this->tasksOn($this->day(5)))->contains('id', $task->id));
    }

    public function test_only_a_task_due_today_can_hold_a_room(): void
    {
        $this->room('venice')->update(['housekeeping_status' => 'ready']);

        $this->add(['blocks_room' => true, 'date' => $this->day(3)])->assertSessionHas('error');

        $this->assertSame(0, HousekeepingTask::count());
        $this->assertSame('ready', $this->room('venice')->housekeeping_status);
    }

    public function test_cancelling_a_task_releases_the_room_and_tells_the_cleaner(): void
    {
        $cleaner = $this->cleaner();
        $room = $this->room('venice');
        $room->update(['housekeeping_status' => 'ready']);
        $this->add(['user_id' => $cleaner->id, 'blocks_room' => true]);
        $task = HousekeepingTask::sole();

        $this->post("/admin/tasks/{$task->id}/cancel")->assertSessionMissing('error');

        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame('ready', $room->fresh()->housekeeping_status);
        $this->assertStringContainsString('Task cancelled', $cleaner->notifications()->latest()->get()->pluck('data.title')->join(' '));
        $this->assertFalse(collect($this->tasksOn())->contains('id', $task->id));
    }

    public function test_a_checkout_cleaning_cannot_be_cancelled(): void
    {
        $booking = $this->confirmed('venice', $this->day(-1), $this->day());
        $this->bookings->checkIn($booking, true, 'test');
        $this->bookings->checkOut($booking);
        $task = HousekeepingTask::sole();

        $this->post("/admin/tasks/{$task->id}/cancel")->assertSessionHas('error');

        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_checkouts_still_create_cleanings_alongside_added_tasks(): void
    {
        $booking = $this->confirmed('venice', $this->day(-1), $this->day());
        $this->bookings->checkIn($booking, true, 'test');
        $this->add(['title' => 'Change the light bulb']);
        $this->bookings->checkOut($booking);

        $rows = collect($this->tasksOn());
        $this->assertTrue($rows->contains('type', 'turnover'));
        $this->assertTrue($rows->contains('title', 'Change the light bulb'));
        $this->assertSame('dirty', $this->room('venice')->housekeeping_status);
    }

    public function test_only_managers_can_add_or_cancel_tasks_and_cleaners_see_only_theirs(): void
    {
        $mine = $this->cleaner('Mine');
        $other = $this->cleaner('Other');
        $this->add(['title' => 'For mine', 'user_id' => $mine->id]);
        $this->add(['title' => 'For other', 'user_id' => $other->id]);
        $task = HousekeepingTask::where('title', 'For mine')->sole();

        $this->actingAs($mine);
        $this->add()->assertForbidden();
        $this->post("/admin/tasks/{$task->id}/cancel")->assertForbidden();

        $titles = collect($this->tasksOn())->pluck('title')->all();
        $this->assertSame(['For mine'], $titles);
    }

    public function test_an_ordinary_task_can_finish_while_a_safety_issue_is_open_on_the_room(): void
    {
        $this->add();
        $task = HousekeepingTask::sole();
        WorkOrder::create(['room_id' => $this->room('venice')->id, 'title' => 'Exposed wiring', 'severity' => 'critical', 'safety_block' => true, 'status' => 'open']);

        $this->post("/admin/tasks/{$task->id}/start");
        $this->post("/admin/tasks/{$task->id}/complete", []);
        $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => true])->assertSessionMissing('error');

        $this->assertSame('accepted', $task->fresh()->status);
    }

    public function test_a_task_needs_a_real_room_and_a_sensible_date(): void
    {
        $this->add(['room_id' => 999999])->assertSessionHasErrors('room_id');
        $this->add(['title' => ''])->assertSessionHasErrors('title');
        $this->add(['type' => 'nonsense'])->assertSessionHasErrors('type');
        $this->add(['date' => $this->day(-1)])->assertSessionHasErrors('date');
        $this->assertSame(0, HousekeepingTask::count());
    }
}
