<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Alerts on the phones, tablets and computers where staff turned them on (Web Push, VAPID).
 * The same push services serve Android, iPhone and iPad (installed to the Home Screen), Windows, Mac and Linux.
 * A failed alert is logged and never stops the work that caused it: the bell inside the system still has it.
 */
class Push
{
    /** How long a push service keeps trying a phone that is off or offline. */
    private const KEEP_SECONDS = 86400;

    public static function configured(): bool
    {
        return filled(config('uniqa.push.public_key')) && filled(config('uniqa.push.private_key'));
    }

    /** Push services want a way to reach the sender: an https address, or a mailto. */
    public static function subject(): string
    {
        $subject = (string) config('uniqa.push.subject');
        if (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://')) {
            return $subject;
        }
        $email = config('uniqa.contact.email');
        if (filled($email)) {
            return "mailto:{$email}";
        }
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? $url : 'mailto:alerts@uniqasuites.invalid';
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string, badge?: int}  $message
     */
    public function toUser(User $user, array $message): void
    {
        if (! self::configured() || ! $user->is_active) {
            return;
        }
        $devices = $user->pushSubscriptions()->get();
        if ($devices->isNotEmpty()) {
            $this->send($devices, $message);
        }
    }

    /**
     * @param  Collection<int, PushSubscription>  $devices
     */
    public function send(Collection $devices, array $message): void
    {
        try {
            $push = new WebPush(
                ['VAPID' => ['subject' => self::subject(), 'publicKey' => config('uniqa.push.public_key'), 'privateKey' => config('uniqa.push.private_key')]],
                ['TTL' => self::KEEP_SECONDS, 'urgency' => 'high'],
                new Client(['timeout' => 8, 'connect_timeout' => 5]),
                // Without a logger the library raises PHP warnings, which Laravel would turn into errors.
                logger: app('log'),
            );
            $push->setReuseVAPIDHeaders(true);

            $byEndpoint = $devices->keyBy('endpoint');
            $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($devices as $device) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $device->endpoint,
                    'publicKey' => $device->public_key,
                    'authToken' => $device->auth_token,
                    'contentEncoding' => $device->content_encoding,
                ]), $payload);
            }

            foreach ($push->flush() as $report) {
                $device = $byEndpoint[$report->getEndpoint()] ?? null;
                if (! $device) {
                    continue;
                }
                if ($report->isSuccess()) {
                    $device->forceFill(['last_sent_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    // The person turned alerts off on that device, or removed the app.
                    $device->delete();
                } else {
                    Log::warning('Push alert was not delivered.', ['device' => $device->id, 'reason' => $report->getReason()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Push alerts could not be sent.', ['error' => $e->getMessage()]);
        }
    }

    /** Can this server encrypt an alert? On Windows a wrong OPENSSL_CONF breaks it, and nothing else would show it. */
    public static function canEncrypt(): bool
    {
        $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        return $key !== false;
    }
}
