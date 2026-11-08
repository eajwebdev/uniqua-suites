<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Models\HousekeepingTask;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

class CameraBookingFlowTest extends DomainTestCase
{
    private const UNIT_N = 'canon-g7x-mark-ii-n';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    public function test_camera_booking_runs_pickup_to_return_without_cleaning_task(): void
    {
        $tasks = HousekeepingTask::count();
        $booking = $this->confirmed(self::UNIT_N, '2031-03-03', '2031-03-06', adults: 1);

        $this->assertSame(135000, (int) $booking->fresh()->total_cents);
        $this->bookings->checkIn($booking->fresh());
        $this->bookings->checkOut($booking->fresh());

        $this->assertSame($tasks, HousekeepingTask::count());
        $this->assertSame('ready', $this->room(self::UNIT_N)->housekeeping_status);
        $this->assertSame('checked_out', $booking->fresh()->status);
    }

    public function test_same_camera_unit_cannot_be_double_booked(): void
    {
        $this->confirmed(self::UNIT_N, '2031-03-03', '2031-03-06', adults: 1);

        $other = $this->confirmed('canon-g7x-mark-ii-o', '2031-03-03', '2031-03-06', adults: 1);
        $this->assertSame('confirmed', $other->fresh()->status);

        $this->expectException(RoomConflict::class);
        $this->confirmed(self::UNIT_N, '2031-03-05', '2031-03-07', adults: 1);
    }

    public function test_the_id_check_is_recorded_without_changing_total(): void
    {
        $booking = $this->confirmed(self::UNIT_N, '2031-03-03', '2031-03-05', adults: 1);
        $total = (int) $booking->fresh()->total_cents;

        $this->post("/admin/bookings/{$booking->id}/rental-security", [
            'id_type' => "Driver's license", 'id_status' => 'held',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(0, $fresh->deposit_cents, 'No security deposit, as in the terms.');
        $this->assertSame("Driver's license", $fresh->id_type);
        $this->assertSame('held', $fresh->id_status);
        $this->assertSame($total, (int) $fresh->total_cents);
    }

    public function test_rental_security_rejected_for_suite_booking(): void
    {
        $booking = $this->confirmed('venice');

        $this->post("/admin/bookings/{$booking->id}/rental-security", ['id_status' => 'held'])
            ->assertStatus(422);
    }

    public function test_return_time_is_saved_from_new_booking(): void
    {
        $this->post('/admin/bookings', [
            'guest_name' => 'Synthetic Renter', 'guest_messenger_handle' => 'synthetic.ig',
            'room_ids' => [$this->room(self::UNIT_N)->id], 'booking_source_id' => $this->source('instagram'),
            'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'adults' => 1,
            'pickup_time' => '14:00', 'return_time' => '13:00',
        ])->assertSessionHasNoErrors();

        $booking = \App\Models\Booking::latest('id')->first();
        $this->assertSame('13:00', substr($booking->return_time, 0, 5));
        $this->assertSame('14:00', substr($booking->requested_arrival_time, 0, 5));
    }

    public function test_an_authorized_discount_on_a_camera_rental_is_kept(): void
    {
        $booking = $this->bookings->confirm($this->inquiry(self::UNIT_N, '2031-03-03', '2031-03-05', 1), ['discount_cents' => 20000, 'discount_reason' => 'Repeat customer']);

        $this->assertSame(80000, (int) $booking->fresh()->total_cents);
        $this->assertTrue($booking->charges()->where('type', 'discount')->whereNull('voided_at')->exists());
    }

    public function test_a_booking_cannot_be_moved_to_the_other_business(): void
    {
        $camera = $this->confirmed(self::UNIT_N, '2031-03-03', '2031-03-05', adults: 1);
        $suite = $this->confirmed('venice');

        foreach ([[$camera, 'venice'], [$suite, 'dji-nano']] as [$booking, $target]) {
            try {
                $this->bookings->amend($booking->fresh(), ['room_ids' => [$this->room($target)->id], 'reason' => 'test']);
                $this->fail('Moving a booking between businesses must be refused.');
            } catch (RoomConflict $e) {
                $this->assertStringContainsString('business', $e->getMessage());
            }
        }
    }
}
