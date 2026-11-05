<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Tests\DomainTestCase;

class SecurityHardeningTest extends DomainTestCase
{
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
