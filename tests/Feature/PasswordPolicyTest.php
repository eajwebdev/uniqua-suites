<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Tests\DomainTestCase;

class PasswordPolicyTest extends DomainTestCase
{
    private function change(User $user, string $new, string $current = 'old-password-123')
    {
        return $this->actingAs($user)->post('/admin/account/password', ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new]);
    }

    private function user(): User
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $user->assignRole('Staff');

        return $user;
    }

    public function test_weak_passwords_are_refused_with_a_clear_reason(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // many tries on purpose
        $user = $this->user();

        $this->change($user, 'abcdefghij')->assertSessionHasErrors('password'); // no number
        $this->change($user, '1234567890')->assertSessionHasErrors('password'); // no letter
        $this->change($user, 'Ab1')->assertSessionHasErrors('password'); // too short
        $this->change($user, 'Password123')->assertSessionHasErrors(['password' => 'That password is too easy to guess. Choose something less common.']);
        $this->change($user, 'PASSWORD@123')->assertSessionHasErrors('password'); // the seeded one, in any case
        $this->change($user, str_repeat('a1', 37))->assertSessionHasErrors('password'); // 74: past what bcrypt reads
        $this->change($user, 'old-password-123')->assertSessionHasErrors(['password' => 'Choose a new password, not the one you use now.']);

        $this->change($user, 'Mango-river-42')->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('Mango-river-42', $user->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused_and_repeated_tries_are_slowed(): void
    {
        $user = $this->user();

        $this->change($user, 'Mango-river-42', 'not-it')->assertSessionHasErrors('current_password');
        for ($i = 0; $i < 5; $i++) {
            $this->change($user, 'Mango-river-42', 'not-it');
        }
        $this->change($user, 'Mango-river-42')->assertStatus(429);
        $this->assertTrue(password_verify('old-password-123', $user->fresh()->password));
    }

    public function test_a_reset_link_follows_the_same_rule(): void
    {
        auth()->logout();
        $user = $this->user();
        $token = Password::createToken($user);
        $reset = fn (string $p) => $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $p, 'password_confirmation' => $p]);

        $reset('password1')->assertSessionHasErrors('password');
        $reset('Mango-river-42')->assertRedirect('/login');
    }
}
