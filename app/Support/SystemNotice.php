<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Internal notice for staff: in the bell, and as an alert on every phone, tablet or computer where
 * the person turned alerts on. Nothing here reaches a guest.
 */
class SystemNotice extends Notification
{
    /** Staff can mute these under Account, on the bell and on their devices alike. A task assigned directly to someone always reaches them. */
    public const CATEGORIES = [
        'inquiries' => 'Website booking requests (suites and cameras)',
        'approvals' => 'Payments, refunds and expenses waiting for my approval',
        'checkouts' => 'Guests checking out and cameras due back soon',
        'holds' => 'Expired holds',
        'arrivals' => 'Upcoming arrivals',
        'housekeeping' => 'Overdue tasks and inspections',
        'incidents' => 'New incidents',
        'calendar' => 'Calendar feed problems and clashes',
        'month_end' => 'Month-end review',
        'stock' => 'Low warehouse stock',
    ];

    /** The first line of an alert on a lock screen. The notice's sentence follows it. */
    private const HEADINGS = [
        'inquiries' => 'New booking request',
        'approvals' => 'Waiting for your approval',
        'checkouts' => 'Checking out soon',
        'holds' => 'Holds expired',
        'arrivals' => 'Coming up',
        'housekeeping' => 'Housekeeping',
        'incidents' => 'Incident reported',
        'maintenance' => 'Urgent repair',
        'calendar' => 'Calendar',
        'month_end' => 'Month-end',
        'stock' => 'Low stock',
    ];

    public function __construct(public string $title, public ?string $url = null, public ?string $category = null, public ?string $heading = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'url' => $this->url];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->heading ?? self::HEADINGS[$this->category] ?? 'UNIQA SUITES',
            'body' => $this->title,
            'url' => $this->url ?: '/admin',
            // Each notice is its own alert; a second one never silently replaces the first.
            'tag' => $this->id,
        ];
    }

    /**
     * Everyone active who holds the permission (or any of several, each person told once) and has
     * not muted the category. $except skips the person who caused it.
     *
     * @param  string|list<string>  $permission
     */
    public static function toPermission(string|array $permission, string $title, ?string $url = null, ?string $category = null, ?int $except = null, ?string $heading = null): void
    {
        User::permission($permission)->where('is_active', true)->get()->unique('id')
            ->reject(fn (User $u) => $u->id === $except)
            ->reject(fn (User $u) => $category && in_array($category, $u->muted_notifications ?? [], true))
            ->each(fn (User $u) => $u->notify(new self($title, $url, $category, $heading)));
    }
}
