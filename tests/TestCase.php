<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before any trait, so before RefreshDatabase can call migrate:fresh.
     * A cached config (php artisan optimize) makes Laravel ignore .env.testing and
     * would otherwise point these destructive tests at the real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new RuntimeException('Refusing to run tests with a cached config: run "php artisan config:clear" first.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]: its name must end in _test.");
        }

        return $app;
    }

    /**
     * A real sign-in starts a fresh session, but a test swaps users inside one. Without this,
     * auth.session would see the previous user's password hash and sign the new user out.
     */
    public function be(UserContract $user, $guard = null)
    {
        session()->forget('password_hash_web');

        return parent::be($user, $guard);
    }
}
