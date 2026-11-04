<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // PHP adds this outside Laravel's response, and it only tells attackers the version.
        header_remove('X-Powered-By');

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=()');

        // The Vite dev server serves scripts from another port, which this policy would rightly block.
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::POLICY);
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->is('admin', 'admin/*', 'login', 'two-factor', 'forgot-password', 'reset-password/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
