<?php

namespace Tests\Feature;

use App\Support\Local;
use Database\Seeders\GrabCamSeeder;
use Illuminate\Support\Facades\Vite;
use Tests\DomainTestCase;

class GrabCamPublicTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    private function models(): array
    {
        auth()->logout();

        return collect($this->get('/grabcam')->assertOk()->original->getData()['page']['props']['models'])->keyBy('slug')->all();
    }

    public function test_grabcam_lists_cameras_and_rates_but_no_suites(): void
    {
        $models = $this->models();

        $this->assertCount(9, $models);
        $this->assertArrayNotHasKey('venice', $models);
        $this->assertSame([50000, 45000, 40000], array_column($models['canon-g7x-mark-ii']['rates'], 'daily_cents'));
        $this->assertSame('Canon', $models['canon-g7x-mark-ii']['brand']);
        $this->assertSame(2, $models['canon-g7x-mark-ii']['units']);
    }

    public function test_suite_home_has_no_cameras(): void
    {
        \App\Models\Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        auth()->logout();

        $slugs = collect($this->get('/')->assertOk()->original->getData()['page']['props']['rooms'])->pluck('slug')->all();
        $this->assertContains('venice', $slugs);
        $this->assertNotContains('dji-nano', $slugs);
        $this->get('/suites/dji-nano')->assertNotFound();
        $this->get('/suites/dji-nano/check-dates?check_in=2031-03-03&check_out=2031-03-05')->assertNotFound();
    }

    public function test_model_taken_only_when_all_units_taken(): void
    {
        // Inside the page's 365-day window.
        $day = fn (int $n) => Local::now()->addDays($n)->toDateString();
        $this->confirmed('canon-g7x-mark-ii-n', $day(10), $day(12), adults: 1);
        $this->assertSame([], $this->models()['canon-g7x-mark-ii']['unavailable']);

        $this->actingAs($this->owner);
        $this->confirmed('canon-g7x-mark-ii-o', $day(11), $day(14), adults: 1);
        $this->assertSame([['from' => $day(11), 'to' => $day(12)]], $this->models()['canon-g7x-mark-ii']['unavailable']);
    }

    public function test_grabcam_check_dates_reports_free_unit(): void
    {
        $this->confirmed('dji-nano', '2031-03-03', '2031-03-05', adults: 1);
        auth()->logout();

        $this->getJson('/grabcam/dji-nano/check-dates?check_in=2031-03-04&check_out=2031-03-06')->assertOk()->assertJson(['available' => false]);
        $this->getJson('/grabcam/dji-nano/check-dates?check_in=2031-03-05&check_out=2031-03-06')->assertOk()->assertJson(['available' => true]);
        $this->getJson('/grabcam/venice/check-dates?check_in=2031-03-05&check_out=2031-03-06')->assertNotFound();
    }

    public function test_only_grabcam_can_be_framed(): void
    {
        // A developer's running dev server must not change what this test sees.
        Vite::useHotFile(storage_path('framework/testing/no-such-hot-file'));
        auth()->logout();

        $cam = $this->get('/grabcam');
        $this->assertStringContainsString("frame-ancestors 'self' https:", (string) $cam->headers->get('Content-Security-Policy'));
        $this->assertFalse($cam->headers->has('X-Frame-Options'));

        $home = $this->get('/');
        $this->assertSame('SAMEORIGIN', $home->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self';", (string) $home->headers->get('Content-Security-Policy'));
    }

    public function test_cameras_without_photos_show_the_default_image_and_inquiries_go_to_facebook(): void
    {
        $model = $this->models()['dji-nano'];
        $this->assertSame([['url' => '/brand/grabcam-default.svg', 'alt' => 'DJI Nano: photo coming soon', 'default' => true]], collect($model['images'])->all());
        $this->assertFileExists(public_path('brand/grabcam-default.svg'));

        $links = $this->get('/grabcam')->original->getData()['page']['props']['grabcam'];
        $this->assertSame('https://m.me/61582134053068', $links['messenger_url'], 'Messenger, so the inquiry arrives already written.');
        $this->assertSame('https://www.facebook.com/profile.php?id=61582134053068', $links['facebook_url']);
    }

    public function test_suite_home_filters_list_no_grab_cam_location_or_camera_models(): void
    {
        auth()->logout();
        $props = $this->get('/')->assertOk()->original->getData()['page']['props'];

        $this->assertNotContains('grab-cam', collect($props['properties'])->pluck('slug')->all());
        $this->assertNotContains('dji-nano', collect($props['types'])->pluck('slug')->all());
        $this->assertContains('studio', collect($props['types'])->pluck('slug')->all());
        $this->assertArrayNotHasKey('frame_ancestors', (array) $this->get('/grabcam')->original->getData()['page']['props']['grabcam']);
    }

    public function test_cameras_get_their_rate_card_picture_as_a_photo_like_the_suite_flyers(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $seeder = new \Database\Seeders\GrabCamSeeder;
        $seeder->withPhotos = true;
        $seeder->run();

        $nano = $this->room('dji-nano');
        $this->assertSame(1, $nano->images()->count());
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists('rooms/seed-cameras/dji-nano.jpg');
        $this->assertSame([136, 164], array_slice(getimagesize(\Illuminate\Support\Facades\Storage::disk('public')->path('rooms/seed-cameras/dji-nano.jpg')), 0, 2));
        $this->assertFalse((bool) collect($this->models()['dji-nano']['images'])->first()['default']);

        // Running again never duplicates, and a photo staff uploaded is kept.
        $seeder->run();
        $this->assertSame(1, $nano->images()->count());
    }

    public function test_seeding_finishes_without_photos_when_the_server_cannot_read_the_rate_card(): void
    {
        // As on a shared host whose image library cannot open the file: the seeder must not stop a deploy.
        \Illuminate\Support\Facades\Storage::fake('public');
        file_put_contents($broken = sys_get_temp_dir().'/broken-rate-card-'.uniqid().'.png', 'not an image');
        $seeder = new \Database\Seeders\GrabCamSeeder;
        $seeder->withPhotos = true;
        $seeder->source = $broken;

        $seeder->run();
        unlink($broken);

        $this->assertSame(10, \App\Models\Room::cameras()->count());
        $this->assertSame(0, $this->room('dji-nano')->images()->count());
        $this->assertTrue((bool) collect($this->models()['dji-nano']['images'])->first()['default'], 'The default picture shows instead.');
    }
}
