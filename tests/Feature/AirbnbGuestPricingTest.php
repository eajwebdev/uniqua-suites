<?php

namespace Tests\Feature;

use App\Domain\Pricing\PricingService;
use App\Models\BookingSource;
use App\Support\Settings;
use Tests\DomainTestCase;

/** Airbnb collects its own price for the whole party, so its bookings never get the extra-guest fee. */
class AirbnbGuestPricingTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('pricing.extra_guest_unit', 'per_night');
        Settings::set('pricing.extra_guest_cents', 20000);
    }

    private function extraGuestCents(?string $source, int $guests): int
    {
        $room = $this->room('milan'); // includes 4, allows 6
        $opts = $source ? ['source_id' => BookingSource::where('code', $source)->value('id')] : [];
        $quote = app(PricingService::class)->quote($room, '2031-03-03', '2031-03-05', $guests, $opts);

        return (int) collect($quote['lines'])->where('type', 'extra_guest')->sum('amount_cents');
    }

    public function test_airbnb_pays_the_same_for_any_party_up_to_the_maximum(): void
    {
        $this->assertSame(0, $this->extraGuestCents('airbnb', 6));
        $this->assertSame(80000, $this->extraGuestCents('facebook_messenger', 6), '2 extra guests x 2 nights x 200');
        $this->assertSame(80000, $this->extraGuestCents(null, 6));
        $this->assertSame(0, $this->extraGuestCents('facebook_messenger', 4));
    }

    public function test_a_confirmed_airbnb_booking_has_no_extra_guest_charge_and_still_respects_the_maximum(): void
    {
        $booking = $this->bookings->confirm($this->inquiry('milan', '2031-03-03', '2031-03-05', 6, 'airbnb'));
        $this->assertSame(0, $booking->charges()->where('type', 'extra_guest')->count());

        $this->expectException(\App\Domain\Availability\RoomConflict::class);
        app(PricingService::class)->quote($this->room('milan'), '2031-03-03', '2031-03-05', 7, ['source_id' => BookingSource::where('code', 'airbnb')->value('id')]);
    }
}
