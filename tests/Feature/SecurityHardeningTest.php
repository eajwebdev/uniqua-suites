<?php

namespace Tests\Feature;

use App\Domain\Integrations\IcalSync;
use App\Models\ExternalCalendarFeed;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\PublicUrl;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Tests\DomainTestCase;

class SecurityHardeningTest extends DomainTestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20310303\r\nDTEND;VALUE=DATE:20310305\r\nUID:a\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private function feed(): ExternalCalendarFeed
    {
        return ExternalCalendarFeed::create(['room_id' => $this->room('venice')->id, 'provider' => 'airbnb', 'url' => 'https://calendar.example.test/private.ics']);
    }

    public function test_changing_a_password_ends_that_persons_other_sessions(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $user->assignRole('Finance / Bookkeeper');

        // The other device's session remembers the hash from before the change.
        $this->actingAs($user)->withSession(['password_hash_web' => $user->getAuthPassword()])->get('/admin/account')->assertOk();
        $staleHash = session('password_hash_web');

        $this->post('/admin/account/password', ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])->assertSessionHasNoErrors();
        $this->get('/admin/account')->assertOk();

        $this->withSession(['password_hash_web' => $staleHash])->get('/admin/account')->assertRedirect('/login');
    }

    public function test_the_script_policy_only_relaxes_while_the_vite_dev_server_is_running(): void
    {
        auth()->logout();
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        Vite::useHotFile($hot);

        $response = $this->get('/login')->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        unlink($hot);
    }

    public function test_every_response_carries_security_headers(): void
    {
        // A developer's running dev server must not change what this test sees.
        Vite::useHotFile(storage_path('framework/testing/no-such-hot-file'));

        foreach (['/', '/policies', '/login'] as $url) {
            auth()->logout();
            $response = $this->get($url)->assertOk();
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString("script-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_private_pages_ask_search_engines_to_stay_out_and_public_ones_do_not(): void
    {
        $this->actingAs($this->owner)->get('/admin/account')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        auth()->logout();
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_requests_get_hsts(): void
    {
        auth()->logout();
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_public_url_rejects_private_targets_and_plain_http(): void
    {
        $resolvesTo = fn (array $ips) => PublicUrl::$resolver = fn () => $ips;

        $resolvesTo(['93.184.216.34']);
        $this->assertTrue(PublicUrl::isSafe('https://calendar.example.test/a.ics'));
        $this->assertFalse(PublicUrl::isSafe('http://calendar.example.test/a.ics'));
        $this->assertFalse(PublicUrl::isSafe('file:///etc/passwd'));
        $this->assertFalse(PublicUrl::isSafe('https://'));

        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '::1', 'fc00::1'] as $ip) {
            $resolvesTo([$ip]);
            $this->assertFalse(PublicUrl::isSafe('https://calendar.example.test/a.ics'), "{$ip} must be refused");
        }

        // One private answer among several public ones is enough to refuse.
        $resolvesTo(['93.184.216.34', '10.0.0.5']);
        $this->assertFalse(PublicUrl::isSafe('https://calendar.example.test/a.ics'));

        $resolvesTo([]);
        $this->assertFalse(PublicUrl::isSafe('https://nowhere.example.test/a.ics'));
    }

    public function test_a_feed_that_later_resolves_privately_is_not_fetched(): void
    {
        $feed = $this->feed();
        Http::fake(['*' => Http::response(self::ICS)]);
        PublicUrl::$resolver = fn () => ['169.254.169.254'];

        app(IcalSync::class)->syncAll();

        Http::assertNothingSent();
        $this->assertSame('failed', $feed->fresh()->last_status);
        $this->assertSame(0, RoomBlock::count());
    }

    public function test_a_feed_redirect_is_refused_rather_than_followed(): void
    {
        $feed = $this->feed();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        app(IcalSync::class)->syncAll();

        Http::assertSentCount(1);
        $this->assertSame('failed', $feed->fresh()->last_status);
    }

    public function test_settings_refuse_a_feed_pointing_at_the_local_network(): void
    {
        PublicUrl::$resolver = fn () => ['192.168.1.10'];

        $this->post('/admin/settings/feeds', ['room_id' => $this->room('venice')->id, 'provider' => 'airbnb', 'url' => 'https://router.example.test/x.ics'])->assertSessionHas('error');

        $this->assertSame(0, ExternalCalendarFeed::count());
    }

    public function test_calendar_events_with_impossible_dates_are_ignored(): void
    {
        $events = app(IcalSync::class)->parse("BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20310305\r\nDTEND;VALUE=DATE:20310303\r\nUID:bad\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20310305\r\nDTEND;VALUE=DATE:20310305\r\nUID:zero\r\nEND:VEVENT\r\n");

        $this->assertSame([], $events);
    }

    public function test_failed_sign_ins_are_recorded(): void
    {
        auth()->logout();
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'auth.login_failed')->count());
    }

    public function test_dashboard_warns_only_the_owner_when_the_scheduler_has_stopped(): void
    {
        $warned = fn () => collect($this->get('/admin')->original->getData()['page']['props']['actions'])->contains(fn ($a) => str_contains($a['label'], 'scheduler not running'));

        $this->assertTrue($warned(), 'It has never run.');

        $this->artisan('uniqa:expire-holds')->assertSuccessful();
        $this->assertFalse($warned(), 'It just ran.');

        Settings::set('system.scheduler_last_run', now()->subMinutes(11)->toIso8601String());
        $this->assertTrue($warned(), 'It has stopped.');

        $reception = User::factory()->create();
        $reception->assignRole('Booking / Reception');
        $this->actingAs($reception);
        $this->assertFalse($warned());
    }

    public function test_doctor_passes_a_healthy_local_install_and_fails_unsafe_production_settings(): void
    {
        $this->artisan('uniqa:doctor')->assertSuccessful();

        config(['app.env' => 'production', 'app.debug' => true, 'session.secure' => false, 'mail.default' => 'log', 'app.url' => 'http://example.test']);
        $this->app['env'] = 'production';

        $this->artisan('uniqa:doctor')->assertFailed();
    }

    public function test_robots_file_keeps_crawlers_out_of_staff_pages(): void
    {
        $this->assertStringContainsString('Disallow: /admin', file_get_contents(public_path('robots.txt')));
    }
}
