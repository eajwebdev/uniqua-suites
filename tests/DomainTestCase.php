<?php

namespace Tests;

use App\Domain\Bookings\BookingService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Support\PublicUrl;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class DomainTestCase extends TestCase
{
    use RefreshDatabase;

    /** Permission sets for tests to sign in with. Real people get theirs from page ticks: see PageAccessTest. */
    private const FIXTURE_ROLES = [
        'Operations Manager' => [
            'dashboard.view', 'bookings.view', 'bookings.manage', 'bookings.override_readiness', 'guests.view', 'guests.manage', 'quotes.manage',
            'rooms.view', 'reports.view', 'housekeeping.manage', 'housekeeping.inspect', 'housekeeping.own',
            'maintenance.manage', 'maintenance.own', 'incidents.view', 'incidents.manage', 'inventory.view', 'inventory.manage', 'inventory.consume',
            'parking.view', 'parking.manage', 'secrets.reveal',
        ],
        'Booking / Reception' => [
            'dashboard.view', 'bookings.view', 'bookings.manage', 'bookings.confirm', 'guests.view', 'guests.manage', 'quotes.manage',
            'rooms.view', 'payments.view', 'payments.record', 'housekeeping.inspect', 'incidents.view', 'incidents.manage', 'parking.view', 'parking.manage', 'secrets.reveal',
        ],
        'Finance / Bookkeeper' => [
            'dashboard.view', 'dashboard.finance', 'bookings.view', 'guests.view', 'rooms.view',
            'payments.view', 'payments.record', 'payments.verify', 'settlements.manage',
            'expenses.view', 'expenses.manage', 'reports.view', 'reports.finance', 'reports.export', 'incidents.view',
        ],
        'Housekeeping / Laundry' => ['housekeeping.own', 'inventory.consume'],
        'Maintenance' => ['maintenance.own'],
        'Staff Advances / Payroll' => ['advances.manage'],
        'Auditor' => ['dashboard.view', 'dashboard.finance', 'bookings.view', 'rooms.view', 'payments.view', 'expenses.view', 'reports.view', 'reports.finance', 'reports.export', 'audit.view'],
    ];

    protected BookingService $bookings;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Feed hosts in tests are fictional and must not depend on real DNS.
        PublicUrl::$resolver = fn () => ['93.184.216.34'];
        $this->seed(DatabaseSeeder::class);
        foreach (self::FIXTURE_ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Settings::set('pricing.weekend_days', [6, 7]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('Owner');
        $this->actingAs($this->owner);
        $this->bookings = app(BookingService::class);
    }

    protected function room(string $slug): Room
    {
        return Room::where('slug', $slug)->firstOrFail();
    }

    protected function source(string $code = 'facebook_messenger'): int
    {
        return BookingSource::where('code', $code)->value('id');
    }

    protected function inquiry(string $slug = 'venice', string $in = '2031-03-03', string $out = '2031-03-05', int $adults = 2, string $source = 'facebook_messenger'): Booking
    {
        return $this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => 'Synthetic Guest '.uniqid()])->id,
            'room_ids' => [$this->room($slug)->id],
            'booking_source_id' => $this->source($source),
            'check_in' => $in,
            'check_out' => $out,
            'adults' => $adults,
        ]);
    }

    protected function confirmed(string $slug = 'venice', string $in = '2031-03-03', string $out = '2031-03-05', string $source = 'facebook_messenger', int $adults = 2): Booking
    {
        return $this->bookings->confirm($this->inquiry($slug, $in, $out, $adults, $source));
    }
}
