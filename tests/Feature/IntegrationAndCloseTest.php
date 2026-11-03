<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Domain\Integrations\IcalSync;
use App\Models\Booking;
use App\Models\ExternalCalendarFeed;
use App\Models\IntegrationHealth;
use App\Models\RoomBlock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\DomainTestCase;

class IntegrationAndCloseTest extends DomainTestCase
{
    private function ics(string $uid = 'evt-1', string $start = '20310303', string $end = '20310305'): string
    {
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nUID:{$uid}\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function feed(): ExternalCalendarFeed
    {
        return ExternalCalendarFeed::create(['room_id' => $this->room('venice')->id, 'provider' => 'airbnb', 'url' => 'https://calendar.example.test/private.ics']);
    }

    public function test_feed_blocks_dates_idempotently_and_blocks_new_bookings(): void
    {
        $this->feed();
        Http::fake(['*' => Http::response($this->ics())]);

        app(IcalSync::class)->syncAll();
        app(IcalSync::class)->syncAll();

        $this->assertSame(1, RoomBlock::count(), 'a repeat sync must not duplicate the block');
        $this->assertSame('connected', IntegrationHealth::where('provider', 'Airbnb iCal')->value('status'));

        $this->expectException(RoomConflict::class);
        $this->confirmed('venice', '2031-03-04', '2031-03-06');
    }

    public function test_event_removed_upstream_releases_its_block(): void
    {
        $this->feed();
        Http::fakeSequence()->push($this->ics())->push("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

        app(IcalSync::class)->syncAll();
        app(IcalSync::class)->syncAll();

        $this->assertSame(0, RoomBlock::whereNull('released_at')->count());
    }

    public function test_clash_with_a_booking_alerts_staff_and_does_not_touch_the_booking(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->feed();
        Http::fake(['*' => Http::response($this->ics())]);

        app(IcalSync::class)->syncAll();

        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
        $this->assertStringContainsString('Calendar clash', $this->owner->notifications()->first()->data['title']);
    }

    public function test_failed_feed_is_reported_without_storing_the_private_url(): void
    {
        $feed = $this->feed();
        Http::fake(['*' => Http::response('nope', 500)]);

        app(IcalSync::class)->syncAll();

        $this->assertSame('failed', IntegrationHealth::where('provider', 'Airbnb iCal')->value('status'));
        $this->assertSame('failed', $feed->fresh()->last_status);
        $this->assertStringNotContainsString('private.ics', (string) $feed->fresh()->last_error);
        $this->assertStringNotContainsString('private.ics', (string) \DB::table('external_calendar_feeds')->value('url'), 'encrypted at rest');
    }

    public function test_settings_page_never_returns_the_feed_url(): void
    {
        $this->feed();
        $this->assertStringNotContainsString('private.ics', $this->get('/admin/settings')->assertOk()->getContent());
    }

    public function test_scheduler_commands_run_and_expire_holds(): void
    {
        $held = $this->bookings->hold($this->inquiry());
        Booking::whereKey($held->id)->update(['hold_expires_at' => now()->subMinute()]);

        $this->assertSame(0, Artisan::call('uniqa:expire-holds'));
        $this->assertSame(0, Artisan::call('uniqa:daily-alerts'));
        $this->assertSame(0, Artisan::call('uniqa:sync-ical'));
        $this->assertSame(Booking::EXPIRED, $held->fresh()->status);
        $this->assertStringContainsString('hold(s) expired', $this->owner->notifications()->first()->data['title']);
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $guest = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $guest->guest->update(['full_name' => '=HYPERLINK("http://evil.test")']);

        $csv = $this->get('/admin/reports/export/aging/csv?from=2031-03-01&to=2031-03-31')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", str_replace('""', '"', $csv));
    }

    public function test_finance_exports_are_blocked_for_roles_without_finance_reports(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['reports.view', 'reports.export']);
        $this->actingAs($user);

        $this->get('/admin/reports/export/collections/csv')->assertForbidden();
        $this->get('/admin/reports?type=aging')->assertForbidden();
        $this->get('/admin/reports/export/tasks/csv')->assertOk();
    }
}
