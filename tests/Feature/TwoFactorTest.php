<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Tests\DomainTestCase;

class TwoFactorTest extends DomainTestCase
{
    private const PASSWORD = 'synthetic-password-1';

    private string $secret;

    private function enrolled(string $role = 'Owner', array $recoveryCodes = ['aaaaaaaaaa-bbbbbbbbbb']): User
    {
        $this->secret = Totp::generateSecret();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        \App\Support\Permissions::apply($user, $role);
        $user->forceFill([
            'two_factor_secret' => $this->secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $recoveryCodes),
        ])->save();
        auth()->logout();

        return $user;
    }

    private function currentCode(): string
    {
        return Totp::code($this->secret, (int) floor(time() / 30));
    }

    private function signIn(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor');
    }

    public function test_a_correct_password_alone_does_not_sign_in_an_enrolled_user(): void
    {
        $user = $this->enrolled();

        $this->signIn($user);

        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        $user = $this->enrolled();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');

        $this->assertFalse(session()->has('mfa'));
        $this->get('/two-factor')->assertRedirect('/login');
    }

    public function test_the_right_code_signs_in_and_cannot_be_replayed(): void
    {
        $user = $this->enrolled();
        $this->signIn($user);
        $code = $this->currentCode();

        $this->post('/two-factor', ['code' => $code])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->signIn($user);
        $this->post('/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->enrolled('Owner', ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd']);
        $this->signIn($user);

        $this->post('/two-factor', ['recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(1, $user->fresh()->two_factor_recovery_codes);

        auth()->logout();
        $this->signIn($user);
        $this->post('/two-factor', ['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_repeated_wrong_codes_end_the_attempt(): void
    {
        $user = $this->enrolled();
        $this->signIn($user);

        for ($i = 0; $i < 4; $i++) {
            $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/two-factor', ['code' => '000000'])->assertRedirect('/login');

        $this->post('/two-factor', ['code' => $this->currentCode()])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(5, DB::table('activity_logs')->where('action', 'auth.two_factor_failed')->count());
    }

    public function test_a_pending_sign_in_expires(): void
    {
        $user = $this->enrolled();
        $this->signIn($user);
        session()->put('mfa.expires_at', time() - 1);

        $this->post('/two-factor', ['code' => $this->currentCode()])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_user_deactivated_mid_sign_in_cannot_finish(): void
    {
        $user = $this->enrolled();
        $this->signIn($user);
        $user->update(['is_active' => false]);

        $this->post('/two-factor', ['code' => $this->currentCode()])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_enrolment_needs_the_password_and_a_working_code(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $user->assignRole('Finance / Bookkeeper');
        $this->actingAs($user);

        $this->postJson('/admin/account/two-factor/setup', ['current_password' => 'wrong'])->assertUnprocessable();

        $secret = $this->postJson('/admin/account/two-factor/setup', ['current_password' => self::PASSWORD])->assertOk()->json('secret');
        $this->assertFalse($user->fresh()->hasTwoFactor(), 'An unconfirmed secret must not change how sign-in works.');
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'The secret must be encrypted at rest.');

        $this->postJson('/admin/account/two-factor/confirm', ['code' => 'nope'])->assertUnprocessable();
        $codes = $this->postJson('/admin/account/two-factor/confirm', ['code' => Totp::code($secret, (int) floor(time() / 30))])->assertOk()->json('recovery_codes');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertCount(8, $codes);
        $this->assertNotContains($codes[0], $fresh->two_factor_recovery_codes, 'Recovery codes must be stored hashed.');
        $this->assertContains(hash('sha256', $codes[0]), $fresh->two_factor_recovery_codes);
    }

    public function test_account_page_never_carries_the_secret(): void
    {
        $user = $this->enrolled();
        $this->actingAs($user);

        $body = $this->get('/admin/account')->assertOk()->getContent();

        $this->assertStringNotContainsString($this->secret, $body);
        $this->assertStringNotContainsString('two_factor_secret', $body);
    }

    public function test_turning_off_needs_the_password(): void
    {
        $user = $this->enrolled();
        $this->actingAs($user);

        $this->post('/admin/account/two-factor/disable', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->post('/admin/account/two-factor/disable', ['current_password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_requirement_redirects_only_privileged_staff_who_have_not_enrolled(): void
    {
        Settings::set('security.require_two_factor', true);

        $owner = User::factory()->create();
        \App\Support\Permissions::apply($owner, 'Owner');
        $this->actingAs($owner)->get('/admin/calendar')->assertRedirect('/admin/account');
        $this->get('/admin/account')->assertOk();

        $cleaner = User::factory()->create();
        $cleaner->assignRole('Housekeeping / Laundry');
        $this->actingAs($cleaner)->get('/admin/housekeeping')->assertOk();

        $enrolled = $this->enrolled();
        $this->actingAs($enrolled)->get('/admin/calendar')->assertOk();
    }

    public function test_only_team_managers_can_reset_someone_elses_second_factor(): void
    {
        $user = $this->enrolled('Finance / Bookkeeper');
        $manager = User::factory()->create();
        $manager->assignRole('Operations Manager');

        $this->actingAs($manager)->post("/admin/team/{$user->id}/reset-two-factor")->assertForbidden();
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->actingAs($this->owner)->post("/admin/team/{$user->id}/reset-two-factor")->assertRedirect();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'team.two_factor_reset')->where('subject_id', $user->id)->count());
    }
}
