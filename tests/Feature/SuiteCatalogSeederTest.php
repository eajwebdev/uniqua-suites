<?php

namespace Tests\Feature;

use App\Domain\Pricing\PricingService;
use App\Models\OwnerDecision;
use App\Models\Room;
use App\Support\Settings;
use Database\Seeders\SuiteCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\DomainTestCase;
use ZipArchive;

class SuiteCatalogSeederTest extends DomainTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->folder = storage_path('framework/testing/suite-'.uniqid());
        File::ensureDirectoryExists($this->folder);

        $this->pack('Bali', ['JPG_Web_HD/Uniqa_Bali_01_Studio_View_HD.jpg', 'JPG_Web_HD/Uniqa_Bali_04_Main_Twin_Bedroom_HD.jpg', 'PNG_HD/Uniqa_Bali_01_Studio_View_HD.png']);
        imagepng(imagecreatetruecolor(603, 744), "{$this->folder}/Rio - price - aminities.png");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function pack(string $name, array $entries): void
    {
        $zip = new ZipArchive;
        $zip->open("{$this->folder}/Uniqa_Suites_{$name}_All_4_HD.zip", ZipArchive::CREATE);
        foreach ($entries as $entry) {
            $zip->addFromString($entry, 'photo:'.$entry);
        }
        $zip->close();
    }

    private function seedSuites(): void
    {
        $seeder = new SuiteCatalogSeeder;
        $seeder->source = $this->folder;
        $seeder->run();
    }

    public function test_flyer_rates_start_from_a_new_rule_and_earlier_stays_keep_the_room_list_rate(): void
    {
        $this->seedSuites();

        $venice = $this->room('venice');
        $pricing = app(PricingService::class);
        Settings::set('pricing.weekend_days', [5, 6]);

        $this->assertCount(2, $venice->rateRules);
        $this->assertSame([180000, 190000], array_column($pricing->nightly($venice, '2031-03-06', '2031-03-08'), 'cents'), 'Thursday, then Friday');
        $this->assertSame(170000, $pricing->nightly($venice, '2026-10-05', '2026-10-06')[0]['cents']);
    }

    public function test_capacity_floor_and_amenities_follow_each_flyer(): void
    {
        $this->seedSuites();

        $rio = $this->room('rio');
        $this->assertSame([2, 4, '6'], [$rio->included_guests, $rio->max_guests, $rio->floor]);
        $this->assertSame(1, $this->room('berlin')->ac_count);
        $this->assertSame(1, $this->room('venice')->ac_count, 'A flyer that does not state it leaves it alone.');
        $this->assertContains('Swimming pool', $rio->amenities->pluck('name'));
        $this->assertNotContains('Swimming pool', $this->room('bali')->amenities->pluck('name'));
        $this->assertContains('Netflix', $this->room('bali')->amenities->pluck('name'));
    }

    public function test_photos_come_from_the_pack_with_the_main_one_first(): void
    {
        $this->seedSuites();

        $images = $this->room('bali')->images;
        $this->assertSame(['rooms/seed/bali/04-twin-bedroom.jpg', 'rooms/seed/bali/01-studio-view.jpg'], $images->pluck('path')->all());
        $this->assertSame('BALI: twin bedroom', $images[0]->alt_text);
        Storage::disk('public')->assertExists($images[0]->path);
    }

    public function test_a_room_without_a_pack_uses_its_flyer_photo_until_one_is_added(): void
    {
        $this->seedSuites();

        $rio = $this->room('rio');
        $this->assertSame(['rooms/seed/rio/flyer-bedroom.jpg'], $rio->images->pluck('path')->all());
        [$width] = getimagesizefromstring(Storage::disk('public')->get($rio->images[0]->path));
        $this->assertSame(504, $width);
        $this->assertStringContainsString('RIO', OwnerDecision::where('key', 'room_photos')->value('detail'));

        $this->pack('Rio', ['Uniqa_Rio_04_Main_Bedroom_HD.jpg']);
        $this->seedSuites();

        $this->assertSame(['rooms/seed/rio/04-bedroom.jpg'], $this->room('rio')->images->pluck('path')->all());
        Storage::disk('public')->assertMissing('rooms/seed/rio/flyer-bedroom.jpg');
        $this->assertStringNotContainsString('RIO', OwnerDecision::where('key', 'room_photos')->value('detail'));
    }

    public function test_a_room_with_no_source_files_is_left_without_photos(): void
    {
        $this->seedSuites();

        $this->assertCount(0, $this->room('tokyo')->images);
        $this->assertSame(220000, (int) $this->room('tokyo')->rateRules()->where('effective_from', '2026-11-04')->value('weekday_cents'));
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seedSuites();
        $this->seedSuites();

        $bali = $this->room('bali');
        $this->assertCount(2, $bali->images);
        $this->assertCount(2, $bali->rateRules);
        $this->assertCount(7, $bali->amenities);
        $this->assertSame(1, OwnerDecision::where('key', 'flyer_differences')->count());
    }

    public function test_it_leaves_approved_rooms_and_staff_photos_alone_and_publishes_nothing(): void
    {
        $this->room('venice')->update(['catalog_status' => 'approved', 'max_guests' => 9]);
        $this->actingAs($this->owner)->post('/admin/rooms/'.$this->room('bali')->id.'/images', ['image' => UploadedFile::fake()->image('real.jpg'), 'alt_text' => 'Real photo'])->assertSessionHasNoErrors();

        $this->seedSuites();

        $this->assertSame(9, $this->room('venice')->max_guests);
        $this->assertCount(1, $this->room('venice')->rateRules);
        $this->assertSame(['Real photo'], $this->room('bali')->images->pluck('alt_text')->all());
        $this->assertSame(0, Room::where('is_published', true)->count());
    }

    public function test_an_approved_room_can_still_swap_its_flyer_photo_for_an_hd_pack(): void
    {
        $this->seedSuites();
        $this->room('rio')->update(['catalog_status' => 'approved']);

        $this->pack('Rio', ['Uniqa_Rio_04_Main_Bedroom_HD.jpg']);
        $this->seedSuites();

        $this->assertSame(['rooms/seed/rio/04-bedroom.jpg'], $this->room('rio')->images->pluck('path')->all());
    }

    public function test_the_landing_page_only_claims_amenities_every_published_suite_has(): void
    {
        $this->seedSuites();
        auth()->logout();
        $amenities = fn () => $this->get('/')->original->getData()['page']['props']['amenities'];

        $this->assertSame([], $amenities());

        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        $this->assertContains('Netflix', $amenities());
        $this->assertNotContains('Gym', $amenities());

        Room::where('slug', '!=', 'rio')->update(['is_published' => false]);
        $this->assertContains('Gym', $amenities());
    }

    public function test_it_sets_the_flyers_policies_only_when_they_are_still_undecided(): void
    {
        $this->seedSuites();
        $this->assertSame([6, 7], Settings::get('pricing.weekend_days'), 'A choice already made stands.');
        $this->assertSame('per_night', Settings::get('pricing.extra_guest_unit'));

        Settings::set('pricing.weekend_days', null);
        $this->seedSuites();
        $this->assertSame([5, 6], Settings::get('pricing.weekend_days'));
    }

    public function test_seeded_details_put_no_price_on_public_pages(): void
    {
        $this->seedSuites();
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        auth()->logout();

        foreach (['/', '/suites', '/suites/rio', '/suites/bali'] as $url) {
            $body = $this->get($url)->assertOk()->getContent();

            foreach (['_cents', 'included_guests', '₱', '170000', '180000', 'Andrea', 'Ereca', '892 8150'] as $marker) {
                $this->assertStringNotContainsString($marker, $body, "{$url} must not contain {$marker}");
            }
        }

        $this->assertStringContainsString('Comfort Studio', $this->get('/suites/rio')->getContent());
    }
}
