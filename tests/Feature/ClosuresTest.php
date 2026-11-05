<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Local;
use App\Support\Permissions;
use Tests\DomainTestCase;

class ClosuresTest extends DomainTestCase
{
    private const REASON = 'Synthetic Christmas party';

    private function day(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function allRooms(): array
    {
        return Room::where('operational_status', '!=', 'retired')->pluck('id')->all();
    }

    private function close(int $first = 10, int $last = 11, ?array $rooms = null)
    {
        return $this->post('/admin/closures', ['reason' => self::REASON, 'first_night' => $this->day($first), 'last_night' => $this->day($last), 'room_ids' => $rooms ?? $this->allRooms()]);
    }

    private function staffWith(array $pages): User
    {
        $user = User::factory()->create();
        Permissions::apply($user, 'Staff', $pages);

        return $user->fresh();
    }

    public function test_the_owner_closes_every_room_and_nobody_can_book_those_nights(): void
    {
        $this->close()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(count($this->allRooms()), RoomBlock::where('kind', RoomBlock::CLOSURE)->count());

        foreach (['venice', 'bali', 'new-york'] as $slug) {
            try {
                $this->confirmed($slug, $this->day(9), $this->day(11));
                $this->fail("{$slug} should be closed");
            } catch (RoomConflict $e) {
                $this->assertStringContainsString('closed', $e->getMessage());
                $this->assertStringContainsString(self::REASON, $e->getMessage());
            }
        }
    }

    public function test_a_hold_and_a_date_change_are_refused_too(): void
    {
        $this->close();

        try {
            $this->bookings->hold($this->inquiry('venice', $this->day(10), $this->day(11)));
            $this->fail('A hold must be refused');
        } catch (RoomConflict) {
            $this->assertTrue(true);
        }

        $booking = $this->confirmed('bali', $this->day(5), $this->day(7));
        $this->expectException(RoomConflict::class);
        $this->bookings->amend($booking, ['check_out' => $this->day(11)]);
    }

    public function test_the_nights_either_side_stay_bookable(): void
    {
        $this->close(10, 11);

        // Leaves the morning of the first closed night, and arrives the morning after the last.
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', $this->day(8), $this->day(10))->status);
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', $this->day(12), $this->day(14))->status);
    }

    public function test_guests_are_never_cancelled_and_nothing_is_closed_if_any_are_booked(): void
    {
        $booking = $this->confirmed('venice', $this->day(10), $this->day(12));

        $this->close()->assertSessionHas('error', fn ($message) => str_contains($message, 'VENICE') && str_contains($message, $booking->reference));

        $this->assertSame(0, RoomBlock::where('kind', RoomBlock::CLOSURE)->count(), 'all or nothing');
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
    }

    public function test_the_booked_room_can_be_left_out_and_the_rest_close(): void
    {
        $booking = $this->confirmed('venice', $this->day(10), $this->day(12));
        $others = array_values(array_diff($this->allRooms(), [$this->room('venice')->id]));

        $this->close(10, 11, $others)->assertSessionMissing('error');

        $this->assertSame(count($others), RoomBlock::where('kind', RoomBlock::CLOSURE)->count());
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
    }

    public function test_an_active_hold_also_stops_a_closure(): void
    {
        $this->bookings->hold($this->inquiry('venice', $this->day(10), $this->day(11)));

        $this->close()->assertSessionHas('error');
        $this->assertSame(0, RoomBlock::where('kind', RoomBlock::CLOSURE)->count());
    }

    public function test_a_plain_inquiry_does_not_stop_a_closure_but_cannot_then_be_held(): void
    {
        $inquiry = $this->inquiry('venice', $this->day(10), $this->day(11));

        $this->close()->assertSessionMissing('error');

        $this->expectException(RoomConflict::class);
        $this->bookings->hold($inquiry);
    }

    public function test_the_website_shows_the_dates_as_taken_without_saying_why(): void
    {
        $room = $this->room('venice');
        $room->update(['catalog_status' => 'approved', 'is_published' => true]);
        $this->close();

        // A guest has their own session, without the owner's "Closed 9 rooms" message in it.
        auth()->logout();
        $this->flushSession();
        $response = $this->get('/suites/'.$room->slug)->assertOk();

        $ranges = $response->original->getData()['page']['props']['room']['unavailable'];
        $this->assertSame([['from' => $this->day(10), 'to' => $this->day(12)]], $ranges);
        $this->assertStringNotContainsString(self::REASON, $response->getContent());
    }

    public function test_reopening_frees_every_room_at_once(): void
    {
        $this->close();
        $closure = collect($this->get('/admin/calendar')->original->getData()['page']['props']['closures'])->sole();
        $this->assertSame(self::REASON, $closure['reason']);
        $this->assertSame($this->day(11), $closure['last_night']);
        $this->assertCount(count($this->allRooms()), $closure['rooms']);

        $this->post("/admin/closures/{$closure['group']}/reopen")->assertSessionMissing('error');

        $this->assertSame(0, RoomBlock::where('kind', RoomBlock::CLOSURE)->whereNull('released_at')->count());
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', $this->day(10), $this->day(11))->status);
        $this->assertCount(0, $this->get('/admin/calendar')->original->getData()['page']['props']['closures']);
    }

    public function test_only_the_owner_side_can_close_or_reopen_and_staff_can_see_why(): void
    {
        $this->close();
        $closure = collect($this->get('/admin/calendar')->original->getData()['page']['props']['closures'])->sole();
        $block = RoomBlock::where('kind', RoomBlock::CLOSURE)->first();

        $reception = $this->staffWith(['bookings']);
        $this->actingAs($reception);

        $this->close(20, 21)->assertForbidden();
        $this->post("/admin/closures/{$closure['group']}/reopen")->assertForbidden();
        $this->post("/admin/blocks/{$block->id}/release")->assertForbidden();
        $this->assertNull($block->fresh()->released_at);

        $props = $this->get('/admin/calendar')->assertOk()->original->getData()['page']['props'];
        $this->assertNull($props['closeRooms']);
        $this->assertSame(self::REASON, collect($props['closures'])->sole()['reason']);
    }

    public function test_ordinary_repair_blocks_can_still_be_released_by_booking_staff(): void
    {
        $block = RoomBlock::create(['room_id' => $this->room('venice')->id, 'start_date' => $this->day(1), 'end_date' => $this->day(2), 'kind' => 'repair', 'reason' => 'Test']);

        $this->actingAs($this->staffWith(['bookings']))->post("/admin/blocks/{$block->id}/release")->assertSessionMissing('error')->assertRedirect();
        $this->assertNotNull($block->fresh()->released_at);
    }

    public function test_closing_needs_sensible_dates(): void
    {
        $this->close(-1, 1)->assertSessionHasErrors('first_night');
        $this->close(5, 4)->assertSessionHasErrors('last_night');
        $this->close(1, 400)->assertSessionHas('error');
        $this->post('/admin/closures', ['reason' => 'X', 'first_night' => $this->day(1), 'last_night' => $this->day(1), 'room_ids' => []])->assertSessionHasErrors('room_ids');
        $this->assertSame(0, RoomBlock::where('kind', RoomBlock::CLOSURE)->count());
    }

    public function test_a_closure_is_kept_in_the_audit_log(): void
    {
        $this->close();

        $this->assertDatabaseHas('activity_logs', ['action' => 'closure.created', 'user_id' => $this->owner->id]);
    }

}
