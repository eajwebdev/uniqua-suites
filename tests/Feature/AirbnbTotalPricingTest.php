<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\TaxRule;
use Tests\DomainTestCase;

/** Airbnb sets its own price: an Airbnb booking costs the total typed from Airbnb, whatever the dates. Other sources use the rates. */
class AirbnbTotalPricingTest extends DomainTestCase
{
    private function post_(string $source, array $extra = [])
    {
        return $this->post('/admin/bookings', $extra + [
            'guest_name' => 'Synthetic Airbnb Total',
            'guest_phone' => '09170000009',
            'room_ids' => [$this->room('venice')->id],
            'booking_source_id' => $this->source($source),
            'check_in' => '2031-03-06', // Fri, Sat, Sun nights: weekday and weekend rates would differ
            'check_out' => '2031-03-09',
            'adults' => 2,
            'then' => 'confirm',
        ]);
    }

    public function test_an_airbnb_booking_needs_the_airbnb_total(): void
    {
        $this->post_('airbnb')->assertSessionHasErrors('channel_total');
        $this->post_('airbnb', ['channel_total' => '0'])->assertSessionHasErrors('channel_total');
        $this->assertSame(0, Booking::count());
    }

    public function test_the_airbnb_total_is_what_the_guest_owes_whatever_the_dates(): void
    {
        TaxRule::create(['name' => 'Synthetic tax', 'rate_basis_points' => 1200, 'effective_from' => '2020-01-01']);
        $this->post_('airbnb', ['channel_total' => '10000.01'])->assertSessionHasNoErrors();

        $booking = Booking::sole();
        $this->assertSame(Booking::CONFIRMED, $booking->status);
        $this->assertSame(1000001, (int) $booking->total_cents, 'No tax or extra charges on top of the Airbnb total.');
        $this->assertSame(0, $booking->activeCharges()->where('type', 'tax')->count());

        $nights = $booking->nights()->where('is_current', true)->orderBy('stay_date')->pluck('rate_cents')->map(fn ($c) => (int) $c)->all();
        $this->assertSame([333334, 333334, 333333], $nights, 'Shared across the nights and adding up to the total.');
    }

    public function test_other_sources_ignore_any_typed_total_and_use_the_rates(): void
    {
        $this->post_('walk_in', ['channel_total' => '1'])->assertSessionHasNoErrors();

        $booking = Booking::sole();
        $this->assertNull($booking->channel_total_cents);
        $this->assertGreaterThan(100, (int) $booking->total_cents);
        $this->assertSame(0, $booking->nights()->where('rate_source', 'channel_total')->count());
    }

    public function test_changing_an_airbnb_stay_keeps_the_total_or_takes_the_new_one(): void
    {
        $this->post_('airbnb', ['channel_total' => '9000'])->assertSessionHasNoErrors();
        $booking = Booking::sole();

        $this->post("/admin/bookings/{$booking->id}/amend", ['check_out' => '2031-03-10', 'reason' => 'Extended on Airbnb'])->assertSessionHasNoErrors();
        $this->assertSame(900000, (int) $booking->fresh()->total_cents, 'Longer stay, same Airbnb total until it is corrected.');
        $this->assertSame(4, $booking->nights()->where('is_current', true)->count());

        $this->post("/admin/bookings/{$booking->id}/amend", ['channel_total' => '12000', 'reason' => 'New Airbnb total'])->assertSessionHasNoErrors();
        $this->assertSame(1200000, (int) $booking->fresh()->total_cents);
    }

    public function test_an_unconfirmed_airbnb_booking_estimates_the_airbnb_total(): void
    {
        $this->post_('airbnb', ['channel_total' => '7500', 'then' => ''])->assertSessionHasNoErrors();

        $estimate = $this->bookings->estimate(Booking::sole());
        $this->assertSame('channel', $estimate['source']);
        $this->assertSame(750000, $estimate['total_cents']);
    }
}
