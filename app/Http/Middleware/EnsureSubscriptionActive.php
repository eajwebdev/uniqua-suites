<?php

namespace App\Http\Middleware;

use App\Support\Subscription;
use Closure;
use Illuminate\Http\Request;

/**
 * Once the grace period after an unpaid subscription passes, the admin side shows the locked screen
 * to everyone but the Administrator. Only signing out stays open. The public website is never behind this.
 */
class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('admin/locked', 'admin/logout') || Subscription::canManage($request->user()) || ! Subscription::isLocked()) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'The system is locked until the subscription is paid.'], 423)
            : redirect('/admin/locked');
    }
}
