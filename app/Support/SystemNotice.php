<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** Internal in-app notification. Nothing here reaches a guest. */
class SystemNotice extends Notification
{
    public function __construct(public string $title, public ?string $url = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'url' => $this->url];
    }

    public static function toPermission(string $permission, string $title, ?string $url = null): void
    {
        User::permission($permission)->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new self($title, $url)));
    }
}
