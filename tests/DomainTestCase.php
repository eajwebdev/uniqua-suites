<?php

namespace Tests;

use App\Domain\Bookings\BookingService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class DomainTestCase extends TestCase
{
    use RefreshDatabase;

    protected BookingService $bookings;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Settings::set('pricing.weekend_days', [6, 7]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('Owner');
        $this->actingAs($this->owner);
        $this->bookings = app(BookingService::class);
    }

    protected function room(string $slug): Room
    {
        return Room::where('slug', $slug)->firstOrFail();
    }

    protected function source(string $code = 'facebook_messenger'): int
    {
        return BookingSource::where('code', $code)->value('id');
    }

    protected function inquiry(string $slug = 'venice', string $in = '2031-03-03', string $out = '2031-03-05', int $adults = 2, string $source = 'facebook_messenger'): Booking
    {
        return $this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => 'Synthetic Guest '.uniqid()])->id,
            'room_ids' => [$this->room($slug)->id],
            'booking_source_id' => $this->source($source),
            'check_in' => $in,
            'check_out' => $out,
            'adults' => $adults,
        ]);
    }

    protected function confirmed(string $slug = 'venice', string $in = '2031-03-03', string $out = '2031-03-05', string $source = 'facebook_messenger', int $adults = 2): Booking
    {
        return $this->bookings->confirm($this->inquiry($slug, $in, $out, $adults, $source));
    }
}
