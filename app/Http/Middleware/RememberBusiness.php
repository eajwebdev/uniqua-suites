<?php

namespace App\Http\Middleware;

use App\Support\AdminCategory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A link carrying ?business=suites|cameras (from a notice, say) opens the admin in that business. */
class RememberBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->query('business');
        if (is_string($business) && in_array($business, AdminCategory::OPTIONS, true)) {
            $request->session()->put('admin_category', $business);
        }

        return $next($request);
    }
}
