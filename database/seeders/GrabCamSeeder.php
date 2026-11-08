<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\Property;
use App\Models\RentalRateTier;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\SeedImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Grab Cam Rental: every camera and device on the rate card, with its per-day rates.
 * Safe to run again: models, units and tiers are matched, not duplicated, and a unit staff
 * already edited keeps its details.
 *   php artisan db:seed --class=GrabCamSeeder
 */
class GrabCamSeeder extends Seeder
{
    private const RATES_FROM = '2026-01-01';

    // "8 days above, get 1 day free use", from the rate card.
    private const FREE_DAY_FROM = 8;

    // Rate card (pesos per day): 1–2 days, 3–5 days, 6 days or more. Units: one code per physical camera;
    // null means a single unit, coded from the model name (codes are unique within a property).
    private const MODELS = [
        ['Canon G7X Mark III', 'Canon', null, [550, 500, 450], [null], [66, 172, 100, 73]],
        ['Canon G7X Mark II', 'Canon', null, [500, 450, 400], ['N', 'O'], [214, 168, 98, 77]],
        ['Canon EOS M100', 'Canon', null, [450, 400, 350], [null], [356, 160, 104, 85]],
        ['Fujifilm X-T30 II', 'Fujifilm', '15-45mm kit lens', [600, 550, 500], [null], [66, 330, 104, 75]],
        ['Ricoh GR IIIx', 'Ricoh', '40mm, Urban Edition', [650, 600, 550], [null], [216, 338, 102, 67]],
        ['iPhone 17 Pro Max', 'Apple', '256GB', [800, 700, 600], [null], [372, 338, 64, 72]],
        ['DJI Pocket 4', 'DJI', 'Creator Combo', [550, 500, 450], [null], [100, 488, 60, 97]],
        ['DJI Action 6', 'DJI', 'Adventure Combo', [550, 500, 450], [null], [214, 505, 98, 80]],
        ['DJI Nano', 'DJI', null, [400, 350, 300], [null], [364, 500, 68, 82]],
    ];

    // Like the suite flyers: until real photos are uploaded, each camera's picture on the rate card stands in.
    // Crop boxes above are x, y, width, height on database/seeders/camera/grabcam-rate-card.png.
    private const SEED_FOLDER = 'rooms/seed-cameras';

    /** Photos are seeded except while testing; a test turns them on with a fake disk. */
    public ?bool $withPhotos = null;

    public ?string $source = null;

    private const TIERS = [[1, 2], [3, 5], [6, null]];

    private const TEMPLATES = [
        'camera_inquiry_response' => ['Camera: inquiry response', 'Hi {{guest}}! Thanks for messaging Grab Cam Rental. The {{room}} looks available from {{check_in}} to {{check_out}} ({{days}} days), subject to confirmation. Shall we reserve it for you?'],
        'camera_confirmation' => ['Camera: rental confirmed', "Hi {{guest}}, your rental {{reference}} is confirmed: {{room}}.\nPickup {{check_in}} at {{pickup_time}}, return {{check_out}} at {{return_time}}.\nTotal {{total}}, balance {{balance}}.\nPlease bring a valid ID at pickup."],
        'camera_payment_instructions' => ['Camera: payment instructions', 'Hi {{guest}}, for rental {{reference}} the amount due is {{balance}}. Please send payment to the account we will share here, then send us your proof of payment.'],
        'camera_pickup_reminder' => ['Camera: pickup reminder', 'Hi {{guest}}, a reminder that your {{room}} is ready for pickup on {{check_in}} at {{pickup_time}}. Please bring a valid ID.'],
        'camera_return_reminder' => ['Camera: return reminder', 'Hi {{guest}}, a reminder that the {{room}} is due back on {{check_out}} at {{return_time}}. Please include all accessories and batteries.'],
        'camera_thank_you' => ['Camera: thank you', 'Hi {{guest}}, thank you for renting with Grab Cam! The {{room}} is back in good condition. We hope to see you again.'],
        'camera_damage_notice' => ['Camera: damage notice', 'Hi {{guest}}, we need to discuss something we noticed when the {{room}} came back (rental {{reference}}). May we share the details with you here?'],
    ];

    // Earlier wording, replaced only where staff have not edited it: camera downpayments are agreed in
    // Messenger, like suites, so no message asks for a deposit.
    private const RETIRED = [
        'camera_confirmation' => "Hi {{guest}}, your rental {{reference}} is confirmed: {{room}}.\nPickup {{check_in}} at {{pickup_time}}, return {{check_out}} at {{return_time}}.\nTotal {{total}}, balance {{balance}}.\nPlease bring a valid ID and the deposit at pickup.",
        'camera_pickup_reminder' => 'Hi {{guest}}, a reminder that your {{room}} is ready for pickup on {{check_in}} at {{pickup_time}}. Please bring a valid ID and the deposit.',
        'camera_deposit_returned' => 'Hi {{guest}}, thank you for renting with Grab Cam! The camera is back in good condition and your deposit of {{deposit}} and your ID have been returned.',
    ];

    public function run(): void
    {
        $property = Property::updateOrCreate(['slug' => 'grab-cam'], [
            'name' => 'Grab Cam Rental',
            'location_label' => 'Grab Cam Rental',
            'category' => Property::CAMERAS,
        ]);

        $this->source ??= database_path('seeders/camera/grabcam-rate-card.png');
        $photos = $this->withPhotos ?? ! app()->environment('testing');

        foreach (self::MODELS as [$name, $brand, $specs, $rates, $units, $crop]) {
            $type = RoomType::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
            $type->update(['brand' => $brand]);

            foreach (self::TIERS as $i => [$min, $max]) {
                RentalRateTier::firstOrCreate(
                    ['room_type_id' => $type->id, 'min_days' => $min, 'effective_from' => self::RATES_FROM],
                    ['max_days' => $max, 'daily_cents' => $rates[$i] * 100, 'free_day_from' => self::FREE_DAY_FROM],
                );
            }

            foreach ($units as $code) {
                $slug = $code ? Str::slug("{$name} {$code}") : Str::slug($name);
                $room = Room::firstOrCreate(['slug' => $slug], [
                    'property_id' => $property->id,
                    'room_type_id' => $type->id,
                    'name' => $code ? "{$name} ({$code})" : $name,
                    'unit_code' => $code ?? Str::upper(Str::slug($name)),
                    'specs' => $specs,
                    // Suite-only facts, fixed so the shared capacity checks pass: one renter per unit.
                    'bedrooms' => 0,
                    'ac_count' => 0,
                    'bathrooms' => 0,
                    'included_guests' => 1,
                    'max_guests' => 1,
                    'parking_eligible' => false,
                    'housekeeping_status' => 'ready',
                    'catalog_status' => 'approved',
                    'is_published' => true,
                ]);
                if ($photos) {
                    $this->seedPhoto($room, $crop);
                }
            }
        }

        // Copy-ready messages for camera rentals, shown on a camera booking instead of the suite ones.
        foreach (self::TEMPLATES as $key => [$name, $body]) {
            MessageTemplate::firstOrCreate(['key' => $key], ['name' => $name, 'body' => $body]);
        }
        foreach (self::RETIRED as $key => $old) {
            $template = MessageTemplate::where('key', $key)->first();
            if ($template && $template->body === $old) {
                isset(self::TEMPLATES[$key]) ? $template->update(['body' => self::TEMPLATES[$key][1]]) : $template->delete();
            }
        }
    }

    /** The camera's picture cut from the rate card and enlarged 2x. A unit that already has a photo keeps it. */
    private function seedPhoto(Room $room, array $crop): void
    {
        if ($room->images()->exists()) {
            return;
        }
        // Any host: no image library or an unreadable file just leaves the default picture.
        $jpeg = SeedImage::crop($this->source, $crop, 2.0);
        if ($jpeg === null) {
            $this->command?->warn("  {$room->name}: photo skipped (the rate card image could not be read on this server). Upload one under Cameras.");

            return;
        }
        $path = self::SEED_FOLDER."/{$room->slug}.jpg";
        Storage::disk('public')->put($path, $jpeg);
        $room->images()->create(['path' => $path, 'alt_text' => "{$room->name}, from the Grab Cam rate card", 'position' => 0]);
    }
}
