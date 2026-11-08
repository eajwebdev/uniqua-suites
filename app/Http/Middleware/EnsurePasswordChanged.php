<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** An account still on the starting password from the seeder can only reach Account until it sets its own. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password && ! $request->is('admin/account', 'admin/account/password', 'admin/logout')) {
            return redirect('/admin/account')->with('error', 'You are using the starting password. Please set your own password to continue.');
        }

        return $next($request);
    }
}
