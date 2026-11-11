<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\OwnerDecision;
use App\Models\User;
use App\Support\OwnerDefaults;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Support\Facades\Schema;
use Tests\DomainTestCase;

class SettledSetupTest extends DomainTestCase
{
    private function booking(array $overrides = [])
    {
        return $this->from('/admin/calendar')->post('/admin/bookings', $overrides + [
            'guest_name' => 'Synthetic Transfer',
            'guest_phone' => '09170000004',
            'room_ids' => [$this->room('venice')->id],
            'booking_source_id' => $this->source('airbnb'),
            'source_reference' => 'HMSYNTH123',
            'channel_total' => '4500',
            'check_in' => '2031-03-03',
            'check_out' => '2031-03-05',
            'adults' => 2,
        ]);
    }

    public function test_every_decision_is_settled_from_the_source_documents(): void
    {
        OwnerDefaults::apply();

        $this->assertSame(0, OwnerDecision::where('status', 'pending')->where('key', '!=', 'flyer_differences')->count());
        $this->assertSame([], array_values(array_diff(OwnerDecision::pluck('key')->all(), array_keys(OwnerDefaults::RESOLUTIONS))), 'every decision has a written answer');
        $this->assertSame('11:00', Settings::get('pricing.standard_check_out'));
        $this->assertSame('per_night', Settings::get('pricing.extra_guest_unit'));
    }

    public function test_settling_never_overrides_what_someone_already_chose(): void
    {
        Settings::set('pricing.standard_check_out', '11:00');
        Settings::set('pricing.weekend_days', [6, 7]);
        Settings::set('finance.require_verification_to_confirm', true);
        OwnerDecision::where('key', 'hold_expiry')->update(['status' => 'rejected', 'resolution' => 'Owner wants something else']);

        OwnerDefaults::apply();
        OwnerDefaults::apply();

        $this->assertSame('11:00', Settings::get('pricing.standard_check_out'));
        $this->assertSame([6, 7], Settings::get('pricing.weekend_days'));
        $this->assertTrue(Settings::get('finance.require_verification_to_confirm'));
        $this->assertSame('rejected', OwnerDecision::where('key', 'hold_expiry')->value('status'));
    }

    public function test_settling_invents_no_guest_terms_or_taxes(): void
    {
        OwnerDefaults::apply();

        $this->assertNull(Settings::get('policy.cancellation'));
        $this->assertNull(Settings::get('policy.house_rules'));
        $this->assertNull(Settings::get('pricing.late_fee_cents_per_hour'));
        $this->assertSame(0, \DB::table('tax_rules')->count());
    }

    public function test_new_york_moves_to_its_real_floor_once_and_a_later_correction_stays(): void
    {
        $room = $this->room('new-york');
        $room->update(['floor' => '5', 'description' => 'Presidential Suite. 55 sqm on the 5th floor.']);

        OwnerDefaults::apply();
        $this->assertSame('22', $room->fresh()->floor);
        $this->assertStringContainsString('22nd floor', $room->fresh()->description);

        $room->update(['floor' => '23', 'description' => 'Corrected by staff.']);
        OwnerDefaults::apply();
        $this->assertSame('23', $room->fresh()->floor);
    }

    public function test_decisions_can_no_longer_be_recorded_and_the_page_is_read_only(): void
    {
        $decision = OwnerDecision::first();

        $this->post("/admin/settings/decisions/{$decision->id}", ['status' => 'rejected'])->assertNotFound();
        $this->assertSame($decision->status, $decision->fresh()->status);
        $this->get('/admin/settings')->assertOk()->assertInertia(fn ($p) => $p->missing('feeds')->missing('integrations'));
    }

    public function test_only_the_administrator_sees_how_the_system_was_set_up(): void
    {
        $count = OwnerDecision::count();
        $this->assertGreaterThan(0, $count);

        $owner = $this->get('/admin/settings')->assertOk()->original->getData()['page']['props'];
        $this->assertNull($owner['decisions'], 'The owner can use Settings but does not get the setup notes.');
        $this->assertArrayHasKey('settings', $owner);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Administrator');
        $this->actingAs($admin);

        $this->assertCount($count, $this->get('/admin/settings')->assertOk()->original->getData()['page']['props']['decisions']);
    }

    public function test_airbnb_sync_and_listing_settings_are_gone(): void
    {
        $room = $this->room('venice');

        $this->post('/admin/settings/feeds', ['room_id' => $room->id, 'provider' => 'airbnb', 'url' => 'https://example.test/a.ics'])->assertNotFound();
        $this->post("/admin/rooms/{$room->id}/listings", ['provider' => 'Airbnb', 'listing_id' => 'X'])->assertNotFound();
        $this->get("/admin/rooms/{$room->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->missing('listings'));
        $this->assertFalse(Schema::hasTable('external_calendar_feeds'));
        $this->assertFalse(Schema::hasTable('room_listings'));
        $this->assertFalse(Schema::hasTable('integration_health'));
    }

    public function test_airbnb_stays_a_booking_source_for_manual_transfer(): void
    {
        $this->get('/admin/bookings/form-options')->assertOk()->assertJsonFragment(['code' => 'airbnb', 'name' => 'Airbnb']);
    }

    public function test_saving_a_new_booking_stays_on_the_page_it_was_opened_from(): void
    {
        $this->booking(['then' => 'confirm'])->assertRedirect('/admin/calendar')->assertSessionHas('success');

        $booking = Booking::sole();
        $this->assertSame(Booking::CONFIRMED, $booking->status);
        $this->assertSame('HMSYNTH123', $booking->source_reference);
        $this->assertSame("/admin/bookings/{$booking->id}", session('flash_action')['href']);
    }

    public function test_airbnb_uses_the_same_confirmation_policy_as_other_sources(): void
    {
        Settings::set('finance.require_verification_to_confirm', true);

        $this->booking(['then' => 'confirm'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'saved as an inquiry') && str_contains($message, 'verified payment'));

        $this->assertSame(Booking::INQUIRY, Booking::sole()->status);
    }

    public function test_a_direct_booking_still_needs_its_payment_and_is_kept_as_an_inquiry(): void
    {
        Settings::set('finance.require_verification_to_confirm', true);

        $this->booking(['then' => 'confirm', 'booking_source_id' => $this->source('facebook_messenger')])
            ->assertRedirect('/admin/calendar')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'saved as an inquiry') && str_contains($message, 'verified payment'));

        $this->assertSame(Booking::INQUIRY, Booking::sole()->status);
    }

    public function test_a_room_conflict_on_confirm_saves_nothing_that_blocks_the_other_guest(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05');

        $this->booking(['then' => 'confirm'])->assertSessionHasErrors('room_ids');

        $this->assertSame(1, Booking::count());
    }

    public function test_someone_who_cannot_confirm_cannot_use_save_and_confirm(): void
    {
        $staff = User::factory()->create();
        Permissions::apply($staff, 'Staff', ['bookings']);

        $this->actingAs($staff->fresh());
        $this->booking(['then' => 'confirm'])->assertForbidden();
        $this->assertSame(0, Booking::count());

        $this->booking(['then' => 'hold'])->assertSessionHas('success');
        $this->assertSame(Booking::TENTATIVE, Booking::sole()->status);
    }

    public function test_the_form_options_need_booking_access_and_old_links_still_work(): void
    {
        $this->get('/admin/bookings/create?room_id=3&check_in=2031-03-03')->assertRedirect('/admin/bookings?new=1&room_id=3&check_in=2031-03-03');

        $cleaner = User::factory()->create();
        Permissions::apply($cleaner, 'Staff', ['housekeeping_own']);
        $this->actingAs($cleaner->fresh());
        $this->get('/admin/bookings/form-options')->assertForbidden();
        $this->get('/admin/bookings/create')->assertForbidden();
    }

    public function test_a_double_clicked_save_makes_one_booking(): void
    {
        $this->booking(['idempotency_key' => 'synthetic-key', 'then' => 'confirm'])->assertSessionHas('success');
        $this->booking(['idempotency_key' => 'synthetic-key', 'then' => 'confirm'])->assertRedirect('/admin/calendar');

        $this->assertSame(1, Booking::count());
        $this->assertSame(Booking::CONFIRMED, Booking::sole()->status);
    }

}
