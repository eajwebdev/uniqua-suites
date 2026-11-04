<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-click sign-in for trying the system, one account per role. Signing in with no password
 * must never reach a live site, so production refuses it even when DEMO=true is left on.
 */
class DemoLogin
{
    public static function enabled(): bool
    {
        return (bool) config('uniqa.demo') && ! app()->environment('production');
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function accounts(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return collect(Permissions::ROLES)->map(fn ($role) => ['key' => Str::slug($role), 'label' => $role])->values()->all();
    }

    /** The Owner button opens the real Owner account. Other roles get an account of their own, whose random password nobody knows. */
    public static function userFor(string $key): ?User
    {
        $role = collect(Permissions::ROLES)->first(fn ($name) => Str::slug($name) === $key);
        if (! $role) {
            return null;
        }
        if ($role === 'Owner') {
            return User::role('Owner')->where('is_active', true)->orderBy('id')->first();
        }

        $user = User::firstOrCreate(
            ['email' => "{$key}@demo.uniqa-suites.test"],
            ['name' => "Demo {$role}", 'password' => Hash::make(Str::random(40))],
        );
        // Only on first use, so access ticked for this account afterwards is kept.
        if ($user->wasRecentlyCreated) {
            Permissions::apply($user, $role);
        }

        return $user->is_active ? $user : null;
    }
}
