<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The password step only records a pending user in the session; nobody is signed in until
 * a code is accepted. Secrets and recovery codes go out as JSON so they never sit in Inertia
 * page props or browser history.
 */
class TwoFactorController extends Controller
{
    public const PENDING_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const RECOVERY_CODES = 8;

    public function form(Request $request)
    {
        if (! $this->pendingUser($request)) {
            return redirect('/login')->with('error', 'Please sign in again.');
        }

        return Inertia::render('Auth/TwoFactor');
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => 'nullable|string|max:10', 'recovery_code' => 'nullable|string|max:40']);
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect('/login')->with('error', 'Please sign in again.');
        }

        $usedRecovery = ! empty($data['recovery_code']);
        $ok = $usedRecovery ? $this->consumeRecoveryCode($user, $data['recovery_code']) : $this->consumeTotp($user, $data['code'] ?? '');

        if (! $ok) {
            $attempts = $request->session()->increment('mfa.attempts');
            Audit::log('auth.two_factor_failed', $user, ['attempt' => $attempts]);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $request->session()->forget('mfa');

                return redirect('/login')->with('error', 'Too many incorrect codes. Please sign in again.');
            }
            throw ValidationException::withMessages([$usedRecovery ? 'recovery_code' : 'code' => 'That code is not valid.']);
        }

        $remember = (bool) $request->session()->get('mfa.remember');
        $request->session()->forget('mfa');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        Audit::log('auth.login', $user, ['two_factor' => $usedRecovery ? 'recovery_code' : 'totp']);

        return redirect()->intended('/admin');
    }

    /** Starts enrolment. Replaces any unconfirmed secret; has no effect on sign-in until confirmed. */
    public function setup(Request $request)
    {
        $request->validate(['current_password' => 'required|current_password']);
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['current_password' => 'Two-factor sign-in is already on. Turn it off first to replace it.']);
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret])->save();

        return response()->json(['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, config('app.name'))])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $user = $request->user();
        if ($user->hasTwoFactor() || ! $user->two_factor_secret) {
            throw ValidationException::withMessages(['code' => 'Start setup again.']);
        }

        $step = Totp::verify($user->two_factor_secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        [$codes, $hashes] = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $hashes,
            'remember_token' => Str::random(60), // A "keep me signed in" cookie from before this must not skip the code.
        ])->save();
        Audit::log('auth.two_factor_enabled', $user);

        return response()->json(['recovery_codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $request->validate(['current_password' => 'required|current_password']);
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['current_password' => 'Two-factor sign-in is not on.']);
        }

        [$codes, $hashes] = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        Audit::log('auth.two_factor_recovery_regenerated', $user);

        return response()->json(['recovery_codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function disable(Request $request)
    {
        $request->validate(['current_password' => 'required|current_password']);
        self::clear($request->user());
        Audit::log('auth.two_factor_disabled', $request->user());

        return back()->with('success', 'Two-factor sign-in is off.');
    }

    public static function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('mfa.user_id');
        if (! $id || $request->session()->get('mfa.expires_at', 0) < time()) {
            $request->session()->forget('mfa');

            return null;
        }
        $user = User::find($id);

        return $user && $user->is_active && $user->hasTwoFactor() ? $user : null;
    }

    /** The WHERE guard makes this atomic: two requests with the same code cannot both pass. */
    private function consumeTotp(User $user, string $code): bool
    {
        $step = Totp::verify($user->two_factor_secret, $code, $user->two_factor_last_step);
        if ($step === null) {
            return false;
        }

        return User::whereKey($user->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]) === 1;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', strtolower(trim($code)));

        return DB::transaction(function () use ($user, $hash) {
            $fresh = User::lockForUpdate()->findOrFail($user->id);
            $remaining = $fresh->two_factor_recovery_codes ?? [];
            if (! in_array($hash, $remaining, true)) {
                return false;
            }
            $fresh->forceFill(['two_factor_recovery_codes' => array_values(array_diff($remaining, [$hash]))])->save();

            return true;
        });
    }

    /** Recovery codes carry 80 random bits, so a plain SHA-256 is enough to store them. */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = bin2hex(random_bytes(5)).'-'.bin2hex(random_bytes(5));
        }

        return [$codes, array_map(fn ($c) => hash('sha256', $c), $codes)];
    }
}
