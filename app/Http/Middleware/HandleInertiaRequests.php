<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'must_change_password' => (bool) $user->must_change_password,
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'unread' => $user->unreadNotifications()->count(),
                    'latest_notice' => $this->latestNotice($request),
                    'notifications' => $this->recentNotices($request),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'action' => fn () => $request->session()->get('flash_action'),
                // A temporary password, shown once. Sessions are encrypted and flash data lasts one request.
                'credential' => fn () => $request->session()->get('credential'),
            ],
            'contact' => config('uniqa.contact'),
            'category' => fn () => $user ? \App\Support\AdminCategory::current() : null,
            // The banner on admin pages while the subscription is ending, due or locked. Owner and Administrator only.
            'subscription' => fn () => $user && $user->hasAnyRole(\App\Support\Permissions::SUBSCRIPTION_VIEWERS) ? \App\Support\Subscription::banner() : null,
            // The corner badge: on trial, expired or locked. Nothing once paid. Owner and Administrator only.
            'subscriptionBadge' => fn () => $user && $user->hasAnyRole(\App\Support\Permissions::SUBSCRIPTION_VIEWERS) ? \App\Support\Subscription::badge() : null,
        ]);
    }

    private function latestNotice(Request $request): ?array
    {
        $notice = $request->user()->unreadNotifications()->latest()->first();

        return $notice ? ['id' => $notice->id, 'title' => $notice->data['title'] ?? '', 'url' => $notice->data['url'] ?? null] : null;
    }

    private function recentNotices(Request $request): array
    {
        return $request->user()->notifications()->latest()->limit(5)->get()
            ->map(fn ($notice) => [
                'id' => $notice->id,
                'title' => $notice->data['title'] ?? 'Notification',
                'url' => $notice->data['url'] ?? null,
                'unread' => $notice->read_at === null,
            ])->all();
    }
}
