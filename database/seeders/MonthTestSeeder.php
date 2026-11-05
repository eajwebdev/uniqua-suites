<?php

namespace Database\Seeders;

use App\Domain\Bookings\BookingService;
use App\Domain\Tasks\TurnoverService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use App\Support\Local;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two samples of every booking status and every cleaning status, on three suites, around today.
 * Fully synthetic, for trying the screens by hand. It runs only on a database whose name ends in
 * _sim: use .\scripts\simulation.ps1 -Month
 */
class MonthTestSeeder extends Seeder
{
    private BookingService $bookings;

    private TurnoverService $turnover;

    private int $guestNumber = 0;

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_sim')) {
            throw new RuntimeException("Refusing to seed test data into [{$database}]: its name must end in _sim.");
        }

        $this->call(DatabaseSeeder::class);
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved', 'housekeeping_status' => 'ready']);

        foreach (SimulationSeeder::ACCOUNTS as $email => [$name, $role, $pages]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => SimulationSeeder::PASSWORD, 'is_active' => true],
            );
            Permissions::apply($user, $role, $pages);
            Employee::updateOrCreate(
                ['user_id' => $user->id],
                ['full_name' => $name, 'position' => $role, 'is_active' => true],
            );
        }
        auth()->login(User::where('email', 'owner@sim.test')->firstOrFail());

        $this->bookings = app(BookingService::class);
        $this->turnover = app(TurnoverService::class);

        $finished = $this->bookingsInEveryStatus();
        $this->cleaningInEveryStatus($finished);

        auth()->logout();
    }

    /** Oldest first, because a room's next guest can only check in once the last one has left. */
    private function bookingsInEveryStatus(): array
    {
        // Checked out: each leaves a real checkout cleaning behind.
        $left = [
            [$this->checkedOut('venice', -4, -3), 'venice', -3],
            [$this->checkedOut('bali', -4, -2), 'bali', -2],
        ];

        // No-show.
        foreach ([['tokyo', -4, -3], ['venice', -3, -2]] as [$slug, $in, $out]) {
            $this->bookings->markNoShow($this->bookings->confirm($this->inquiry($slug, $in, $out, 'walk_in')), 'Test sample');
        }

        // Checked in, leaving today.
        $staying = [];
        foreach (['venice', 'bali'] as $slug) {
            $staying[$slug] = $this->bookings->checkIn($this->bookings->confirm($this->inquiry($slug, -1, 0, 'airbnb')), true, 'Test sample');
        }

        // Confirmed. VENICE's arrives today, as its last guest leaves, so it shows a real deadline.
        $this->bookings->confirm($this->inquiry('venice', 0, 2, 'facebook_messenger'));
        $this->bookings->confirm($this->inquiry('tokyo', 3, 5, 'airbnb'));

        // On hold: a week, so the samples stay on hold while you test.
        $this->bookings->hold($this->inquiry('bali', 2, 4, 'instagram'), 168);
        $this->bookings->hold($this->inquiry('tokyo', 7, 9, 'phone'), 168);

        // Inquiry.
        $this->inquiry('venice', 6, 8, 'facebook_messenger');
        $this->inquiry('bali', 6, 7, 'instagram');

        // Cancelled.
        $this->bookings->cancel($this->inquiry('tokyo', 10, 12, 'phone'), 'Test sample: guest changed plans');
        $this->bookings->cancel($this->bookings->confirm($this->inquiry('venice', 10, 11, 'airbnb')), 'Test sample: guest changed plans');

        // Expired hold.
        foreach ([['bali', 9, 10], ['tokyo', 11, 12]] as [$slug, $in, $out]) {
            Booking::whereKey($this->bookings->hold($this->inquiry($slug, $in, $out, 'referral'))->id)->update(['hold_expires_at' => now()->subHour()]);
        }
        $this->bookings->expireHolds();

        return ['left' => $left, 'staying' => $staying];
    }

    private function cleaningInEveryStatus(array $finished): void
    {
        $one = User::where('email', 'cleaner1@sim.test')->firstOrFail();
        $two = User::where('email', 'cleaner2@sim.test')->firstOrFail();
        // Due times count from now, so every state shows whatever time of day this runs: late work is At risk, the rest is not.
        $add = fn (string $slug, string $title, string $type, int $dueInHours, ?User $who) => $this->turnover->createTask([
            'type' => $type,
            'title' => "TEST {$title}",
            'room_id' => Room::where('slug', $slug)->value('id'),
            'due_at' => now()->addHours($dueInHours),
        ], $who);

        // Waiting: guests still in the room, due out today.
        $this->turnover->assign($this->turnover->plan($finished['staying']['venice'], Room::where('slug', 'venice')->firstOrFail(), Local::today()), $one);
        $this->turnover->plan($finished['staying']['bali'], Room::where('slug', 'bali')->firstOrFail(), Local::today());

        // Not started, nobody assigned.
        $add('tokyo', 'Replace the bathroom curtain', 'other', 3, null);
        $add('bali', 'Restock toiletries', 'restock', 4, null);

        // Assigned, not started.
        $add('tokyo', 'Clean the balcony', 'cleaning', 3, $one);
        $add('venice', 'Change the light bulb', 'other', -1, $two);

        // In progress.
        foreach ([['tokyo', 'Deep clean the kitchen', 'deep_clean', 2, $one], ['bali', 'Wash the curtains', 'deep_clean', -1, $two]] as $task) {
            $this->turnover->start($add(...$task));
        }

        // Waiting for inspection.
        foreach ([['tokyo', 'Clean the windows', 'cleaning', 2, $two], ['venice', 'Check the aircon filter', 'inspection', 3, $one]] as $task) {
            $this->finish($add(...$task), 'Test sample: done.');
        }

        // Failed inspection: to be cleaned again.
        foreach ([['tokyo', 'Clean the bathroom', 'cleaning', 3, $one, 'Mirror still has marks'], ['bali', 'Vacuum the sofa', 'cleaning', -2, $two, 'Missed under the cushions']] as [$slug, $title, $type, $dueInHours, $who, $note]) {
            $task = $add($slug, $title, $type, $dueInHours, $who);
            $this->finish($task, null);
            $this->turnover->inspect($task->fresh(), false, $note);
        }

        // Passed: the checkout cleanings from the stays that have ended.
        foreach ($finished['left'] as [$booking, $slug]) {
            $task = HousekeepingTask::where('booking_id', $booking->id)->where('type', 'turnover')->firstOrFail();
            $this->turnover->assign($task, $one);
            $this->finish($task->fresh(), null);
            $this->turnover->inspect($task->fresh(), true, null);
        }

        // Cancelled. These are hidden from the Cleaning list on purpose.
        foreach ([['tokyo', 'Polish the table', 'other', 2, $one], ['venice', 'Move the sofa', 'other', 2, null]] as $task) {
            $this->turnover->cancelTask($add(...$task));
        }
    }

    private function finish(HousekeepingTask $task, ?string $notes): void
    {
        $this->turnover->start($task);
        $this->turnover->complete($task->fresh(), $task->checklist ?? [], $notes);
    }

    private function checkedOut(string $slug, int $in, int $out): Booking
    {
        $booking = $this->bookings->confirm($this->inquiry($slug, $in, $out, 'airbnb'));
        $this->bookings->checkIn($booking, true, 'Test sample');
        $this->bookings->checkOut($booking->fresh());

        // When it really happened, rather than the moment this seeder ran.
        $when = CarbonImmutable::parse($this->date($out).' 12:00', Local::tz())->utc();
        DB::table('bookings')->where('id', $booking->id)->update(['actual_check_in_at' => CarbonImmutable::parse($this->date($in).' 14:00', Local::tz())->utc(), 'actual_check_out_at' => $when]);

        return $booking->fresh();
    }

    private function inquiry(string $slug, int $in, int $out, string $source): Booking
    {
        $this->guestNumber++;

        return $this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => sprintf('TEST Guest %02d', $this->guestNumber), 'phone' => '0900-000-0000'])->id,
            'room_ids' => [Room::where('slug', $slug)->value('id')],
            'booking_source_id' => BookingSource::where('code', $source)->value('id'),
            'check_in' => $this->date($in),
            'check_out' => $this->date($out),
            'adults' => 2,
        ]);
    }

    private function date(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }
}
