<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function loginForm()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }
        $request->session()->regenerate();
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
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);

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
            'notifications' => $request->user()->notifications()->latest()->limit(30)->get()
                ->map(fn ($n) => ['id' => $n->id, 'title' => $n->data['title'] ?? '', 'url' => $n->data['url'] ?? null, 'read' => (bool) $n->read_at, 'at' => $n->created_at]),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $request->user()->update(['password' => $data['password']]);
        Auth::logoutOtherDevices($data['password']);
        Audit::log('auth.password_changed');

        return back()->with('success', 'Password changed.');
    }

    public function readNotifications(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
