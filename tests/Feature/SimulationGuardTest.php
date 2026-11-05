<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Database\Seeders\SimulationSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\DomainTestCase;

/** Direct seeding uses .env; helper simulation commands remain isolated to the simulation setup. */
class SimulationGuardTest extends DomainTestCase
{
    public function test_the_seeder_uses_the_current_configured_database(): void
    {
        app(SimulationSeeder::class)->run();

        $this->assertGreaterThan(0, Booking::count());
        $this->assertTrue(User::where('email', 'owner@sim.test')->firstOrFail()->hasRole('Owner'));
    }

    public function test_the_simulation_commands_do_not_exist_outside_the_simulation_database(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertNotContains('uniqa:simulate', $commands);
        $this->assertNotContains('uniqa:sim-guard', $commands);
    }
}
