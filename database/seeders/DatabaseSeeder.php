<?php

namespace Database\Seeders;

use App\Models\BookingSource;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\MessageTemplate;
use App\Models\OwnerDecision;
use App\Models\PaymentAccount;
use App\Models\ParkingSpace;
use App\Models\Property;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Permissions;
use App\Support\OwnerDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Approved-structure seed only: no guest, staff, contact or Wi-Fi data from the workbooks.
 * Rooms are "proposed" and unpublished until the owner approves them.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (Permissions::ROLES as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(Permissions::forRole($role));
        }

        $loop = Property::updateOrCreate(['slug' => 'the-loop'], ['name' => 'The Loop', 'location_label' => 'The Loop, Limketkai']);
        $mesa = Property::updateOrCreate(['slug' => 'mesaverte'], ['name' => 'Mesaverte Residences', 'location_label' => 'Mesaverte Residences']);

        $types = [];
        foreach (['Studio', '1 Bedroom', '2 Bedrooms'] as $name) {
            $types[$name] = RoomType::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }

        // Source: UNIQA_ROOM LIST.xlsx > ROOM DETAILS. Rates in pesos.
        $catalog = [
            ['RIO', 'T3, 6H', $mesa, 'Studio', 0, 1, 1, false, 2, 2, 1600, 1800],
            ['VENICE', '1410', $loop, 'Studio', 0, 1, 1, false, 3, 4, 1700, 1900],
            ['BALI', '2520', $loop, 'Studio', 0, 1, 1, true, 4, 4, 2000, 2200],
            ['TOKYO', '1725', $loop, '1 Bedroom', 1, 1, 1, true, 4, 5, 2200, 2400],
            ['ROMA', '1916', $loop, '1 Bedroom', 1, 1, 1, true, 4, 4, 2100, 2300],
            ['MILAN', '0918', $loop, '1 Bedroom', 1, 2, 1, true, 4, 6, 2500, 2700],
            ['BERLIN', 'T3, 14Q', $mesa, '1 Bedroom', 1, 2, 1, true, 4, 6, 2500, 2700],
            ['PARIS', '0524', $loop, '2 Bedrooms', 2, 2, 2, true, 6, 8, 3500, 4000],
            ['NEW YORK', '2224', $loop, '2 Bedrooms', 2, 3, 2, true, 6, 10, 4000, 4500],
        ];

        foreach ($catalog as [$name, $unit, $property, $type, $bed, $ac, $bath, $balcony, $included, $max, $weekday, $weekend]) {
            $room = Room::firstOrCreate(['slug' => Str::slug($name)], [
                'property_id' => $property->id,
                'room_type_id' => $types[$type]->id,
                'name' => $name,
                'unit_code' => $unit,
                'bedrooms' => $bed,
                'ac_count' => $ac,
                'bathrooms' => $bath,
                'has_balcony' => $balcony,
                'included_guests' => $included,
                'max_guests' => $max,
            ]);
            RateRule::firstOrCreate(['room_id' => $room->id, 'effective_from' => '2026-01-01'], [
                'weekday_cents' => $weekday * 100,
                'weekend_cents' => $weekend * 100,
                'source_note' => 'UNIQA_ROOM LIST.xlsx, ROOM DETAILS (proposed, pending owner approval)',
            ]);
        }

        foreach ([
            ['facebook_messenger', 'Facebook Messenger', false], ['instagram', 'Instagram', false], ['airbnb', 'Airbnb', false],
            ['walk_in', 'Walk-in', false], ['phone', 'Phone Call', false], ['referral', 'Direct Referral', false], ['other', 'Other', false],
            ['outside_unclassified', 'Outside Booking / Unclassified', true],
        ] as [$code, $name, $legacy]) {
            BookingSource::firstOrCreate(['code' => $code], ['name' => $name, 'is_legacy_only' => $legacy]);
        }

        foreach (['Water', 'Electricity', 'Wi-Fi', 'Rent', 'Laundry', 'Cleaning', 'Essentials', 'Refills', 'Repairs & Maintenance', 'Renovation', 'Platform Fees', 'Other'] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }

        foreach ([['Cash on hand', 'cash'], ['GCash', 'gcash'], ['Bank account', 'bank']] as [$name, $kind]) {
            PaymentAccount::firstOrCreate(['name' => $name], ['kind' => $kind]);
        }

        // Example SKUs from the RENOVATION sheet. No stock movements: REFILLS tabs are empty.
        foreach (['Tissue', 'Hand soap', 'Shampoo', 'Body wash', 'Dishwashing liquid', 'Room freshener', 'Bathroom freshener', 'Batteries'] as $item) {
            InventoryItem::firstOrCreate(['sku' => Str::upper(Str::slug($item))], ['name' => $item]);
        }

        // Spaces only with proposed rates; vendor names and phone numbers stay in the private workbook.
        // Stall IDs as written in PARKING DETAILS; one Loop stall has no ID in the source and is left for the owner to add.
        foreach ([[$loop, '3RD FLOOR - #15', 35000], [$mesa, 'T1, LG - 43', 30000], [$mesa, 'T3, LG', 30000], [$mesa, 'T2, P25', 30000], [$mesa, 'T1, P32', 30000]] as [$property, $code, $rate]) {
            ParkingSpace::firstOrCreate(['property_id' => $property->id, 'code' => $code], ['rate_cents' => $rate, 'restrictions' => 'From PARKING DETAILS; confirm date and vendor before use']);
        }

        $this->templates();
        $this->decisions();

        // Tests stay pinned to the room list's rates, and would otherwise copy every photo per test.
        if (! app()->environment('testing')) {
            $this->call(SuiteCatalogSeeder::class);
            OwnerDefaults::apply();
        }

        if (! User::exists() && app()->environment('local')) {
            $password = Str::random(16);
            $owner = User::create(['name' => 'Owner', 'email' => 'owner@uniqa-suites.test', 'password' => Hash::make($password)]);
            $owner->assignRole('Owner');
            $this->command?->warn("Owner login: {$owner->email} / {$password}  (change this after first sign-in)");
        }
    }

    private function templates(): void
    {
        $templates = [
            'inquiry_response' => ['Inquiry response', "Hi {{guest}}! Thank you for your interest in UNIQA SUITES. {{room}} for {{check_in}} to {{check_out}} ({{guests}} guests) looks possible, subject to confirmation. May I send your quotation?"],
            'quote' => ['Quotation', OwnerDefaults::QUOTE_TEMPLATE],
            'confirmation' => ['Booking confirmation', "Hi {{guest}}, your booking {{reference}} is confirmed: {{room}}, {{check_in}} to {{check_out}}.\nTotal {{total}}, balance {{balance}}.\nStandard check-in is {{check_in_time}}."],
            'payment_instructions' => ['Payment instructions', "Hi {{guest}}, for booking {{reference}} the amount due is {{balance}}. Please send payment to the account we will share here, then send us your proof of payment. We will confirm once received."],
            'balance_reminder' => ['Balance reminder', "Hi {{guest}}, a reminder that booking {{reference}} ({{check_in}}) has a balance of {{balance}}."],
            'early_check_in' => ['Early check-in notice', "Hi {{guest}}, early check-in is subject to availability, room readiness and staff approval. Standard check-in is {{check_in_time}}."],
            'directions' => ['Directions', "Hi {{guest}}, {{room}} is at {{property}}. Here are the directions: [staff to add approved directions]"],
            'arrival' => ['Arrival instructions', "Hi {{guest}}, welcome! For your arrival at {{room}}, {{property}} on {{check_in}}: [staff to add approved instructions]"],
            'check_out_reminder' => ['Check-out reminder', "Hi {{guest}}, a reminder that check-out for {{room}} is on {{check_out}}. Thank you for staying with us."],
            'thank_you' => ['Post-stay thank-you', "Hi {{guest}}, thank you for staying at UNIQA SUITES. We hope to welcome you again."],
            'incident' => ['Damage or incident notice', "Hi {{guest}}, we need to discuss an item noted after your stay in {{room}} (booking {{reference}}). May we share the details with you here?"],
        ];
        foreach ($templates as $key => [$name, $body]) {
            MessageTemplate::firstOrCreate(['key' => $key], ['name' => $name, 'body' => $body]);
        }
    }

    private function decisions(): void
    {
        $rows = [
            ['weekend_definition', 'Which nights count as weekend rate?', 'The room list has weekday and weekend prices but never defines the days. Quoting stays disabled until this is set.', 'default_needs_approval'],
            ['extra_guest_unit', 'Is the ₱200 extra-guest fee per night or per stay?', 'Sales sheets show 200/PAX without a billing unit. No fee is charged until approved.', 'default_needs_approval'],
            ['standard_check_out', 'Standard check-out time and late fee', 'The source establishes neither. Late check-out charging stays off until set.', 'default_needs_approval'],
            ['early_fee', 'Early check-in ₱200 per started hour before 2:00 PM', 'Default from the project brief. Active, changeable in Settings.', 'default_needs_approval'],
            ['hold_expiry', 'Default hold expiry (24 hours)', 'System default, not from any source document.', 'default_needs_approval'],
            ['laundry_policy', 'Auto-suggest a laundry job at every checkout', 'System default. Quantity and cost are entered per job, never assumed.', 'default_needs_approval'],
            ['payout_handling', 'How Airbnb payouts are received', 'Sheets show Airbnb amounts and a BPI payout context, but no fee or payout schedule.', 'default_needs_approval'],
            ['room_2221_identity', 'What unit is 2221?', 'January to June sheets head 2221 as a STUDIO; it then disappears in July, when 2520 (a studio) first appears. The brief suggests it may be NEW YORK 2224, but that is a 2-bedroom. Its 2026 sales stay unlinked until you confirm whether it is BALI 2520, NEW YORK 2224 or a separate former unit.', 'data_conflict'],
            ['room_2510_2520', 'Is 2510 the same unit as 2520 (BALI)?', 'The parking calendar heading says 2510. The room list, cleaning and laundry use 2520. Kept separate until confirmed.', 'data_conflict'],
            ['room_14q_location', 'Is 14Q at The Loop or Mesaverte?', 'September to December headings say LOOP 14Q. The room list places BERLIN T3 14Q at Mesaverte.', 'data_conflict'],
            ['capacity_history', 'Historical capacities differ from the room list', 'Tokyo 1725 up to 7 vs 5, Venice 1410 up to 5 vs 4, Rio up to 4 vs 2, Bali 2520 5 vs 4. Current list used for new bookings once approved.', 'data_conflict'],
            ['messenger_url', 'Official Messenger, Instagram and contact details', 'The website\'s Book Now buttons open a chat with the official page, facebook.com/uniqasuitescdo, set in the environment file. Instagram, phone and email were not supplied, so none are shown.', 'default_needs_approval'],
            ['room_photos', 'Room photography', 'No room photos were supplied. Public room pages show the name and details without images until uploaded.', 'default_needs_approval'],
            ['address_details', 'Public address, map links and amenities', 'Only the building names are known. Nothing further is published.', 'default_needs_approval'],
            ['cutover_date', 'Cutover date from Excel to this system', 'Must be set by the owner, with Airbnb future bookings reconciled first.', 'default_needs_approval'],
            ['tax_rules', 'Are any taxes or fees charged on stays?', 'None were supplied, so none are charged. If one applies, add it under Settings with its effective date. Confirm the rate and how it is applied with your accountant.', 'default_needs_approval'],
            ['stay_rules', 'Minimum stay and booking window', 'The source sets no limits, so none are enforced. Set them under Settings, or per room under Rooms.', 'default_needs_approval'],
            ['guest_policies', 'Cancellation policy and house rules', 'None were supplied. The public policies page shows them only after you write them under Settings.', 'default_needs_approval'],
            ['ical_future', 'Airbnb iCal import', 'Read-only blocked dates are available once private feed URLs are supplied. No two-way sync is offered.', 'future_optional'],
            ['public_no_prices', 'The public website shows taken dates but no prices', 'Owner decisions, 4 November 2026. Guests browse suites and see which dates are taken, by date only. Book Now opens Facebook Messenger with free dates they chose. Only staff see prices and send the quotation, and a booking exists only when staff enter it.', 'source_fact'],
            ['source_room_catalog', 'Nine rooms from ROOM DETAILS', 'Names, units, capacities and rates come directly from the room list. Approve each room under Rooms before publishing.', 'source_fact'],
        ];
        foreach ($rows as [$key, $title, $detail, $kind]) {
            OwnerDecision::firstOrCreate(['key' => $key], ['title' => $title, 'detail' => $detail, 'kind' => $kind]);
        }
    }
}
