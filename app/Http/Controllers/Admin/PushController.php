<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\Push;
use App\Support\SystemNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turning alerts on and off for the device in hand. A device belongs to whoever signed in on it last:
 * signing out stops its alerts, and the next person's sign-in takes it over.
 */
class PushController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(Push::configured(), 503, 'Alerts are not set up on this server yet.');
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'starts_with:https://'],
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'content_encoding' => 'nullable|in:aes128gcm,aesgcm',
        ]);
        $hash = PushSubscription::hashOf($data['endpoint']);

        PushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'device' => self::device((string) $request->userAgent()),
        ]);
        $request->session()->put('push_endpoint', $hash);

        return response()->json(['devices' => $request->user()->pushSubscriptions()->count()]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:1000']);
        $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->delete();
        $request->session()->forget('push_endpoint');

        return response()->json(['devices' => $request->user()->pushSubscriptions()->count()]);
    }

    /** Goes to every device the person turned on, so they can see it arrive. */
    public function test(Request $request)
    {
        $request->user()->notify(new SystemNotice('Alerts are working on this device.', '/admin/account', heading: 'UNIQA SUITES'));

        return response()->json(['devices' => $request->user()->pushSubscriptions()->count()]);
    }

    /** A plain name for the device list, from the browser's own description. */
    public static function device(string $agent): string
    {
        $os = match (true) {
            Str::contains($agent, 'iPhone') => 'iPhone',
            Str::contains($agent, 'iPad') => 'iPad',
            Str::contains($agent, 'Android') => Str::contains($agent, 'Mobile') ? 'Android phone' : 'Android tablet',
            Str::contains($agent, 'Windows') => 'Windows',
            Str::contains($agent, 'Mac OS') => 'Mac',
            Str::contains($agent, 'CrOS') => 'Chromebook',
            Str::contains($agent, 'Linux') => 'Linux',
            default => 'Device',
        };
        $browser = match (true) {
            Str::contains($agent, 'Edg/') => 'Edge',
            Str::contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            Str::contains($agent, 'Firefox') => 'Firefox',
            Str::contains($agent, ['Chrome', 'CriOS']) => 'Chrome',
            Str::contains($agent, 'Safari') => 'Safari',
            default => null,
        };

        return $browser ? "{$os}, {$browser}" : $os;
    }
}
