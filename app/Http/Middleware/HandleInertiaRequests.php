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
