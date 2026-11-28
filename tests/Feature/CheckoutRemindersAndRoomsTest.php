<?php

namespace Tests\Feature;

use App\Domain\Bookings\CheckoutReminders;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

/** Alerts a few hours before a check-out or camera return, and the phone-friendly Rooms & Properties page. */
class CheckoutRemindersAndRoomsTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
        Settings::set('pricing.standard_check_out', '11:00');
        Settings::set('alerts.checkout_lead_hours', 2);
    }

    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, config('uniqa.display_timezone')));
    }

    private function inHouse(string $slug, string $in, string $out, ?string $returnAt = null): Booking
    {
        $booking = $this->confirmed($slug, $in, $out, adults: 1);
        $booking->forceFill(['status' => Booking::CHECKED_IN] + ($returnAt ? ['return_at' => $returnAt] : []))->save();

        return $booking->fresh();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function remind(): int
    {
        return app(CheckoutReminders::class)->send();
    }

    private function titles(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.title')->all();
    }

    public function test_front_desk_and_housekeeping_hear_two_hours_before_a_guest_checks_out(): void
    {
        $this->at('2031-03-01 08:00');
        $booking = $this->inHouse('venice', '2031-02-27', '2031-03-01');
        $desk = $this->staff('Booking / Reception');
        $housekeeping = $this->staff('Operations Manager');
        $maintenance = $this->staff('Maintenance');

        $this->assertSame(0, $this->remind(), 'Three hours before is too early.');

        $this->at('2031-03-01 09:15');
        $this->assertSame(1, $this->remind());
        $this->assertSame(0, $this->remind(), 'One reminder per check-out.');

        $title = $this->titles($desk)[0] ?? '';
        $this->assertStringContainsString('VENICE', $title);
        $this->assertStringContainsString('11:00 AM', $title);
        $this->assertStringContainsString($booking->reference, $title);
        $this->assertCount(1, $this->titles($housekeeping));
        $this->assertCount(1, $this->titles($this->owner), 'Someone with both permissions is told once.');
        $this->assertSame([], $this->titles($maintenance));
    }

    public function test_an_extended_stay_is_reminded_again_for_the_new_time(): void
    {
        $this->at('2031-03-01 09:30');
        $booking = $this->inHouse('venice', '2031-02-27', '2031-03-01');
        $this->assertSame(1, $this->remind());

        $booking->forceFill(['check_out' => '2031-03-02'])->save();
        $this->at('2031-03-02 09:30');
        $this->assertSame(1, $this->remind());
        $this->assertCount(2, $this->titles($this->owner));
    }

    public function test_a_camera_due_back_alerts_front_desk_only(): void
    {
        $this->at('2031-03-01 14:00');
        $booking = $this->inHouse('dji-nano', '2031-02-28', '2031-03-01', '2031-03-01 15:30:00');
        $desk = $this->staff('Booking / Reception');
        $housekeeping = $this->staff('Housekeeping / Laundry');
        // A housekeeping manager without front-desk access is not told about cameras.
        $cleaningLead = User::factory()->create();
        $cleaningLead->givePermissionTo('housekeeping.manage');

        $this->assertSame(1, $this->remind());

        $title = $this->titles($desk)[0] ?? '';
        $this->assertStringContainsString('due back at 3:30 PM', $title);
        $this->assertStringContainsString('in about 2 hours', $title);
        $this->assertSame([], $this->titles($housekeeping));
        $this->assertSame([], $this->titles($cleaningLead));
        $this->assertSame($booking->id, (int) str_replace('/admin/bookings/', '', $desk->fresh()->notifications->first()->data['url']));
    }

    public function test_no_reminder_when_already_late_not_checked_in_or_muted(): void
    {
        $this->at('2031-03-01 11:30');
        $this->inHouse('venice', '2031-02-27', '2031-03-01'); // past 11:00: a late check-out, not a heads-up
        $this->confirmed('paris', '2031-02-27', '2031-03-01', adults: 1); // never checked in
        $this->assertSame(0, $this->remind());

        $this->at('2031-03-05 09:30');
        $this->owner->forceFill(['muted_notifications' => ['checkouts']])->save();
        $this->inHouse('milan', '2031-03-03', '2031-03-05');
        $this->assertSame(1, $this->remind(), 'Still marked as reminded, so unmuting later does not resend it.');
        $this->assertSame([], $this->titles($this->owner));
    }

    public function test_the_lead_time_follows_settings(): void
    {
        Settings::set('alerts.checkout_lead_hours', 4);
        $this->at('2031-03-01 07:30');
        $this->inHouse('venice', '2031-02-27', '2031-03-01');

        $this->assertSame(1, $this->remind());
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('uniqa:checkout-reminders')->expectsOutputToContain('Check-out reminders sent: 0')->assertSuccessful();
    }

    // Rooms & Properties

    public function test_rooms_page_shows_each_suite_with_what_it_is_doing_today(): void
    {
        $today = \App\Support\Local::today();
        $tomorrow = CarbonImmutable::parse($today)->addDay()->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $stay = $this->inHouse('venice', $yesterday, $tomorrow);

        $page = $this->get('/admin/rooms')->assertOk()->original->getData()['page'];
        $this->assertSame('Admin/Rooms', $page['component']);
        $this->assertSame('suites', $page['props']['business']);
        $rooms = collect($page['props']['rooms'])->keyBy('name');
        $this->assertCount(9, $rooms);
        $this->assertSame('occupied', $rooms['VENICE']['state']);
        $this->assertSame($stay->reference, $rooms['VENICE']['stay']['reference']);
        $this->assertSame($tomorrow, $rooms['VENICE']['stay']['check_out']);
        $this->assertSame('vacant', $rooms['PARIS']['state']);
        $this->assertTrue($page['props']['can']['manage']);
    }

    public function test_rooms_page_lists_cameras_when_cameras_is_selected(): void
    {
        $this->post('/admin/category', ['category' => 'cameras']);

        $page = $this->get('/admin/rooms')->assertOk()->original->getData()['page']['props'];
        $this->assertSame('cameras', $page['business']);
        $this->assertCount(10, $page['rooms']);
        $this->assertNotNull($page['create']);
    }

    public function test_status_can_be_changed_from_the_card_and_is_recorded(): void
    {
        $venice = $this->room('venice');

        $this->post("/admin/rooms/{$venice->id}/status", ['operational_status' => 'maintenance'])->assertSessionHas('success');
        $this->assertSame('maintenance', $venice->fresh()->operational_status);
        $this->assertDatabaseHas('room_status_events', ['room_id' => $venice->id, 'from_status' => 'active', 'to_status' => 'maintenance']);

        $this->post("/admin/rooms/{$venice->id}/status", ['operational_status' => 'broken'])->assertSessionHasErrors('operational_status');

        $this->actingAs($this->staff('Booking / Reception'))->post("/admin/rooms/{$venice->id}/status", ['operational_status' => 'active'])->assertForbidden();
        $this->assertSame('maintenance', $venice->fresh()->operational_status);
    }

    public function test_dashboard_board_still_reads_the_same_room_states(): void
    {
        $today = \App\Support\Local::today();
        $this->inHouse('venice', CarbonImmutable::parse($today)->subDay()->toDateString(), CarbonImmutable::parse($today)->addDay()->toDateString());

        $board = collect($this->get('/admin')->assertOk()->original->getData()['page']['props']['board'])->keyBy('name');
        $this->assertSame('occupied', $board['VENICE']['state']);
        $this->assertSame('vacant', $board['PARIS']['state']);
    }
}
