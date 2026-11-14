<?php

namespace Tests\Feature;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\RentalPeriod;
use App\Models\Booking;
use App\Models\Guest;
use Database\Seeders\GrabCamSeeder;
use Illuminate\Support\Facades\DB;
use Tests\DomainTestCase;

/** Camera rentals run on exact pickup and return times; suites keep whole nights. */
class CameraTimedRentalTest extends DomainTestCase
{
    private const UNIT = 'canon-g7x-mark-ii-n'; // ₱500/day for 1–2 days, ₱450 for 3–5

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    private function rental(string $in, string $pickup, string $out, string $return, string $unit = self::UNIT): Booking
    {
        return $this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => 'Renter '.uniqid()])->id,
            'room_ids' => [$this->room($unit)->id],
            'booking_source_id' => $this->source(),
            'check_in' => $in, 'pickup_time' => $pickup,
            'check_out' => $out, 'return_time' => $return,
            'adults' => 1,
        ]);
    }

    private function confirmedRental(string $in, string $pickup, string $out, string $return): Booking
    {
        return $this->bookings->confirm($this->rental($in, $pickup, $out, $return))->fresh();
    }

    public function test_billed_days_are_whole_24_hour_blocks(): void
    {
        $days = fn ($a, $b) => RentalPeriod::between($a, $b)->days();
        $this->assertSame(1, $days('2031-03-03 10:00', '2031-03-04 10:00'));
        $this->assertSame(1, $days('2031-03-03 10:00', '2031-03-04 14:00'), '28 hours is still one day');
        $this->assertSame(2, $days('2031-03-03 10:00', '2031-03-05 10:00'));
        $this->assertSame(1, $days('2031-03-03 09:00', '2031-03-03 18:00'), 'a short rental is one day');
        $this->assertSame(240, RentalPeriod::between('2031-03-03 10:00', '2031-03-04 14:00')->extraMinutes());
    }

    public function test_24_hour_rental_costs_one_day_and_stores_exact_times(): void
    {
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');

        $this->assertSame(50000, (int) $b->total_cents);
        $this->assertSame('2031-03-03 10:00:00', substr((string) $b->pickup_at, 0, 19));
        $this->assertSame('2031-03-04 10:00:00', substr((string) $b->return_at, 0, 19));
        $this->assertSame(0, DB::table('room_night_claims')->where('booking_id', $b->id)->count(), 'cameras are guarded by times, not night claims');
    }

    public function test_extra_hours_are_not_charged_automatically(): void
    {
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '14:00'); // 28 h

        $this->assertSame(50000, (int) $b->total_cents);
    }

    public function test_48_hours_is_two_days(): void
    {
        $this->assertSame(100000, (int) $this->confirmedRental('2031-03-03', '10:00', '2031-03-05', '10:00')->total_cents);
    }

    public function test_next_pickup_at_the_exact_return_time_is_allowed(): void
    {
        $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');

        $next = $this->confirmedRental('2031-03-04', '10:00', '2031-03-05', '10:00');

        $this->assertSame(Booking::CONFIRMED, $next->status);
        $this->assertCount(0, app(InventoryService::class)->overlaps('2031-01-01'));
    }

    public function test_pickup_one_hour_before_the_return_is_blocked(): void
    {
        $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');

        $this->expectException(RoomConflict::class);
        $this->confirmedRental('2031-03-04', '09:00', '2031-03-05', '09:00');
    }

    public function test_overlap_by_one_minute_is_blocked_and_a_hold_also_blocks(): void
    {
        $held = $this->bookings->hold($this->rental('2031-03-03', '10:00', '2031-03-04', '10:00'));
        $this->assertSame(Booking::TENTATIVE, $held->status);

        $this->expectException(RoomConflict::class);
        $this->confirmedRental('2031-03-04', '09:59', '2031-03-05', '10:00');
    }

    public function test_other_unit_of_the_same_model_is_free(): void
    {
        $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');

        $other = $this->bookings->confirm($this->rental('2031-03-03', '12:00', '2031-03-04', '12:00', 'canon-g7x-mark-ii-o'));
        $this->assertSame(Booking::CONFIRMED, $other->status);
    }

    public function test_two_rentals_on_one_day_do_not_clash(): void
    {
        $morning = $this->confirmedRental('2031-03-03', '08:00', '2031-03-03', '13:00');
        $this->assertSame('2031-03-04', $morning->check_out->toDateString(), 'the day span is never empty');

        $evening = $this->confirmedRental('2031-03-03', '14:00', '2031-03-04', '14:00');
        $this->assertSame(Booking::CONFIRMED, $evening->status);
        $this->assertCount(0, app(InventoryService::class)->overlaps('2031-01-01'));
    }

    public function test_return_before_pickup_is_refused(): void
    {
        $this->expectException(RoomConflict::class);
        $this->rental('2031-03-03', '10:00', '2031-03-03', '09:00');
    }

    public function test_changing_times_rechecks_conflicts_and_reprices(): void
    {
        $this->confirmedRental('2031-03-05', '10:00', '2031-03-06', '10:00');
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');

        $b = $this->bookings->amend($b, ['check_out' => '2031-03-05', 'return_time' => '10:00', 'reason' => 'Extended'])->fresh();
        $this->assertSame(100000, (int) $b->total_cents);
        $this->assertSame('2031-03-05 10:00:00', substr((string) $b->return_at, 0, 19));

        $this->expectException(RoomConflict::class);
        $this->bookings->amend($b, ['return_time' => '10:01', 'reason' => 'One minute later']);
    }

    public function test_late_return_is_tracked_but_not_charged(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-03 10:00', 'Asia/Manila'));
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');
        $this->bookings->checkIn($b);
        $this->assertSame(0, $b->fresh()->lateMinutes());

        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-04 12:30', 'Asia/Manila'));
        $this->assertSame(150, $b->fresh()->lateMinutes(), 'still out: 2 h 30 min overdue');

        $this->bookings->checkOut($b->fresh(), null, '2031-03-04 12:15');
        $b = $b->fresh();

        $this->assertSame(135, $b->lateMinutes());
        $this->assertSame(50000, (int) $b->total_cents, 'the owner adds a late fee by hand');
        $this->assertStringContainsString('2 h 15 min after the agreed return time', $b->statusEvents()->latest('id')->value('note'));
    }

    public function test_return_time_cannot_be_in_the_future(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-04 09:00', 'Asia/Manila'));
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');
        $this->bookings->checkIn($b);

        $this->expectException(RoomConflict::class);
        $this->bookings->checkOut($b->fresh(), null, '2031-03-04 11:00');
    }

    public function test_new_booking_form_requires_both_times_for_a_camera(): void
    {
        $this->post('/admin/bookings', [
            'guest_name' => 'Renter', 'guest_phone' => '0917', 'room_ids' => [$this->room(self::UNIT)->id],
            'booking_source_id' => $this->source(), 'check_in' => '2031-03-03', 'check_out' => '2031-03-04', 'adults' => 1,
        ])->assertSessionHasErrors(['pickup_time', 'return_time']);
    }

    public function test_availability_uses_exact_times_for_cameras(): void
    {
        $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');
        $id = $this->room(self::UNIT)->id;
        $ask = fn ($pickup) => $this->getJson("/admin/bookings/availability?check_in=2031-03-04&check_out=2031-03-05&pickup_time={$pickup}&return_time=10:00")->assertOk()->json('taken');

        $this->assertArrayHasKey($id, $ask('09:00'));
        $this->assertArrayNotHasKey($id, $ask('10:00'));
    }

    public function test_public_page_shows_the_pickup_day_taken_and_the_return_day_free(): void
    {
        $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '14:00');
        $inventory = app(InventoryService::class);
        $id = $this->room(self::UNIT)->id;

        $this->assertSame([['from' => '2031-03-03', 'to' => '2031-03-04']], $inventory->takenRanges($id, '2031-03-01', '2031-03-10'));
        $this->assertFalse($inventory->isFree($id, '2031-03-03', '2031-03-04'));
        $this->assertTrue($inventory->isFree($id, '2031-03-04', '2031-03-05'));
    }

    public function test_a_camera_stay_without_times_still_blocks_its_days(): void
    {
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-05', '10:00');
        DB::table('booking_rooms')->where('booking_id', $b->id)->update(['starts_at' => null, 'ends_at' => null]);

        $this->expectException(RoomConflict::class);
        $this->confirmedRental('2031-03-04', '10:00', '2031-03-04', '15:00');
    }

    public function test_an_older_rental_without_a_return_time_is_never_shown_late(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-03 10:00', 'Asia/Manila'));
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');
        $this->bookings->checkIn($b);
        $b->update(['return_time' => null]);

        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-04 15:00', 'Asia/Manila'));
        $this->assertSame(0, $b->fresh()->lateMinutes());
    }

    public function test_a_same_day_rental_is_due_back_today_on_the_dashboard_and_list(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-03 08:00', 'Asia/Manila'));
        $b = $this->confirmedRental('2031-03-03', '09:00', '2031-03-03', '17:00');
        $this->bookings->checkIn($b);
        session(['admin_category' => 'cameras']);

        $props = $this->get('/admin')->assertOk()->original->getData()['page']['props'];
        $this->assertStringContainsString($b->reference, json_encode($props), 'listed as due back today');

        $row = collect($this->get('/admin/bookings?view=all')->assertOk()->original->getData()['page']['props']['bookings']['data'])->firstWhere('id', $b->id);
        $this->assertNotNull($row, 'the rental is in the list');
        $this->assertSame('2031-03-03', $row['check_out'], 'its real return day, not the day after');
        $this->assertSame(1, $row['nights']);
    }

    public function test_a_camera_that_is_out_can_have_its_return_extended(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-03 10:00', 'Asia/Manila'));
        $b = $this->confirmedRental('2031-03-03', '10:00', '2031-03-04', '10:00');
        $this->bookings->checkIn($b);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-03-04 09:00', 'Asia/Manila'));

        $this->getJson("/admin/bookings/availability?check_in=2031-03-03&check_out=2031-03-05&pickup_time=10:00&return_time=10:00&ignore_booking={$b->id}")
            ->assertOk()->assertJsonMissingPath('taken.'.$this->room(self::UNIT)->id);
    }

    public function test_suites_still_book_by_nights(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', '2031-03-05', '2031-03-06')->status);

        $this->expectException(RoomConflict::class);
        $this->confirmed('venice', '2031-03-04', '2031-03-06');
    }
}
