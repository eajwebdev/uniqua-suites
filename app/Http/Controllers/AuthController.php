<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\DemoLogin;
use App\Support\Settings;
use App\Support\SystemNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function loginForm()
    {
        return Inertia::render('Auth/Login', ['demoAccounts' => DemoLogin::accounts()]);
    }

    public function demoLogin(Request $request, string $role)
    {
        abort_unless(DemoLogin::enabled(), 404);
        $user = DemoLogin::userFor($role);
        abort_unless($user, 404);

        $request->session()->regenerate();
        Auth::login($user);
        Audit::log('auth.demo_login', null, ['role' => $role]);

        return redirect('/admin');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::validate($credentials + ['is_active' => true])) {
            Audit::log('auth.login_failed', null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }
        $user = Auth::getLastAttempted();
        Auth::getProvider()->rehashPasswordIfRequired($user, $credentials);
        $request->session()->regenerate();

        // A correct password alone does not sign in an account that has a second factor.
        if ($user->hasTwoFactor()) {
            $request->session()->put('mfa', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => time() + TwoFactorController::PENDING_MINUTES * 60,
                'attempts' => 0,
            ]);

            return redirect('/two-factor');
        }

        Auth::login($user, $request->boolean('remember'));
        Audit::log('auth.login');

        return redirect()->intended('/admin');
    }

    public function logout(Request $request)
    {
        Audit::log('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgotForm()
    {
        return Inertia::render('Auth/Forgot');
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the account exists.
        return back()->with('success', 'If that account exists, a reset link has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return Inertia::render('Auth/Reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect('/login')->with('success', 'Password updated. Please sign in.');
    }

    public function account(Request $request)
    {
        return Inertia::render('Admin/Account', [
            'twoFactor' => [
                'enabled' => $request->user()->hasTwoFactor(),
                'confirmed_at' => $request->user()->two_factor_confirmed_at,
                'recovery_remaining' => count($request->user()->two_factor_recovery_codes ?? []),
                'required' => (bool) Settings::get('security.require_two_factor'),
            ],
            'notificationPrefs' => ['categories' => SystemNotice::CATEGORIES, 'muted' => $request->user()->muted_notifications ?? []],
            'notifications' => $request->user()->notifications()->latest()->limit(30)->get()
                ->map(fn ($n) => ['id' => $n->id, 'title' => $n->data['title'] ?? '', 'url' => $n->data['url'] ?? null, 'read' => (bool) $n->read_at, 'at' => $n->created_at]),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(8)]], ['password.different' => 'Choose a new password, not the one you use now.']);
        $request->user()->update(['password' => $data['password'], 'must_change_password' => false]);
        Auth::logoutOtherDevices($data['password']);
        Audit::log('auth.password_changed');

        return back()->with('success', 'Password changed.');
    }

    public function updateNotificationPrefs(Request $request)
    {
        $data = $request->validate(['muted' => 'nullable|array', 'muted.*' => ['string', Rule::in(array_keys(SystemNotice::CATEGORIES))]]);
        $request->user()->forceFill(['muted_notifications' => array_values($data['muted'] ?? [])])->save();

        return back()->with('success', 'Notification choices saved.');
    }

    public function readNotifications(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
