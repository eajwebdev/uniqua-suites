<?php

use App\Domain\Availability\RoomConflict;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias([
            'perm' => PermissionMiddleware::class,
            'active' => EnsureActiveUser::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule conflicts come back to the form as a readable message, with nothing committed.
        // They are normal operation, so they stay out of the error log.
        $exceptions->dontReport(RoomConflict::class);
        $exceptions->render(function (RoomConflict $e, Request $request) {
            return back()->with('error', $e->getMessage())->withInput();
        });
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            return back()->with('error', 'That record already exists.')->withInput();
        });
    })->create();
