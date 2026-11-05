<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_complete_repeatable_demo_without_leaving_a_user_signed_in(): void
    {
        $this->seed(DemoSeeder::class);

        $users = [
            'owner@uniqa-suites.test' => 'Owner',
            'manager@uniqa-suites.test' => 'Operations Manager',
            'reception@uniqa-suites.test' => 'Booking / Reception',
            'admin@uniqa-suites.test' => 'Administrator',
            'staff@uniqa-suites.test' => 'Staff',
        ];

        $this->assertSame(5, User::count());
        foreach ($users as $email => $role) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('demo-password', $user->password));
            $this->assertTrue($user->hasRole($role));
        }

        $this->assertSame(9, Room::where('is_published', true)->where('catalog_status', 'approved')->count());
        $this->assertSame(5, Booking::count());
        $this->assertSame([5, 6], Settings::get('pricing.weekend_days'));
        $this->assertSame('per_night', Settings::get('pricing.extra_guest_unit'));
        $this->assertGuest();

        $this->seed(DemoSeeder::class);

        $this->assertSame(5, User::count());
        $this->assertSame(5, Booking::count());
        $this->assertGuest();
    }
}
