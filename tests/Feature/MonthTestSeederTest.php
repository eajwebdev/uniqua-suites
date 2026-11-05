<?php

namespace Tests\Feature;

use App\Domain\Availability\InventoryService;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Support\Local;
use Database\Seeders\MonthTestSeeder;
use RuntimeException;
use Tests\DomainTestCase;

class MonthTestSeederTest extends DomainTestCase
{
    private function seedMonth(): void
    {
        // The connection stays on the test database. Only the name the guard reads changes.
        $key = 'database.connections.'.config('database.default').'.database';
        $real = config($key);
        config([$key => 'db_uniquasuites_sim']);
        $this->app->make(MonthTestSeeder::class)->run();
        config([$key => $real]);
        $this->actingAs($this->owner);
    }

    public function test_it_refuses_any_database_not_ending_in_sim(): void
    {
        foreach (['db_uniquasuites', 'db_uniquasuites_test'] as $name) {
            config(['database.connections.'.config('database.default').'.database' => $name]);

            try {
                $this->app->make(MonthTestSeeder::class)->run();
                $this->fail("It ran against {$name}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must end in _sim', $e->getMessage());
            }
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_it_makes_two_of_every_booking_status(): void
    {
        $this->seedMonth();

        $expected = array_fill_keys([Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT, Booking::CANCELLED, Booking::NO_SHOW, Booking::EXPIRED], 2);
        $actual = Booking::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
        $this->assertSame(16, Guest::where('full_name', 'like', 'TEST Guest %')->count());
    }

    public function test_it_makes_active_employee_records_for_every_sample_account(): void
    {
        $this->seedMonth();

        $employees = Employee::with('user')->orderBy('id')->get();

        $this->assertCount(count(\Database\Seeders\SimulationSeeder::ACCOUNTS), $employees);
        $this->assertTrue($employees->every(fn (Employee $employee) => $employee->is_active && $employee->user_id));
        $this->assertEqualsCanonicalizing(
            array_keys(\Database\Seeders\SimulationSeeder::ACCOUNTS),
            $employees->pluck('user.email')->all(),
        );
    }

    public function test_it_makes_two_of_every_cleaning_status(): void
    {
        $this->seedMonth();

        $expected = array_fill_keys(['waiting', 'pending', 'assigned', 'in_progress', 'for_inspection', 'rejected', 'accepted', 'cancelled'], 2);
        $actual = HousekeepingTask::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_it_uses_three_suites_with_no_clashes_and_shows_on_the_cleaning_page(): void
    {
        $this->seedMonth();

        $this->assertEqualsCanonicalizing(['VENICE', 'BALI', 'TOKYO'], \DB::table('booking_rooms as br')->join('rooms as r', 'r.id', '=', 'br.room_id')->distinct()->pluck('r.name')->all());
        $this->assertCount(0, app(InventoryService::class)->overlaps('2000-01-01'));

        $shown = collect($this->get('/admin/housekeeping')->assertOk()->original->getData()['page']['props']['tasks'])->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['accepted', 'assigned', 'for_inspection', 'in_progress', 'pending', 'rejected', 'waiting'], $shown);
        $this->assertSame(Local::today(), Booking::where('status', Booking::CHECKED_IN)->first()->check_out->toDateString());
    }
}
