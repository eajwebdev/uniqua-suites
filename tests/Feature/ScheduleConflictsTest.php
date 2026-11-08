<?php

namespace Tests\Feature;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\WorkOrder;
use App\Support\Local;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DomainTestCase;

/** "Never a conflict": what guests see, what staff are held to and what the database accepts must all agree. */
class ScheduleConflictsTest extends DomainTestCase
{
    private function day(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function publicRoom(string $slug): array
    {
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        auth()->logout();
        $room = $this->get("/suites/{$slug}")->assertOk()->original->getData()['page']['props']['room'];
        $this->actingAs($this->owner);

        return $room;
    }

    private function check(string $slug, string $in, string $out): array
    {
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        auth()->logout();
        $json = $this->getJson("/suites/{$slug}/check-dates?check_in={$in}&check_out={$out}")->assertOk()->json();
        $this->actingAs($this->owner);

        return $json;
    }

    private function nightsClaimed(Booking $booking): array
    {
        return DB::table('room_night_claims')->where('booking_id', $booking->id)->orderBy('stay_date')->pluck('stay_date')->all();
    }

    // What guests see

    public function test_guests_see_taken_dates_and_nothing_about_who_has_them(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        RoomBlock::create(['room_id' => $this->room('venice')->id, 'start_date' => $this->day(10), 'end_date' => $this->day(12), 'kind' => 'owner_use', 'reason' => 'Private reason']);

        $room = $this->publicRoom('venice');

        $this->assertSame([['from' => $this->day(3), 'to' => $this->day(5)], ['from' => $this->day(10), 'to' => $this->day(12)]], $room['unavailable']);

        auth()->logout();
        $body = $this->get('/suites/venice')->getContent();
        foreach (['Private reason', 'owner_use', 'Synthetic Guest', 'UQ-', 'confirmed', 'facebook_messenger', '_cents'] as $marker) {
            $this->assertStringNotContainsString($marker, $body);
        }
    }

    public function test_touching_stays_merge_into_one_range(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        $this->confirmed('venice', $this->day(5), $this->day(7));

        $this->assertSame([['from' => $this->day(3), 'to' => $this->day(7)]], $this->publicRoom('venice')['unavailable']);
    }

    public function test_holds_count_as_taken_but_inquiries_cancellations_and_lapsed_holds_do_not(): void
    {
        $hold = $this->bookings->hold($this->inquiry('venice', $this->day(3), $this->day(5)));
        $this->inquiry('venice', $this->day(8), $this->day(9));
        $cancelled = $this->confirmed('venice', $this->day(10), $this->day(11));
        $this->bookings->cancel($cancelled, 'Synthetic');
        $lapsed = $this->bookings->hold($this->inquiry('venice', $this->day(12), $this->day(13)));
        $lapsed->update(['hold_expires_at' => now()->subMinute()]);

        $this->assertSame([['from' => $this->day(3), 'to' => $this->day(5)]], $this->publicRoom('venice')['unavailable']);
        $this->assertSame('tentative', $hold->fresh()->status);
    }

    public function test_a_stay_already_under_way_shows_only_from_today(): void
    {
        $this->confirmed('venice', $this->day(-2), $this->day(2));

        $this->assertSame([['from' => $this->day(0), 'to' => $this->day(2)]], $this->publicRoom('venice')['unavailable']);
    }

    public function test_the_date_check_refuses_any_overlap_and_allows_arriving_on_a_check_out_day(): void
    {
        $this->confirmed('venice', $this->day(5), $this->day(8));

        foreach ([[4, 6], [5, 8], [6, 7], [7, 10], [4, 9]] as [$in, $out]) {
            $this->assertFalse($this->check('venice', $this->day($in), $this->day($out))['available'], "{$in} to {$out} overlaps");
        }
        foreach ([[3, 5], [8, 9], [1, 2]] as [$in, $out]) {
            $this->assertTrue($this->check('venice', $this->day($in), $this->day($out))['available'], "{$in} to {$out} is free");
        }
    }

    public function test_the_date_check_never_reveals_a_booking_reference(): void
    {
        $booking = $this->confirmed('venice', $this->day(5), $this->day(8));

        $this->assertStringNotContainsString($booking->reference, json_encode($this->check('venice', $this->day(5), $this->day(6))));
    }

    public function test_the_date_check_refuses_past_dates_closed_rooms_and_stay_limits(): void
    {
        $this->assertFalse($this->check('venice', $this->day(-1), $this->day(1))['available']);

        Settings::set('stay.min_nights', 3);
        $this->assertStringContainsString('minimum stay', $this->check('venice', $this->day(3), $this->day(4))['message']);
        Settings::set('stay.min_nights', null);

        $this->room('venice')->update(['operational_status' => 'maintenance']);
        $this->assertFalse($this->check('venice', $this->day(3), $this->day(4))['available']);
        $this->assertTrue($this->publicRoom('venice')['closed']);
        $this->room('venice')->update(['operational_status' => 'active']);

        WorkOrder::create(['room_id' => $this->room('venice')->id, 'title' => 'Synthetic', 'safety_block' => true, 'status' => 'open', 'severity' => 'critical', 'description' => 'Synthetic']);
        $this->assertTrue($this->publicRoom('venice')['closed']);
    }

    public function test_the_date_check_is_public_only_for_published_rooms_and_rejects_bad_dates(): void
    {
        auth()->logout();
        $this->getJson('/suites/venice/check-dates?check_in=2031-03-03&check_out=2031-03-04')->assertNotFound();

        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        $this->getJson('/suites/venice/check-dates?check_in=2031-03-04&check_out=2031-03-03')->assertStatus(422);
        $this->getJson('/suites/venice/check-dates?check_in=nonsense&check_out=2031-03-03')->assertStatus(422);
    }

    // The database as the last line of defence

    public function test_the_database_itself_refuses_a_second_claim_on_a_night(): void
    {
        $booking = $this->confirmed('venice', $this->day(3), $this->day(4));
        $other = $this->inquiry('bali', $this->day(3), $this->day(4));

        $this->expectException(QueryException::class);
        DB::table('room_night_claims')->insert(['room_id' => $this->room('venice')->id, 'stay_date' => $this->day(3), 'booking_id' => $other->id]);
        $this->assertNotNull($booking);
    }

    public function test_a_write_that_skipped_every_check_is_stopped_by_the_claim_and_rolls_back(): void
    {
        $first = $this->confirmed('venice', $this->day(3), $this->day(5));
        $second = $this->inquiry('venice', $this->day(4), $this->day(6));

        // A future bug: code that writes the status without asking whether the room is free.
        try {
            app(InventoryService::class)->withRoomLocks([$this->room('venice')->id], function () use ($second) {
                $second->update(['status' => Booking::CONFIRMED]);
                app(InventoryService::class)->syncClaims($second->fresh());
            });
            $this->fail('The database should have refused it.');
        } catch (RoomConflict $e) {
            $this->assertStringContainsString('already taken', $e->getMessage());
        }

        $this->assertSame(Booking::INQUIRY, $second->fresh()->status, 'Rolled back.');
        $this->assertCount(2, $this->nightsClaimed($first));
        $this->assertSame([], $this->nightsClaimed($second));
    }

    public function test_claims_follow_a_booking_through_its_whole_life(): void
    {
        $booking = $this->inquiry('venice', $this->day(3), $this->day(5));
        $this->assertSame([], $this->nightsClaimed($booking), 'An inquiry holds nothing.');

        $this->bookings->hold($booking);
        $this->assertSame([$this->day(3), $this->day(4)], $this->nightsClaimed($booking));

        $this->bookings->confirm($booking);
        $this->assertSame([$this->day(3), $this->day(4)], $this->nightsClaimed($booking));

        $this->bookings->amend($booking->fresh(), ['room_ids' => [$this->room('bali')->id], 'check_in' => $this->day(6), 'check_out' => $this->day(7), 'reason' => 'Synthetic']);
        $this->assertSame([$this->day(6)], $this->nightsClaimed($booking));
        $this->assertSame($this->room('bali')->id, DB::table('room_night_claims')->where('booking_id', $booking->id)->value('room_id'));

        $this->bookings->cancel($booking->fresh(), 'Synthetic');
        $this->assertSame([], $this->nightsClaimed($booking));
    }

    public function test_a_lapsed_hold_never_blocks_a_new_booking_before_or_after_the_sweep(): void
    {
        $lapsed = $this->bookings->hold($this->inquiry('venice', $this->day(3), $this->day(5)));
        $lapsed->update(['hold_expires_at' => now()->subMinute()]);

        $next = $this->confirmed('venice', $this->day(3), $this->day(5));
        $this->assertCount(2, $this->nightsClaimed($next));

        $this->bookings->expireHolds();
        $this->assertSame([], $this->nightsClaimed($lapsed));
        $this->assertCount(2, $this->nightsClaimed($next), 'The sweep leaves the new stay alone.');

        $this->expectException(RoomConflict::class);
        $this->bookings->hold($lapsed->fresh());
    }

    public function test_a_checked_out_stay_frees_its_remaining_nights(): void
    {
        $booking = $this->confirmed('venice', $this->day(0), $this->day(4));
        $this->bookings->checkIn($booking);
        $this->bookings->checkOut($booking->fresh());

        $this->assertSame([], $this->nightsClaimed($booking));
        $this->assertTrue($this->check('venice', $this->day(1), $this->day(2))['available']);
    }

    // Everything agreeing, under a long random mix of staff actions

    public function test_a_long_random_mix_of_actions_never_produces_a_conflict(): void
    {
        mt_srand(20261104);
        $slugs = ['venice', 'bali', 'tokyo'];
        $live = [];
        $refused = 0;
        $done = 0;

        for ($i = 0; $i < 250; $i++) {
            $slug = $slugs[mt_rand(0, 2)];
            $in = mt_rand(1, 40);
            $out = $in + mt_rand(1, 5);
            try {
                switch (mt_rand(0, 5)) {
                    case 0:
                    case 1:
                        // What the public check promises must match what staff are then allowed to do.
                        $promised = $this->check($slug, $this->day($in), $this->day($out))['available'];
                        try {
                            $live[] = $this->confirmed($slug, $this->day($in), $this->day($out));
                            $this->assertTrue($promised, "Public said taken, staff got {$slug} {$in}-{$out}.");
                            $done++;
                        } catch (RoomConflict $e) {
                            $this->assertFalse($promised, "Public said free, staff were refused {$slug} {$in}-{$out}.");
                            throw $e;
                        }
                        break;
                    case 2:
                        $live[] = $this->bookings->hold($this->inquiry($slug, $this->day($in), $this->day($out)));
                        $done++;
                        break;
                    case 3:
                        if ($live) {
                            $pick = $live[mt_rand(0, count($live) - 1)]->fresh();
                            if (in_array($pick->status, [Booking::TENTATIVE, Booking::CONFIRMED], true)) {
                                $this->bookings->amend($pick, ['room_ids' => [$this->room($slug)->id], 'check_in' => $this->day($in), 'check_out' => $this->day($out), 'reason' => 'Synthetic']);
                                $done++;
                            }
                        }
                        break;
                    case 4:
                        if ($live) {
                            $pick = $live[mt_rand(0, count($live) - 1)]->fresh();
                            if (in_array($pick->status, [Booking::TENTATIVE, Booking::CONFIRMED], true)) {
                                $this->bookings->cancel($pick, 'Synthetic');
                                $done++;
                            }
                        }
                        break;
                    case 5:
                        $this->post('/admin/rooms/'.$this->room($slug)->id.'/blocks', ['start_date' => $this->day($in), 'end_date' => $this->day($out), 'kind' => 'repair', 'reason' => 'Synthetic']);
                        break;
                }
            } catch (RoomConflict) {
                $refused++;
            }
        }

        $this->assertGreaterThan(30, $done, 'The mix must really have booked things.');
        $this->assertGreaterThan(30, $refused, 'The mix must really have collided.');
        $this->assertCount(0, app(InventoryService::class)->overlaps(Local::today()), 'No two live stays or blocks overlap.');

        // The claims must be exactly the live nights: no more, no fewer.
        $expected = [];
        foreach (Booking::whereIn('status', [Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN])->get() as $booking) {
            if ($booking->status === Booking::TENTATIVE && ! $booking->hold_expires_at?->isFuture()) {
                continue;
            }
            foreach ($booking->activeRooms as $stay) {
                for ($night = $stay->check_in->toImmutable(); $night->lt($stay->check_out); $night = $night->addDay()) {
                    $expected[] = "{$stay->room_id}|{$night->toDateString()}|{$booking->id}";
                }
            }
        }
        $actual = DB::table('room_night_claims')->get()->map(fn ($c) => "{$c->room_id}|{$c->stay_date}|{$c->booking_id}")->all();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    // Choosing rooms when making or changing a booking

    private function newBooking(string $slug, int $in, int $out, array $extra = [])
    {
        return $this->post('/admin/bookings', $extra + ['guest_name' => 'Synthetic New', 'guest_phone' => '09170000005', 'room_ids' => [$this->room($slug)->id], 'booking_source_id' => $this->source(), 'check_in' => $this->day($in), 'check_out' => $this->day($out), 'adults' => 2]);
    }

    public function test_the_room_list_says_which_rooms_are_taken_for_the_chosen_dates(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        $this->bookings->hold($this->inquiry('bali', $this->day(3), $this->day(5)));
        RoomBlock::create(['room_id' => $this->room('tokyo')->id, 'start_date' => $this->day(4), 'end_date' => $this->day(6), 'kind' => 'repair', 'reason' => 'Synthetic']);
        $this->room('roma')->update(['operational_status' => 'maintenance']);

        $taken = $this->getJson("/admin/bookings/availability?check_in={$this->day(4)}&check_out={$this->day(5)}")->assertOk()->json('taken');

        $this->assertEqualsCanonicalizing([$this->room('venice')->id, $this->room('bali')->id, $this->room('tokyo')->id, $this->room('roma')->id], array_map('intval', array_keys($taken)));
        $this->assertSame([], (array) $this->getJson("/admin/bookings/availability?check_in={$this->day(5)}&check_out={$this->day(6)}")->json('taken.'.$this->room('venice')->id), 'A check-out day is free for a new arrival.');
    }

    public function test_the_room_list_ignores_guest_numbers_so_a_group_can_share_rooms(): void
    {
        $this->assertSame([], (array) $this->getJson("/admin/bookings/availability?check_in={$this->day(4)}&check_out={$this->day(5)}")->json('taken'));
    }

    public function test_the_room_list_lets_a_booking_keep_its_own_dates(): void
    {
        $booking = $this->confirmed('venice', $this->day(3), $this->day(5));
        $id = $this->room('venice')->id;

        $this->assertArrayNotHasKey($id, (array) $this->getJson("/admin/bookings/availability?check_in={$this->day(3)}&check_out={$this->day(6)}&ignore_booking={$booking->id}")->json('taken'));
        $this->assertArrayHasKey($id, (array) $this->getJson("/admin/bookings/availability?check_in={$this->day(3)}&check_out={$this->day(6)}")->json('taken'));
    }

    public function test_the_room_list_is_for_booking_staff_only(): void
    {
        $housekeeping = \App\Models\User::factory()->create();
        $housekeeping->assignRole('Housekeeping / Laundry');

        $this->actingAs($housekeeping)->getJson("/admin/bookings/availability?check_in={$this->day(4)}&check_out={$this->day(5)}")->assertForbidden();
    }

    public function test_a_taken_room_cannot_even_be_saved_as_an_inquiry_and_leaves_nothing_behind(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        $bookings = Booking::count();
        $guests = \App\Models\Guest::count();

        $this->newBooking('venice', 4, 6)->assertSessionHasErrors('room_ids');
        $this->newBooking('venice', 4, 6, ['then' => 'hold'])->assertSessionHasErrors('room_ids');

        $this->assertSame($bookings, Booking::count());
        $this->assertSame($guests, \App\Models\Guest::count(), 'No stray guest record.');

        $this->newBooking('venice', 5, 6)->assertSessionHasNoErrors();
        $this->assertSame($bookings + 1, Booking::count(), 'Arriving on the check-out day is fine.');
    }

    public function test_a_double_clicked_save_returns_the_same_booking_instead_of_failing(): void
    {
        $key = ['idempotency_key' => 'synthetic-key', 'then' => 'hold'];

        $this->newBooking('venice', 3, 4, $key)->assertSessionHasNoErrors();
        $count = Booking::count();
        $this->newBooking('venice', 3, 4, $key)->assertSessionHasNoErrors();

        $this->assertSame($count, Booking::count());
    }

    public function test_an_inquiry_cannot_be_moved_onto_taken_dates(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        $inquiry = $this->inquiry('venice', $this->day(8), $this->day(9));

        $this->post("/admin/bookings/{$inquiry->id}/amend", ['reason' => 'Synthetic', 'check_in' => $this->day(4), 'check_out' => $this->day(5)])->assertSessionHas('error');

        $this->assertSame($this->day(8), $inquiry->fresh()->check_in->toDateString());
    }

    // Noticing anything that gets in from outside

    public function test_an_outside_clash_raises_the_dashboard_alert_and_fails_the_doctor(): void
    {
        $this->confirmed('venice', $this->day(3), $this->day(5));
        $alert = fn () => collect($this->get('/admin')->original->getData()['page']['props']['actions'])->contains(fn ($a) => str_contains($a['label'], 'schedule conflict'));
        $this->assertFalse($alert());

        // As an Airbnb calendar can: it does not ask this system first.
        RoomBlock::create(['room_id' => $this->room('venice')->id, 'start_date' => $this->day(4), 'end_date' => $this->day(6), 'kind' => 'external_calendar', 'reason' => 'AIRBNB calendar']);

        $this->assertTrue($alert());
        $this->artisan('uniqa:doctor')->assertFailed();
    }

    public function test_past_overlaps_do_not_raise_the_alert(): void
    {
        $this->confirmed('venice', $this->day(-5), $this->day(-3));
        RoomBlock::create(['room_id' => $this->room('venice')->id, 'start_date' => $this->day(-4), 'end_date' => $this->day(-2), 'kind' => 'repair', 'reason' => 'Synthetic']);

        $this->assertCount(0, app(InventoryService::class)->overlaps(Local::today()));
    }
}
