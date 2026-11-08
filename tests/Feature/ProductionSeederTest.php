<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config([
            'app.env' => 'production',
        ]);
        Storage::fake('public');
        // The seeded subscription is real dates: keep these tests inside its trial whatever today is.
        $this->travelTo('2026-11-10 09:00:00');
    }

    public function test_a_clean_production_seed_creates_the_catalog_and_one_account_per_role(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        // Suites wait for the owner's approval; the Grab Cam rate card was supplied by the owner and is live.
        $this->assertSame(9, Room::suites()->count());
        $this->assertSame(0, Room::suites()->where('is_published', true)->count());
        $this->assertSame(10, Room::cameras()->where('is_published', true)->count());
        $this->assertSame(5, User::count());
        $this->assertTrue(User::where('email', 'owner@uniqasuites.com')->firstOrFail()->hasRole('Owner'));
        foreach (['Administrator' => 'admin@uniqasuites.com', 'Operations Manager' => 'manager@uniqasuites.com', 'Booking / Reception' => 'reception@uniqasuites.com', 'Staff' => 'staff@uniqasuites.com'] as $role => $email) {
            $this->assertTrue(User::where('email', $email)->firstOrFail()->hasRole($role), $role);
        }
        $this->assertTrue(User::all()->every(fn ($u) => $u->must_change_password && Hash::check('password@123', $u->password)));
        $this->assertTrue(Settings::get('pricing.weekend_days') === [5, 6], 'Settings are seeded too.');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(19, Room::count());
        $this->assertSame(5, User::count(), 'Re-seeding never adds accounts or resets a password.');

        // The agreed subscription: trial Nov 9–22, then one open bill from Nov 23. Re-seeding adds nothing.
        $plan = \App\Support\Subscription::settings();
        $this->assertSame(['2026-11-09', '2026-11-22', '2026-11-23', 400000, 3], [$plan['trial_start'], $plan['trial_end'], $plan['start'], $plan['monthly_fee_cents'], $plan['grace_days']]);
        $bill = \App\Models\SubscriptionBill::sole();
        $this->assertSame(['2026-11-23', '2026-12-22', '2026-11-23', 'unpaid'], [$bill->period_start->toDateString(), $bill->period_end->toDateString(), $bill->due_on->toDateString(), $bill->status]);
    }

    public function test_demo_accounts_and_data_are_refused_in_production(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        try {
            app(DemoSeeder::class)->run();
            $this->fail('The demo seeder should be blocked in production.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(5, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@uniqa-suites.test']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_seeded_account_must_set_its_own_password_before_anything_else(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $this->app['env'] = 'testing';
        $this->withoutMiddleware(\App\Http\Middleware\SecurityHeaders::class);
        $reception = User::where('email', 'reception@uniqasuites.com')->firstOrFail();
        $this->assertTrue($reception->can('bookings.manage'), 'Seeded staff start with the pages of their role.');

        $this->post('/login', ['email' => $reception->email, 'password' => 'password@123'])->assertRedirect();
        $this->assertAuthenticatedAs($reception);
        $this->get('/admin')->assertRedirect('/admin/account');
        $this->get('/admin/bookings')->assertRedirect('/admin/account');
        $this->get('/admin/account')->assertOk();

        $this->post('/admin/account/password', ['current_password' => 'password@123', 'password' => 'my-own-secret-9', 'password_confirmation' => 'my-own-secret-9'])->assertSessionHasNoErrors();
        $this->assertFalse($reception->fresh()->must_change_password);
        $this->get('/admin')->assertOk();
    }
}
