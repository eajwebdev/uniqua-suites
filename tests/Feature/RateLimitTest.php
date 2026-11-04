<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\DomainTestCase;

class RateLimitTest extends DomainTestCase
{
    public function test_browsing_the_public_site_does_not_use_up_staff_sign_in_attempts(): void
    {
        auth()->logout();

        for ($i = 0; $i < 20; $i++) {
            $this->get('/suites')->assertOk();
        }

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect();
    }

    public function test_failed_sign_ins_are_limited_per_account(): void
    {
        auth()->logout();
        $user = User::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'wrong'])->assertTooManyRequests();

        // A colleague at the same front desk can still sign in.
        $this->post('/login', ['email' => 'colleague@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    public function test_one_address_cannot_guess_across_many_accounts_without_limit(): void
    {
        auth()->logout();

        for ($i = 0; $i < 30; $i++) {
            $this->post('/login', ['email' => "person{$i}@example.test", 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'person99@example.test', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_public_pages_still_have_their_own_limit(): void
    {
        auth()->logout();

        for ($i = 0; $i < 120; $i++) {
            $this->get('/policies')->assertOk();
        }

        $this->get('/policies')->assertTooManyRequests();
    }
}
