<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\Room;
use App\Models\User;
use App\Support\Local;
use App\Support\Push;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;
use Database\Seeders\GrabCamSeeder;
use Illuminate\Support\Collection;
use Tests\DomainTestCase;

/** Book Now / Inquire alerts for front desk, and alerts reaching staff devices. */
class PushAlertsTest extends DomainTestCase
{
    /** Records what would have gone to the push services. */
    private object $sent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        config(['uniqa.push.public_key' => 'test-public', 'uniqa.push.private_key' => 'test-private']);

        $this->sent = new class extends Push
        {
            public array $messages = [];

            public function send(Collection $devices, array $message): void
            {
                foreach ($devices as $device) {
                    $this->messages[] = ['user' => $device->user_id, 'device' => $device->id] + $message;
                }
            }
        };
        $this->app->instance(Push::class, $this->sent);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function device(User $user, string $name = 'one'): PushSubscription
    {
        $endpoint = "https://push.example.test/{$name}";

        return PushSubscription::create(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint), 'public_key' => 'key', 'auth_token' => 'auth']);
    }

    private function future(int $days): string
    {
        return CarbonImmutable::parse(Local::today())->addDays($days)->toDateString();
    }

    private function titles(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.title')->all();
    }

    public function test_book_now_on_a_suite_alerts_front_desk_once_on_the_bell_and_their_devices(): void
    {
        $desk = $this->staff('Booking / Reception');
        $cleaner = $this->staff('Housekeeping / Laundry');
        $this->device($desk);
        $this->device($cleaner, 'cleaner');
        auth()->logout();

        $click = ['kind' => 'suite', 'slug' => 'venice', 'from' => $this->future(10), 'to' => $this->future(12), 'guests' => 2];
        $this->postJson('/book-click', $click)->assertNoContent();
        $this->postJson('/book-click', $click)->assertNoContent(); // pressed twice: one alert

        $titles = $this->titles($desk);
        $this->assertCount(1, $titles);
        $this->assertStringContainsString('VENICE', $titles[0]);
        $this->assertStringContainsString('2 nights, 2 guests', $titles[0]);
        $this->assertSame([], $this->titles($cleaner), 'Only people who handle bookings hear about it.');

        $alerts = collect($this->sent->messages)->where('user', $desk->id)->values();
        $this->assertCount(1, $alerts);
        $this->assertSame('New booking request', $alerts[0]['title']);
        $this->assertStringStartsWith('/admin/calendar?business=suites', $alerts[0]['url']);
        $this->assertSame(1, $alerts[0]['badge']);
        $this->assertFalse(collect($this->sent->messages)->contains('user', $cleaner->id));
    }

    public function test_inquire_on_a_camera_alerts_front_desk_with_the_times(): void
    {
        $desk = $this->staff('Booking / Reception');
        $this->device($desk);
        auth()->logout();
        $slug = Room::cameras()->with('type')->first()->type->slug;

        $this->postJson('/book-click', ['kind' => 'camera', 'slug' => $slug, 'from' => $this->future(3), 'to' => $this->future(5), 'pickup_time' => '10:00', 'return_time' => '15:30'])->assertNoContent();

        $title = $this->titles($desk)[0] ?? '';
        $this->assertStringContainsString('10:00 AM', $title);
        $this->assertStringContainsString('3:30 PM', $title);
        $this->assertSame('Grab Cam rental request', $this->sent->messages[0]['title'] ?? null);
        $this->assertStringStartsWith('/admin/calendar?business=cameras', $this->sent->messages[0]['url']);
    }

    public function test_book_clicks_that_cannot_be_real_raise_no_alert(): void
    {
        $desk = $this->staff('Booking / Reception');
        Room::where('slug', 'paris')->update(['is_published' => false]);
        auth()->logout();

        $this->postJson('/book-click', ['kind' => 'suite', 'slug' => 'venice', 'from' => '2020-01-01', 'to' => '2020-01-03'])->assertNoContent(); // past
        $this->postJson('/book-click', ['kind' => 'suite', 'slug' => 'paris', 'from' => $this->future(5), 'to' => $this->future(6)])->assertNoContent(); // unpublished
        $this->postJson('/book-click', ['kind' => 'suite', 'slug' => 'no-such-suite', 'from' => $this->future(5), 'to' => $this->future(6)])->assertNoContent();
        $this->postJson('/book-click', ['kind' => 'suite', 'slug' => 'venice', 'from' => $this->future(5), 'to' => $this->future(5)])->assertNoContent(); // no night
        $this->postJson('/book-click', ['kind' => 'boat', 'slug' => 'venice', 'from' => $this->future(5), 'to' => $this->future(6)])->assertUnprocessable();

        $this->assertSame([], $this->titles($desk));
    }

    public function test_muting_website_requests_stops_the_bell_and_the_device(): void
    {
        $desk = $this->staff('Booking / Reception');
        $desk->forceFill(['muted_notifications' => ['inquiries']])->save();
        $this->device($desk);
        auth()->logout();

        $this->postJson('/book-click', ['kind' => 'suite', 'slug' => 'venice', 'from' => $this->future(10), 'to' => $this->future(11)])->assertNoContent();

        $this->assertSame([], $this->titles($desk));
        $this->assertSame([], $this->sent->messages);
    }

    public function test_a_device_signs_up_moves_to_whoever_signs_in_and_stops_on_sign_out(): void
    {
        $payload = ['endpoint' => 'https://push.example.test/tablet', 'keys' => ['p256dh' => 'pk', 'auth' => 'au'], 'content_encoding' => 'aes128gcm'];

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36')
            ->postJson('/admin/push/subscribe', $payload)->assertOk()->assertJson(['devices' => 1]);
        $this->assertSame('Android phone, Chrome', PushSubscription::sole()->device);
        $this->assertSame($this->owner->id, PushSubscription::sole()->user_id);

        $desk = $this->staff('Booking / Reception');
        $this->actingAs($desk)->postJson('/admin/push/subscribe', $payload)->assertOk();
        $this->assertSame($desk->id, PushSubscription::sole()->user_id, 'One row per device, for the person signed in now.');

        $this->post('/admin/logout');
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_turning_off_removes_only_this_device(): void
    {
        $this->device($this->owner, 'phone');
        $this->device($this->owner, 'laptop');

        $this->postJson('/admin/push/unsubscribe', ['endpoint' => 'https://push.example.test/phone'])->assertOk()->assertJson(['devices' => 1]);
        $this->assertSame(['https://push.example.test/laptop'], $this->owner->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_sign_up_needs_a_secure_push_address_and_keys(): void
    {
        $this->postJson('/admin/push/subscribe', ['endpoint' => 'http://push.example.test/x', 'keys' => ['p256dh' => 'pk', 'auth' => 'au']])->assertUnprocessable();
        $this->postJson('/admin/push/subscribe', ['endpoint' => 'https://push.example.test/x'])->assertUnprocessable();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_test_alert_reaches_the_persons_own_devices(): void
    {
        $this->device($this->owner);

        $this->postJson('/admin/push/test')->assertOk();

        $this->assertCount(1, $this->sent->messages);
        $this->assertSame($this->owner->id, $this->sent->messages[0]['user']);
    }

    public function test_without_keys_nothing_is_sent_and_the_bell_still_works(): void
    {
        config(['uniqa.push.public_key' => null]);
        $this->device($this->owner);

        $this->postJson('/admin/push/test')->assertOk();

        $this->assertSame([], $this->sent->messages);
        $this->assertCount(1, $this->titles($this->owner));
        $this->assertNull($this->get('/admin/account')->original->getData()['page']['props']['push']['key']);
    }

    public function test_inactive_staff_get_no_device_alerts(): void
    {
        $this->device($this->owner);
        $this->owner->forceFill(['is_active' => false])->save();

        app(Push::class)->toUser($this->owner->fresh(), ['title' => 't', 'body' => 'b']);

        $this->assertSame([], $this->sent->messages);
    }

    public function test_an_approval_notice_skips_the_person_who_caused_it(): void
    {
        $other = User::factory()->create();
        \App\Support\Permissions::apply($other, 'Owner');

        SystemNotice::toPermission('refunds.approve', 'Refund needs approval.', '/admin/payments', 'approvals', except: $this->owner->id);

        $this->assertSame([], $this->titles($this->owner));
        $this->assertSame(['Refund needs approval.'], $this->titles($other));
    }

    public function test_the_staff_app_is_offered_on_admin_pages_only(): void
    {
        $this->assertStringContainsString('rel="manifest"', $this->get('/admin')->getContent());
        auth()->logout();
        $this->assertStringContainsString('rel="manifest"', $this->get('/login')->getContent());
        $this->assertStringNotContainsString('rel="manifest"', $this->get('/')->getContent());
        $this->assertStringNotContainsString('rel="manifest"', $this->get('/grabcam')->getContent());
    }
}
