<?php

namespace Tests\Feature;

use App\Models\Booking;
use Database\Seeders\SimulationSeeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\DomainTestCase;

/** The simulation writes hundreds of fake bookings, so it must never be able to reach a real database. */
class SimulationGuardTest extends DomainTestCase
{
    public function test_the_seeder_refuses_any_database_not_ending_in_sim(): void
    {
        foreach (['db_uniquasuites', 'db_uniquasuites_test', 'simulation', 'db_sim_real'] as $name) {
            config(['database.connections.'.config('database.default').'.database' => $name]);

            try {
                app(SimulationSeeder::class)->run();
                $this->fail("It ran against {$name}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must end in _sim', $e->getMessage());
            }
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_the_simulation_commands_do_not_exist_outside_the_simulation_database(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertNotContains('uniqa:simulate', $commands);
        $this->assertNotContains('uniqa:sim-guard', $commands);
    }
}
