<?php

namespace Tests\Feature;

use App\Models\Quote;
use Tests\DomainTestCase;

class BookingEstimateTest extends DomainTestCase
{
    private function page(int $id): array
    {
        return $this->get("/admin/bookings/{$id}")->assertOk()->original->getData()['page']['props'];
    }

    public function test_an_unpriced_booking_shows_its_charges_as_an_estimate_without_owing_anything_yet(): void
    {
        $booking = $this->inquiry('venice', '2031-03-03', '2031-03-05', 2);
        $props = $this->page($booking->id);

        $this->assertCount(0, $props['charges'], 'Nothing is charged before confirmation.');
        $estimate = $props['estimate'];
        $this->assertSame('rates', $estimate['source']);
        $this->assertGreaterThan(0, $estimate['total_cents']);
        $this->assertSame($estimate['total_cents'], array_sum(array_column($estimate['lines'], 'amount_cents')));
        $this->assertSame(0, (int) $booking->fresh()->total_cents, 'The estimate is not saved, so it never shows as money owed.');

        // Confirming locks the same amount.
        $this->bookings->confirm($booking);
        $props = $this->page($booking->id);
        $this->assertNull($props['estimate']);
        $this->assertSame($estimate['total_cents'], (int) $booking->fresh()->total_cents);
    }

    public function test_a_booking_made_from_a_quote_shows_what_the_guest_was_quoted(): void
    {
        $booking = $this->inquiry('venice', '2031-03-03', '2031-03-05', 2);
        $quote = Quote::create(['reference' => 'Q-TEST', 'version' => 1, 'booking_id' => $booking->id, 'room_id' => $this->room('venice')->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guest_count' => 2, 'total_cents' => 300000]);
        $quote->items()->create(['type' => 'accommodation', 'description' => 'Two nights', 'quantity' => 2, 'unit_cents' => 170000, 'amount_cents' => 340000]);
        $quote->items()->create(['type' => 'discount', 'description' => 'Repeat guest', 'quantity' => 1, 'unit_cents' => -40000, 'amount_cents' => -40000]);

        $estimate = $this->page($booking->id)['estimate'];
        $this->assertSame(['quote', 'Q-TEST v1', 300000], [$estimate['source'], $estimate['quote'], $estimate['total_cents']]);
        $this->assertSame(['Two nights', 'Repeat guest'], array_column($estimate['lines'], 'description'));

        // After the dates change, the old quote no longer describes the stay.
        $quote->update(['check_out' => '2031-03-06']);
        $this->assertSame('rates', $this->page($booking->id)['estimate']['source']);
    }
}
