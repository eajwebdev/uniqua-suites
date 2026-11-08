<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\OwnerDecision;
use App\Models\RateRule;
use App\Models\Room;
use App\Support\SeedImage;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Photos, current rates, capacities and amenities from the suite flyers and HD photo packs in
 * database/seeders/suite. Safe to run again: details and rates only change on rooms still "proposed",
 * photos staff uploaded are kept, and no room is published. Contact details on the flyers are
 * not imported.
 *   php artisan db:seed --class=SuiteCatalogSeeder
 */
class SuiteCatalogSeeder extends Seeder
{
    private const RATES_FROM = '2026-11-04';

    private const SEED_FOLDER = 'rooms/seed';

    private const AMENITIES = ['Wi-Fi', 'Netflix', 'Board games', 'Hair dryer', 'Iron', 'First aid kit', 'Cooking facilities'];

    // From each flyer: floor, description, beds, weekday and weekend pesos, included and max guests, air conditioners.
    private const SUITES = [
        'rio' => ['6', 'Comfort Studio. 20 sqm on the 6th floor.', '1 Queen bed, 1 Double bed', 1700, 1800, 2, 4, null, ['Swimming pool', 'Gym']],
        'venice' => ['14', 'Comfort Plus Studio. 21 sqm on the 14th floor.', '1 Queen bed, 1 Double bed, 1 Sofa bed', 1800, 1900, 3, 4, null, []],
        'bali' => ['25', 'Twin Studio. 23 sqm on the 25th floor.', '1 Queen bed, 1 Double bed, 1 Single bed', 2000, 2200, 4, 5, null, []],
        'tokyo' => ['17', 'Elite Plus 1 Bedroom with a balcony view. 28 sqm on the 17th floor.', '1 Queen bed, 1 Single bed, 2 Double beds', 2200, 2400, 3, 5, null, []],
        'roma' => ['19', 'Elite 1 Bedroom with a balcony. 25 sqm on the 19th floor.', '1 Double bed, 1 Single bed, 1 Sofa bed', 2100, 2300, 3, 4, null, []],
        'milan' => ['9', 'Premium 1 Bedroom with a balcony. 32 sqm on the 9th floor.', '1 Queen bed, 2 Double beds', 2600, 2800, 4, 6, 2, []],
        'berlin' => ['14', 'Executive 1 Bedroom. 28 sqm on the 14th floor.', '1 Queen bed, 1 Double bed, 1 Sofa bed', 2500, 2800, 4, 6, 1, []],
        'paris' => ['5', 'Executive 2 Bedroom with an 80-degree balcony. 55 sqm on the 5th floor.', '2 Queen beds, 2 Double beds', 3800, 4000, 6, 8, 2, []],
        'new-york' => ['22', 'Presidential Suite, 2 Bedroom with an 80-degree balcony. 55 sqm on the 22nd floor.', '2 Queen beds, 2 Double beds', 4200, 4500, 6, 8, 3, []],
    ];

    // No HD pack was supplied for these: the flyer's main photo stands in until one is added. x, y, width, height.
    private const FLYER_PHOTOS = [
        'rio' => ['Rio - price - aminities.png', [48, 176, 504, 232], 'bedroom'],
        'venice' => ['Vence - price - aminities.png', [52, 192, 500, 232], 'studio'],
    ];

    public ?string $source = null;

    public function run(): void
    {
        // Kept out of public/, because the flyers show prices and staff phone numbers.
        $this->source ??= database_path('seeders/suite');
        $flyerOnly = [];

        foreach (Room::whereIn('slug', array_keys(self::SUITES))->orderBy('id')->get() as $room) {
            if ($room->catalog_status === 'proposed') {
                $this->seedDetails($room);
            }

            // Also for approved rooms: an HD pack must still be able to replace a flyer stand-in.
            if ($this->seedPhotos($room)) {
                $flyerOnly[] = $room->name;
            }
        }

        // Stated on every flyer. A choice already made under Settings stands.
        foreach (['pricing.weekend_days' => [5, 6], 'pricing.extra_guest_unit' => 'per_night'] as $key => $value) {
            if (Settings::get($key) === null) {
                Settings::set($key, $value);
            }
        }

        $this->updateDecisions($flyerOnly);
    }

    private function seedDetails(Room $room): void
    {
        [$floor, $description, $beds, $weekday, $weekend, $included, $max, $ac, $extras] = self::SUITES[$room->slug];

        $room->update(['floor' => $floor, 'description' => $description, 'inclusions' => $beds, 'included_guests' => $included, 'max_guests' => $max, 'ac_count' => $ac ?? $room->ac_count]);
        $room->amenities()->sync(collect([...self::AMENITIES, ...$extras])->map(fn ($name) => Amenity::firstOrCreate(['name' => $name])->id));

        // A new rule, never an edit, so the room list's rate stays on record.
        RateRule::firstOrCreate(['room_id' => $room->id, 'effective_from' => self::RATES_FROM], [
            'weekday_cents' => $weekday * 100,
            'weekend_cents' => $weekend * 100,
            'source_note' => 'Suite flyer (proposed, pending owner approval)',
        ]);
    }

    /** Returns true when the room only has a low-resolution flyer photo. */
    private function seedPhotos(Room $room): bool
    {
        if ($room->images()->where('path', 'not like', self::SEED_FOLDER.'/%')->exists()) {
            return false;
        }

        $photos = $this->packPhotos($room);
        $flyerOnly = $photos === [];
        if ($flyerOnly) {
            $photos = $this->flyerPhoto($room);
        }
        if ($photos === []) {
            $this->command?->warn("No photos found for {$room->name} in {$this->source}.");

            return false;
        }

        $disk = Storage::disk('public');
        $folder = self::SEED_FOLDER."/{$room->slug}";
        $disk->deleteDirectory($folder);
        $room->images()->delete();

        foreach (array_values($photos) as $position => $photo) {
            $disk->put("{$folder}/{$photo['file']}", $photo['bytes']);
            $room->images()->create(['path' => "{$folder}/{$photo['file']}", 'alt_text' => "{$room->name}: {$photo['label']}", 'position' => $position]);
        }

        return $flyerOnly;
    }

    /** The website-sized JPGs from a room's HD pack, with its "Main" photo first as on the flyer. */
    private function packPhotos(Room $room): array
    {
        $zip = new ZipArchive;
        if ($zip->open($this->source.'/Uniqa_Suites_'.str_replace(' ', '_', Str::title($room->name)).'_All_4_HD.zip') !== true) {
            return [];
        }

        $photos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('/_(\d+)_(Main_)?(\w+)_HD\.jpg$/i', basename($zip->getNameIndex($i)), $m)) {
                $sort = ($m[2] ? '0' : '1').$m[1];
                $photos[$sort] = ['file' => $m[1].'-'.Str::slug($m[3]).'.jpg', 'label' => Str::lower(str_replace('_', ' ', $m[3])), 'bytes' => $zip->getFromIndex($i)];
            }
        }
        $zip->close();
        ksort($photos);

        return $photos;
    }

    private function flyerPhoto(Room $room): array
    {
        [$file, [$x, $y, $width, $height], $label] = self::FLYER_PHOTOS[$room->slug] ?? [null, [0, 0, 0, 0], null];
        if (! $file) {
            return [];
        }
        // Any host: no image library or an unreadable flyer just leaves the room without this photo.
        $jpeg = SeedImage::crop("{$this->source}/{$file}", [$x, $y, $width, $height], 1.0, 92);
        if ($jpeg === null) {
            $this->command?->warn("  {$room->name}: flyer photo skipped (the flyer image could not be read on this server).");

            return [];
        }

        return [['file' => 'flyer-'.Str::slug($label).'.jpg', 'label' => $label, 'bytes' => $jpeg]];
    }

    /** Keeps the register truthful. These stay pending: reading a flyer is not the owner's sign-off. */
    private function updateDecisions(array $flyerOnly): void
    {
        $photos = 'Photos from the HD packs are loaded. Check them before publishing each room.';
        if ($flyerOnly) {
            $photos = 'Photos from the HD packs are loaded, except '.implode(' and ', $flyerOnly).', which only have one low-resolution photo cropped from the flyer. Add their HD packs to database/seeders/suite and run the suite seeder again.';
        }

        $details = [
            'weekend_definition' => 'The suite flyers say weekdays are Sunday to Thursday and the weekend is Friday and Saturday, so that is now set. Confirm it here, or change it under Settings.',
            'extra_guest_unit' => 'The suite flyers say ₱200 per night, so that is now set. Confirm it here, or change it under Settings.',
            'capacity_history' => 'Current capacities now follow the suite flyers (Rio 2 to 4, Venice 3 to 4, Bali 4 to 5, Tokyo 3 to 5). Sales history still shows Tokyo up to 7 and Venice up to 5.',
            'room_photos' => $photos,
            'address_details' => 'Amenities now come from the icons on the suite flyers. Address and map links were not supplied, so only the building names are published.',
            'source_room_catalog' => 'Names and units come from the room list. Current rates, capacities, floors and amenities come from the suite flyers. Approve each room under Rooms before publishing.',
        ];
        foreach ($details as $key => $detail) {
            OwnerDecision::where('key', $key)->where('status', 'pending')->update(['detail' => $detail]);
        }

        OwnerDecision::firstOrCreate(['key' => 'flyer_differences'], [
            'title' => 'Suite flyers differ from the room list',
            'detail' => 'The flyers were used as the newer source. Rates: Rio 1,700 (was 1,600), Venice 1,800 (was 1,700), Berlin weekend 2,800 (was 2,700), Paris 3,800 (was 3,500), New York 4,200 (was 4,000). Guests: Rio up to 4 (was 2), Bali up to 5 (was 4), Tokyo and Roma include 3 (was 4), New York up to 8 (was 10). Berlin has 1 air conditioner (was 2). Amenity names were read from the flyer icons.',
            'kind' => 'data_conflict',
        ]);
    }
}
