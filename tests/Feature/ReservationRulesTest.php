<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\LaundryJob;
use App\Models\RoomBlock;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\DomainTestCase;

class ReservationRulesTest extends DomainTestCase
{
    public function test_airbnb_booking_blocks_a_facebook_booking_for_the_same_dates(): void
    {
        $this->confirmed('venice', source: 'airbnb');

        $this->expectException(RoomConflict::class);
        $this->confirmed('venice', source: 'facebook_messenger');
    }

    public function test_inquiry_does_not_block_inventory_but_an_active_hold_does(): void
    {
        $inquiry = $this->inquiry();
        $second = $this->confirmed('venice', '2031-03-03', '2031-03-04');
        $this->assertSame(Booking::CONFIRMED, $second->status);

        $this->expectException(RoomConflict::class);
        $this->bookings->hold($inquiry);
    }

    public function test_expired_hold_releases_dates_and_a_confirmed_booking_never_expires(): void
    {
        $held = $this->bookings->hold($this->inquiry());
        $other = $this->confirmed('bali');
        Booking::whereKey($held->id)->update(['hold_expires_at' => now()->subMinute()]);
        Booking::whereKey($other->id)->update(['hold_expires_at' => now()->subMinute()]);

        $this->assertSame(1, $this->bookings->expireHolds());
        $this->assertSame(0, $this->bookings->expireHolds(), 'idempotent');
        $this->assertSame(Booking::EXPIRED, $held->fresh()->status);
        $this->assertSame(Booking::CONFIRMED, $other->fresh()->status);

        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice')->status);
    }

    public function test_back_to_back_is_allowed_but_arrival_needs_a_ready_room(): void
    {
        $first = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $second = $this->confirmed('venice', '2031-03-05', '2031-03-07');

        $this->assertSame('2031-03-05', $first->check_out->toDateString());
        $this->assertSame('2031-03-05', $second->check_in->toDateString());

        $this->bookings->checkIn($first);
        $this->bookings->checkOut($first);

        try {
            $this->bookings->checkIn($second);
            $this->fail('A dirty room must block check-in.');
        } catch (RoomConflict $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }
        $this->assertSame(Booking::CONFIRMED, $second->fresh()->status);
    }

    public function test_the_next_guest_cannot_check_in_while_the_last_guest_is_still_in_the_room(): void
    {
        $first = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $second = $this->confirmed('venice', '2031-03-05', '2031-03-07');
        $this->room('venice')->update(['housekeeping_status' => 'ready']);
        $this->bookings->checkIn($first);

        foreach ([false, true] as $override) {
            try {
                $this->bookings->checkIn($second->fresh(), $override, 'Override must not help');
                $this->fail('A room cannot hold two parties.');
            } catch (RoomConflict $e) {
                $this->assertStringContainsString('still has a guest checked in', $e->getMessage());
            }
        }
        $this->assertSame(Booking::CONFIRMED, $second->fresh()->status);

        $this->bookings->checkOut($first->fresh());
        $this->assertSame(Booking::CHECKED_IN, $this->bookings->checkIn($second->fresh(), true, 'Cleaned early')->status);
    }

    public function test_moving_a_room_extending_dates_and_group_changes_recheck_conflicts(): void
    {
        $this->confirmed('bali', '2031-03-03', '2031-03-05');
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $later = $this->confirmed('venice', '2031-03-06', '2031-03-08');

        foreach ([
            ['room_ids' => [$this->room('bali')->id]],
            ['check_out' => '2031-03-07'],
            ['room_ids' => [$this->room('venice')->id, $this->room('bali')->id]],
        ] as $change) {
            try {
                $this->bookings->amend($booking, $change);
                $this->fail('Expected a conflict for '.json_encode($change));
            } catch (RoomConflict) {
                $this->assertSame('2031-03-05', $booking->fresh()->check_out->toDateString());
                $this->assertSame(1, $booking->activeRooms()->count());
            }
        }

        $moved = $this->bookings->amend($booking, ['room_ids' => [$this->room('roma')->id]]);
        $this->assertSame($this->room('roma')->id, $moved->activeRooms()->first()->room_id);
        $this->assertSame(Booking::CONFIRMED, $later->fresh()->status);
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', '2031-03-03', '2031-03-05')->status, 'old room was released');
    }

    public function test_out_of_service_and_safety_work_orders_prevent_assignment(): void
    {
        RoomBlock::create(['room_id' => $this->room('venice')->id, 'start_date' => '2031-03-04', 'end_date' => '2031-03-06', 'kind' => 'repair', 'reason' => 'Test']);
        WorkOrder::create(['room_id' => $this->room('bali')->id, 'title' => 'Exposed wiring', 'severity' => 'critical', 'safety_block' => true, 'status' => 'open']);

        foreach (['venice', 'bali'] as $slug) {
            try {
                $this->confirmed($slug);
                $this->fail("{$slug} should be unavailable");
            } catch (RoomConflict) {
                $this->assertTrue(true);
            }
        }

        WorkOrder::query()->update(['status' => 'resolved']);
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('bali')->status);
    }

    public function test_checkout_retry_creates_exactly_one_turnover_and_laundry_job(): void
    {
        $booking = $this->confirmed();
        $this->bookings->checkIn($booking);
        $this->bookings->checkOut($booking);
        $this->bookings->checkOut($booking);

        $this->assertSame(1, HousekeepingTask::where('booking_id', $booking->id)->count());
        $this->assertSame(1, LaundryJob::where('booking_id', $booking->id)->count());
        $this->assertSame('dirty', $this->room('venice')->housekeeping_status);
        $this->assertSame(Booking::CHECKED_OUT, $booking->fresh()->status);
    }

    /**
     * Proves the mechanism, not just the outcome: with the room row locked elsewhere,
     * a confirm must not even read availability until that lock is released.
     */
    public function test_availability_check_waits_for_the_room_row_lock(): void
    {
        $booking = $this->inquiry('venice');
        $roomId = $this->room('venice')->id;
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
        $locker = DB::connection('mysql_lock');

        try {
            $locker->beginTransaction();
            $locker->table('rooms')->where('id', $roomId)->lockForUpdate()->get();

            $worker = new Process([PHP_BINARY, 'artisan', 'uniqa:test-confirm', (string) $booking->id, (string) $this->owner->id, '0', '--env=testing'], base_path());
            $worker->setTimeout(60);
            $worker->start();

            // Long enough for the worker to boot and reach the lock.
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && $worker->isRunning()) {
                usleep(100_000);
            }
            $this->assertStringNotContainsString('CHECKING', $worker->getOutput(), 'Availability was read while another transaction held the room lock.');
            $this->assertTrue($worker->isRunning(), 'Worker should still be waiting for the lock: '.$worker->getOutput().$worker->getErrorOutput());

            $locker->commit();
            $worker->wait();
            $this->assertStringContainsString('CHECKING', $worker->getOutput());
            $this->assertStringContainsString('CONFIRMED', $worker->getOutput());
        } finally {
            if ($locker->transactionLevel() > 0) {
                $locker->rollBack();
            }
            foreach (['room_night_claims', 'booking_status_events', 'booking_nights', 'booking_charges', 'pricing_snapshots', 'booking_rooms', 'bookings', 'guests'] as $table) {
                DB::table($table)->delete();
            }
            DB::beginTransaction();
        }
    }

    /**
     * Two OS processes on separate MariaDB connections race to confirm one room.
     * Uses committed data because each process has its own connection.
     */
    public function test_two_real_concurrent_transactions_cannot_both_confirm_a_room(): void
    {
        $a = $this->inquiry('venice');
        $b = $this->inquiry('venice');
        $charges = fn () => DB::table('booking_charges')->count();
        // RefreshDatabase wraps the test in a transaction the workers could not see.
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        try {
            $workers = [];
            $startAt = (string) (microtime(true) + 4);
            foreach ([$a->id, $b->id] as $id) {
                $p = new Process([PHP_BINARY, 'artisan', 'uniqa:test-confirm', (string) $id, (string) $this->owner->id, $startAt, '--env=testing'], base_path());
                $p->start();
                $workers[] = $p;
            }
            $outputs = [];
            foreach ($workers as $p) {
                $p->wait();
                $lines = explode("\n", trim($p->getOutput()));
                $outputs[] = trim(end($lines));
            }

            $this->assertEqualsCanonicalizing(['CONFIRMED', 'CONFLICT'], $outputs, implode(' | ', $outputs).' '.$workers[0]->getErrorOutput());
            $this->assertSame(1, Booking::whereIn('id', [$a->id, $b->id])->where('status', Booking::CONFIRMED)->count());
            $loser = Booking::whereIn('id', [$a->id, $b->id])->where('status', Booking::INQUIRY)->firstOrFail();
            $this->assertSame(0, DB::table('booking_charges')->where('booking_id', $loser->id)->count(), 'no partial financial update');
            $this->assertSame(2, $charges());
        } finally {
            foreach (['room_night_claims', 'booking_status_events', 'booking_nights', 'booking_charges', 'pricing_snapshots', 'booking_rooms', 'bookings', 'guests'] as $table) {
                DB::table($table)->delete();
            }
            DB::beginTransaction();
        }
    }
}
