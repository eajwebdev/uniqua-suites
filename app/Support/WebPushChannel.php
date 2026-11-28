<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sends a notice to the person's devices as well as the bell. During a web request the sending
 * waits until the page has been answered, so a guest pressing Book Now or a staff member saving
 * a form never waits on a push service.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush') || ! Push::configured()) {
            return;
        }
        $message = $notification->toWebPush($notifiable);
        // The app icon's number: the bell has just been given this notice too.
        $message['badge'] = $notifiable->unreadNotifications()->count();

        $deliver = fn () => app(Push::class)->toUser($notifiable, $message);
        app()->runningInConsole() ? $deliver() : app()->terminating($deliver);
    }
}
