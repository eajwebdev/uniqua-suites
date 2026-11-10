<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Quote;
use App\Models\Room;
use Tests\DomainTestCase;

class QuoteGuestAndCategoryTest extends DomainTestCase
{
    private function stay(array $extra = []): array
    {
        return array_merge(['room_id' => $this->room('venice')->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guests' => 2], $extra);
    }

    private function cameras(): Room
    {
        $seeder = new \Database\Seeders\GrabCamSeeder;
        $seeder->withPhotos = false;
        $seeder->run();

        return Room::cameras()->firstOrFail();
    }

    public function test_a_quote_can_be_for_an_existing_guest(): void
    {
        $guest = Guest::create(['full_name' => 'Maria Santos', 'phone' => '09171234567']);

        $this->actingAs($this->owner)->post('/admin/quotes', $this->stay(['guest_id' => $guest->id]))->assertRedirect()->assertSessionHasNoErrors();

        $quote = Quote::sole();
        $this->assertSame([$guest->id, 'Maria Santos'], [(int) $quote->guest_id, $quote->guest_name]);
        $this->assertSame(1, Guest::count());
    }

    public function test_a_quote_needs_a_guest(): void
    {
        $this->actingAs($this->owner)->post('/admin/quotes', $this->stay())
            ->assertSessionHasErrors(['guest_name' => 'Choose an existing guest or enter the name of a new guest.']);
        $this->assertSame(0, Quote::count());
    }

    public function test_a_new_guest_typed_on_the_quote_is_added_to_guests(): void
    {
        $this->actingAs($this->owner)->post('/admin/quotes', $this->stay(['guest_name' => ' Juan Cruz ', 'guest_messenger_handle' => 'juan.cruz']))->assertRedirect()->assertSessionHasNoErrors();

        $guest = Guest::where('full_name', 'Juan Cruz')->sole();
        $this->assertSame('juan.cruz', $guest->messenger_handle);
        $this->assertSame($guest->id, (int) Quote::sole()->guest_id);

        // The same person again (same name and contact) is not added twice.
        $this->post('/admin/quotes', $this->stay(['guest_name' => 'juan cruz', 'guest_messenger_handle' => 'juan.cruz']))->assertSessionHasNoErrors();
        $this->assertSame(1, Guest::where('full_name', 'Juan Cruz')->count());
    }

    public function test_a_booking_made_from_a_quote_is_linked_to_it_and_the_quote_page_carries_the_guest(): void
    {
        $guest = Guest::create(['full_name' => 'Maria Santos', 'messenger_handle' => 'maria.s']);
        $this->actingAs($this->owner)->post('/admin/quotes', $this->stay(['guest_id' => $guest->id, 'guests' => 3, 'source_id' => $this->source()]));
        $quote = Quote::sole();

        $props = $this->get("/admin/quotes/{$quote->id}")->original->getData()['page']['props']['quote'];
        $this->assertSame(['id' => $guest->id, 'full_name' => 'Maria Santos', 'phone' => null, 'email' => null, 'messenger_handle' => 'maria.s'], $props['guest']);

        $this->post('/admin/bookings', [
            'guest_id' => $guest->id, 'room_ids' => [$this->room('venice')->id], 'booking_source_id' => $this->source(),
            'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'adults' => 3, 'children' => 0, 'quote_id' => $quote->id,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($quote->fresh()->booking_id, 'The quote now points at its booking, so it is not booked twice.');
        $this->assertSame($guest->id, (int) $quote->fresh()->booking->guest_id);
    }

    public function test_an_older_quote_with_only_a_typed_name_still_fills_in_the_booking(): void
    {
        $guest = Guest::create(['full_name' => 'Patricia Mae', 'phone' => '09614058217']);
        $quote = Quote::create(['reference' => 'Q-OLD', 'version' => 1, 'room_id' => $this->room('venice')->id, 'guest_name' => 'patricia mae', 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guest_count' => 2, 'total_cents' => 340000]);

        $props = $this->actingAs($this->owner)->get("/admin/quotes/{$quote->id}")->original->getData()['page']['props']['quote'];
        $this->assertSame($guest->id, $props['guest']['id'], 'The typed name matches one guest, so that guest is used.');
        $this->assertSame($this->source('facebook_messenger'), $props['booking_source_id'], 'Quotes are sent on Messenger, so that is the source unless the quote says otherwise.');

        // Two guests with that name: nobody is guessed, the name is typed in instead.
        Guest::create(['full_name' => 'Patricia Mae']);
        $this->assertNull($this->get("/admin/quotes/{$quote->id}")->original->getData()['page']['props']['quote']['guest']);
    }

    public function test_the_form_offers_guests_and_only_suites(): void
    {
        $this->cameras();
        Guest::create(['full_name' => 'Maria Santos']);

        $props = $this->actingAs($this->owner)->get('/admin/quotes/create')->assertOk()->original->getData()['page']['props'];
        $this->assertContains('Maria Santos', array_column($props['guests'], 'full_name'));
        $suiteIds = Room::suites()->pluck('id')->all();
        $this->assertNotEmpty($props['rooms']);
        foreach ($props['rooms'] as $room) {
            $this->assertContains($room['id'], $suiteIds, "{$room['label']} is not a suite.");
        }
    }

    public function test_a_camera_cannot_be_quoted_and_camera_quotes_stay_off_the_list(): void
    {
        $camera = $this->cameras();
        $this->actingAs($this->owner)->post('/admin/quotes', $this->stay(['room_id' => $camera->id]))->assertSessionHasErrors('room_id');
        $this->assertSame(0, Quote::count());

        // An old quote for a camera, from before this rule, does not appear among suite quotes.
        Quote::create(['reference' => 'Q-OLD', 'version' => 1, 'room_id' => $camera->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guest_count' => 1, 'total_cents' => 100]);
        $this->post('/admin/quotes', $this->stay(['guest_name' => 'Maria']));
        $rows = $this->get('/admin/quotes')->original->getData()['page']['props']['rows']['data'];
        $this->assertSame(['Maria'], array_map(fn ($r) => $r['guest']['label'], $rows));
        $this->assertSame('/admin/guests/'.Guest::where('full_name', 'Maria')->value('id'), $rows[0]['guest']['href']);
    }
}
