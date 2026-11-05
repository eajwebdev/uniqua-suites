<?php

namespace Database\Seeders;

use App\Domain\Bookings\BookingService;
use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Support\Local;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic demo data for local walkthroughs. Never run against real records:
 * it publishes the catalog and sets placeholder pricing policy.
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'demo', 'testing']), 403, 'Demo data is allowed only in the local, demo, or testing environment.');

        // Use the one database already configured by .env. Seed the real roles and catalog first,
        // so a fresh demo database does not need a separate setup command or another database.
        $this->call(DatabaseSeeder::class);

        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        Settings::set('pricing.weekend_days', [5, 6]);
        Settings::set('pricing.extra_guest_unit', 'per_night');

        $staff = [
            'Owner' => 'owner@uniqa-suites.test',
            'Operations Manager' => 'manager@uniqa-suites.test',
            'Booking / Reception' => 'reception@uniqa-suites.test',
            'Administrator' => 'admin@uniqa-suites.test',
            'Staff' => 'staff@uniqa-suites.test',
        ];
        foreach ($staff as $role => $email) {
            $user = User::updateOrCreate(['email' => $email], ['name' => 'Demo '.$role, 'password' => Hash::make('demo-password')]);
            Permissions::apply($user, $role);
        }

        auth()->login(User::where('email', 'owner@uniqa-suites.test')->firstOrFail());
        try {
            if (Booking::exists()) {
                return;
            }

            $bookings = app(BookingService::class);
            $finance = app(FinanceService::class);
            $today = Local::now();
            $source = fn ($code) => BookingSource::where('code', $code)->value('id');
            $room = fn ($slug) => Room::where('slug', $slug)->value('id');
            $guest = fn ($name) => Guest::firstOrCreate(['full_name' => $name], ['phone' => '0900-000-0000', 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test'])->id;

            $make = fn ($name, $slug, $src, $inOffset, $nights, $adults) => $bookings->createInquiry([
                'guest_id' => $guest($name),
                'room_ids' => [$room($slug)],
                'booking_source_id' => $source($src),
                'check_in' => $today->addDays($inOffset)->toDateString(),
                'check_out' => $today->addDays($inOffset + $nights)->toDateString(),
                'adults' => $adults,
            ]);

            $a = $bookings->confirm($make('Demo Guest Alpha', 'venice', 'facebook_messenger', 0, 2, 2));
            $receipt = $finance->recordReceipt(['booking_id' => $a->id, 'received_at' => $today->toDateTimeString(), 'amount_cents' => 100000, 'method' => 'gcash', 'external_reference' => 'DEMO-GCASH-001']);
            $finance->review($receipt, true);

            $b = $bookings->confirm($make('Demo Guest Bravo', 'tokyo', 'airbnb', -2, 2, 4));
            $bookings->checkIn($b);
            $finance->recordSettlement(['booking_id' => $b->id, 'provider' => 'Airbnb', 'gross_cents' => $b->fresh()->total_cents, 'fees_cents' => 30000, 'payout_reference' => 'DEMO-PAYOUT-001']);

            $bookings->hold($make('Demo Guest Charlie', 'bali', 'instagram', 3, 1, 2));
            $make('Demo Guest Delta', 'paris', 'walk_in', 7, 2, 5);
            $bookings->confirm($make('Demo Guest Echo', 'milan', 'phone', 5, 3, 4));
        } finally {
            auth()->logout();
        }
    }
}
