<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;

/** When the owner requires it, staff with sensitive access can only reach Account until they enrol. */
class EnsureTwoFactorEnrolled
{
    private const PRIVILEGED = ['team.manage', 'settings.manage', 'payments.verify', 'refunds.approve', 'periods.lock', 'secrets.reveal', 'secrets.manage', 'advances.manage', 'imports.manage'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactor() && Settings::get('security.require_two_factor') && ! $request->is('admin/account*', 'admin/logout') && $user->hasAnyPermission(self::PRIVILEGED)) {
            return redirect('/admin/account')->with('error', 'Your role requires two-factor sign-in. Set it up here to continue.');
        }

        return $next($request);
    }
}
