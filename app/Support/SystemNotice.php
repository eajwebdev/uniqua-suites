<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** Internal in-app notification. Nothing here reaches a guest. */
class SystemNotice extends Notification
{
    /** Staff can mute these under Account. A task assigned directly to someone always reaches them. */
    public const CATEGORIES = [
        'holds' => 'Expired holds',
        'arrivals' => 'Upcoming arrivals',
        'housekeeping' => 'Overdue tasks and inspections',
        'incidents' => 'New incidents',
        'calendar' => 'Calendar feed problems and clashes',
        'month_end' => 'Month-end review',
        'stock' => 'Low warehouse stock',
    ];

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

    public static function toPermission(string $permission, string $title, ?string $url = null, ?string $category = null): void
    {
        User::permission($permission)->where('is_active', true)->get()
            ->reject(fn (User $u) => $category && in_array($category, $u->muted_notifications ?? [], true))
            ->each(fn (User $u) => $u->notify(new self($title, $url)));
    }
}
