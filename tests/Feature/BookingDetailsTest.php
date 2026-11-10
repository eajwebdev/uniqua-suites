<?php

namespace Tests\Feature;

use App\Models\Booking;
use Tests\DomainTestCase;

/** Source, reference and notes are edited on the booking page; a source change reprices a confirmed stay. */
class BookingDetailsTest extends DomainTestCase
{
    private function details(Booking $booking, array $data)
    {
        return $this->post("/admin/bookings/{$booking->id}/details", $data + ['booking_source_id' => $booking->booking_source_id]);
    }

    public function test_notes_and_reference_are_saved(): void
    {
        $booking = $this->inquiry();
        $this->details($booking, ['source_reference' => ' HM123 ', 'special_requests' => 'Extra towels', 'internal_notes' => "Called twice.\nPrefers GCash."])->assertSessionHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(['HM123', 'Extra towels', "Called twice.\nPrefers GCash."], [$fresh->source_reference, $fresh->special_requests, $fresh->internal_notes]);
    }

    public function test_changing_a_confirmed_stay_to_airbnb_needs_the_total_and_reprices_it(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $ratesTotal = (int) $booking->total_cents;
        $airbnb = $this->source('airbnb');

        $this->details($booking, ['booking_source_id' => $airbnb])->assertSessionHasErrors('channel_total');
        $this->details($booking, ['booking_source_id' => $airbnb, 'channel_total' => '5000'])->assertSessionHasNoErrors();
        $this->assertSame([$airbnb, 500000], [(int) $booking->fresh()->booking_source_id, (int) $booking->fresh()->total_cents]);

        $this->details($booking, ['booking_source_id' => $this->source('walk_in')])->assertSessionHasNoErrors();
        $fresh = $booking->fresh();
        $this->assertNull($fresh->channel_total_cents);
        $this->assertSame($ratesTotal, (int) $fresh->total_cents, 'Back on the nightly rates.');
    }

    public function test_a_cancelled_booking_keeps_its_source_but_notes_can_change_and_its_rooms_still_show(): void
    {
        $booking = $this->bookings->cancel($this->inquiry('venice'), 'Guest changed plans');

        $this->details($booking, ['booking_source_id' => $this->source('airbnb'), 'channel_total' => '100'])->assertSessionHasErrors('booking_source_id');
        $this->details($booking, ['internal_notes' => 'Refund not needed'])->assertSessionHasNoErrors();
        $this->assertSame('Refund not needed', $booking->fresh()->internal_notes);

        $page = $this->get("/admin/bookings/{$booking->id}")->assertOk()->original->getData()['page']['props'];
        $this->assertSame([], $page['booking']['rooms']);
        $this->assertSame('Venice', ucfirst(strtolower($page['booking']['released_rooms'][0]['name'])));
        $this->assertSame('Guest changed plans', $page['booking']['cancellation_reason']);
    }
}
