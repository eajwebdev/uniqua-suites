<?php

namespace Tests\Feature;

use App\Domain\Parking\ParkingService;
use App\Models\Booking;
use App\Models\ExternalSettlement;
use App\Models\ParkingAssignment;
use App\Models\ParkingBlock;
use App\Models\ParkingSpace;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Local;
use Tests\DomainTestCase;

/**
 * Parking requests with or without a stall, their own fee and payments, slot availability, and the booking link:
 * parking never changes the room's price or what was already paid for the stay.
 */
class ParkingManagementTest extends DomainTestCase
{
    private function space(?string $code = null, string $ownership = 'own'): ParkingSpace
    {
        return ParkingSpace::create(['property_id' => $this->room('venice')->property_id, 'code' => $code ?? 'P-'.uniqid(), 'ownership' => $ownership, 'vendor_name' => $ownership === 'borrowed' ? 'Ms. Lim' : null, 'rate_cents' => 25000]);
    }

    private function request(Booking $booking, array $extra = []): ParkingAssignment
    {
        $this->post('/admin/parking/assignments', $extra + ['booking_id' => $booking->id, 'status' => 'requested', 'start_date' => '2031-03-03', 'end_date' => '2031-03-05', 'rate' => '300'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        return ParkingAssignment::latest('id')->firstOrFail();
    }

    private function pay(ParkingAssignment $a, int $cents, string $status = 'verified'): void
    {
        Receipt::create(['reference' => 'RC-'.uniqid(), 'booking_id' => $a->booking_id, 'parking_assignment_id' => $a->id, 'received_at' => now(), 'receipt_date' => Local::today(), 'amount_cents' => $cents, 'method' => 'cash', 'verification_status' => $status]);
    }

    private function payments(ParkingAssignment $a): array
    {
        return app(ParkingService::class)->payments(collect([$a->fresh()]))[$a->id];
    }

    public function test_a_request_without_a_stall_charges_the_booking_but_holds_no_space(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $stay = (int) $booking->total_cents;
        $roomCharges = $booking->activeCharges()->pluck('amount_cents', 'id')->all();
        $space = $this->space('P-1');

        $a = $this->request($booking);

        $this->assertNull($a->parking_space_id);
        $this->assertSame([ParkingAssignment::REQUESTED, 2, 60000], [$a->status, $a->nights, $a->fee_cents]);
        $this->assertSame($stay + 60000, (int) $booking->fresh()->total_cents, 'The fee adds to what the guest owes.');
        $this->assertSame($roomCharges, $booking->activeCharges()->where('type', '!=', 'parking')->pluck('amount_cents', 'id')->all(), 'Room charges untouched.');
        $this->assertSame('Guest', explode(' ', $a->holder_name)[1], 'The guest name comes from the booking.');

        // No stall is held: another guest can be assigned the stall for the same nights.
        $other = $this->confirmed('bali', '2031-03-03', '2031-03-05');
        $this->request($other, ['parking_space_id' => $space->id, 'status' => 'assigned']);
        $this->assertSame(1, ParkingAssignment::blocking()->count());
    }

    public function test_assigning_blocks_the_stall_and_overlaps_and_manual_blocks_are_refused(): void
    {
        $space = $this->space('P-2');
        $first = $this->request($this->confirmed('venice', '2031-03-03', '2031-03-05'), ['parking_space_id' => $space->id, 'status' => 'assigned']);
        $second = $this->confirmed('bali', '2031-03-03', '2031-03-05');

        $this->post('/admin/parking/assignments', ['booking_id' => $second->id, 'parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-04', 'end_date' => '2031-03-06'])->assertSessionHasErrors('parking_space_id');
        // Pencilled in but not confirmed: recorded, and does not hold the stall.
        $this->post('/admin/parking/assignments', ['booking_id' => $second->id, 'parking_space_id' => $space->id, 'status' => 'pending_confirmation', 'start_date' => '2031-03-04', 'end_date' => '2031-03-06'])->assertSessionHasNoErrors();
        $this->assertSame(ParkingAssignment::PENDING, ParkingAssignment::latest('id')->first()->status);

        // A manual block cannot cover a confirmed guest, and blocks the stall for new assignments.
        $this->post('/admin/parking/blocks', ['parking_space_id' => $space->id, 'start_date' => '2031-03-04', 'end_date' => '2031-03-07', 'reason' => 'owner_use'])->assertSessionHasErrors('start_date');
        $this->post('/admin/parking/blocks', ['parking_space_id' => $space->id, 'start_date' => '2031-03-10', 'end_date' => '2031-03-12', 'reason' => 'maintenance'])->assertSessionHasNoErrors();
        $block = ParkingBlock::sole();
        $this->post('/admin/parking/assignments', ['holder_name' => 'Walk-in', 'parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-11', 'end_date' => '2031-03-12'])->assertSessionHasErrors('parking_space_id');

        $free = collect(app(ParkingService::class)->availability('2031-03-11', '2031-03-12'))->firstWhere('id', $space->id);
        $this->assertFalse($free['free']);

        // Removing the block frees it again.
        $this->post("/admin/parking/blocks/{$block->id}/delete")->assertSessionHasNoErrors();
        $this->post('/admin/parking/assignments', ['holder_name' => 'Walk-in', 'parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-11', 'end_date' => '2031-03-12'])->assertSessionHasNoErrors();

        // Moving the first guest off the stall frees their nights.
        $this->post("/admin/parking/assignments/{$first->id}", ['booking_id' => $first->booking_id, 'status' => 'requested', 'start_date' => '2031-03-03', 'end_date' => '2031-03-05', 'rate' => '300'])->assertSessionHasNoErrors();
        $this->assertTrue(collect(app(ParkingService::class)->availability('2031-03-03', '2031-03-05'))->firstWhere('id', $space->id)['free']);
    }

    public function test_payment_status_is_separate_from_assignment_and_tracks_partial_payments(): void
    {
        $a = $this->request($this->confirmed('venice', '2031-03-03', '2031-03-05'));
        $this->assertSame(ParkingService::TO_COLLECT, $this->payments($a)['payment_status']);

        $this->pay($a, 20000);
        $this->pay($a, 10000, 'pending');
        $p = $this->payments($a);
        $this->assertSame(['partially_paid', 20000, 10000, 40000], [$p['payment_status'], $p['paid_cents'], $p['pending_cents'], $p['balance_cents']]);

        $this->pay($a, 40000);
        $this->assertSame(ParkingService::PAID, $this->payments($a)['payment_status']);
        $this->assertSame(ParkingAssignment::REQUESTED, $a->fresh()->status, 'Paid, while the stall is still to be confirmed.');
    }

    public function test_recording_a_parking_payment_tags_the_receipt_and_warns_on_overpaying_parking(): void
    {
        $a = $this->request($this->confirmed('venice', '2031-03-03', '2031-03-05'));
        $now = Local::now()->format('Y-m-d\TH:i');

        $this->post('/admin/receipts', ['parking_assignment_id' => $a->id, 'received_at' => $now, 'amount' => '700', 'method' => 'cash'])->assertSessionHasErrors(['amount', 'confirm_unusual']);
        $this->post('/admin/receipts', ['parking_assignment_id' => $a->id, 'received_at' => $now, 'amount' => '600', 'method' => 'cash'])->assertSessionHasNoErrors();

        $receipt = Receipt::where('parking_assignment_id', $a->id)->sole();
        $this->assertSame([$a->booking_id, 60000, 'pending'], [$receipt->booking_id, (int) $receipt->amount_cents, $receipt->verification_status]);

        $checker = User::factory()->create();
        $checker->assignRole('Finance / Bookkeeper');
        $this->actingAs($checker)->post("/admin/receipts/{$receipt->id}/review", ['decision' => 'verified'])->assertSessionHasNoErrors();
        $this->assertSame(ParkingService::PAID, $this->payments($a)['payment_status']);
    }

    public function test_an_airbnb_stay_stays_paid_when_parking_is_added(): void
    {
        $booking = $this->inquiry('venice', '2031-03-03', '2031-03-05', 2, 'airbnb');
        $booking->update(['channel_total_cents' => 500000]);
        $booking = $this->bookings->confirm($booking->fresh());
        ExternalSettlement::create(['booking_id' => $booking->id, 'provider' => 'airbnb', 'gross_cents' => (int) $booking->total_cents, 'expected_net_cents' => 450000, 'actual_payout_cents' => 450000, 'payout_date' => '2031-03-06']);

        $a = $this->request($booking);
        $split = app(ParkingService::class)->bookingSplit($booking->fresh(), app(\App\Domain\Finance\FinanceService::class)->balance($booking->fresh()));

        $this->assertSame($split['stay_charges_cents'], $split['stay_paid_cents'], 'The Airbnb stay is still fully paid.');
        $this->assertSame([60000, 0, 60000], [$split['parking_fee_cents'], $split['parking_paid_cents'], $split['parking_balance_cents']]);
        $this->assertSame(ParkingService::TO_COLLECT, $this->payments($a)['payment_status']);

        $row = collect($this->get('/admin/parking?type=airbnb')->assertOk()->original->getData()['page']['props']['summary'])->firstWhere('id', $a->id);
        $this->assertSame(['airbnb', 'to_collect', 'requested', null], [$row['booking_type'], $row['payment_status'], $row['status'], $row['slot']]);
    }

    public function test_a_general_payment_covers_the_stay_first_then_parking(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $stay = (int) $booking->total_cents;
        $a = $this->request($booking);

        Receipt::create(['reference' => 'RC-'.uniqid(), 'booking_id' => $booking->id, 'received_at' => now(), 'receipt_date' => Local::today(), 'amount_cents' => $stay + 10000, 'method' => 'cash', 'verification_status' => 'verified']);
        $this->assertSame([ParkingService::PARTIAL, 10000], [$this->payments($a)['payment_status'], $this->payments($a)['paid_cents']]);
    }

    public function test_parking_asked_for_with_a_new_booking_is_in_the_estimate_and_survives_confirmation(): void
    {
        $this->post('/admin/bookings', [
            'guest_name' => 'Synthetic Parker', 'guest_phone' => '0917', 'room_ids' => [$this->room('venice')->id], 'booking_source_id' => $this->source(),
            'check_in' => '2031-03-03', 'check_out' => '2031-03-06', 'adults' => 2,
            'parking' => true, 'parking_start' => '2031-03-03', 'parking_end' => '2031-03-05', 'parking_rate' => '300', 'parking_vehicle' => 'ABC 123',
        ])->assertSessionHasNoErrors();
        $booking = Booking::latest('id')->firstOrFail();
        $a = ParkingAssignment::where('booking_id', $booking->id)->sole();
        $this->assertSame([ParkingAssignment::REQUESTED, null, 60000, 'ABC 123', 'Synthetic Parker'], [$a->status, $a->parking_space_id, $a->fee_cents, $a->vehicle, $a->holder_name]);

        $estimate = $this->bookings->estimate($booking);
        $this->assertContains('parking', array_column($estimate['lines'], 'type'));

        $confirmed = $this->bookings->confirm($booking->fresh());
        $this->assertSame($estimate['total_cents'], (int) $confirmed->total_cents, 'Confirmation keeps the parking: same total as the estimate the downpayment was based on.');
        $this->assertSame(1, $confirmed->activeCharges()->where('type', 'parking')->count());
    }

    public function test_cancelling_the_booking_releases_its_parking(): void
    {
        $space = $this->space('P-3');
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $a = $this->request($booking, ['parking_space_id' => $space->id, 'status' => 'assigned']);

        $this->bookings->cancel($booking, 'Guest changed plans');

        $this->assertSame(ParkingAssignment::CANCELLED, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->charge->voided_at);
        $this->assertTrue(collect(app(ParkingService::class)->availability('2031-03-03', '2031-03-05'))->firstWhere('id', $space->id)['free']);
    }

    public function test_changing_dates_or_rate_replaces_the_charge_without_touching_room_charges(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-06');
        $stay = (int) $booking->total_cents;
        $a = $this->request($booking);
        $oldCharge = $a->charge_id;

        $this->post("/admin/parking/assignments/{$a->id}", ['booking_id' => $booking->id, 'status' => 'requested', 'start_date' => '2031-03-03', 'end_date' => '2031-03-06', 'rate' => '250'])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame([3, 75000], [$a->nights, $a->fee_cents]);
        $this->assertNotSame($oldCharge, $a->charge_id);
        $this->assertSame($stay + 75000, (int) $booking->fresh()->total_cents);
    }

    public function test_spaces_can_be_edited_and_an_unavailable_stall_cannot_be_assigned(): void
    {
        $space = $this->space('P-4');
        $this->post("/admin/parking/spaces/{$space->id}", ['code' => 'P-4A', 'name' => 'Basement 2', 'ownership' => 'borrowed', 'vendor_name' => 'Mr. Cruz', 'vendor_contact' => '0917', 'rate' => '350', 'status' => 'unavailable'])->assertSessionHasNoErrors();
        $space->refresh();
        $this->assertSame(['P-4A', 'Basement 2', 'borrowed', 'Mr. Cruz', 35000, 'unavailable'], [$space->code, $space->name, $space->ownership, $space->vendor_name, (int) $space->rate_cents, $space->status]);

        $this->post('/admin/parking/assignments', ['holder_name' => 'Walk-in', 'parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-03', 'end_date' => '2031-03-04', 'owner_confirmed' => true])->assertSessionHasErrors('parking_space_id');

        // A stall with a confirmed guest coming cannot be switched off until that guest is moved.
        $this->post("/admin/parking/spaces/{$space->id}", ['code' => 'P-4A', 'ownership' => 'own', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post('/admin/parking/assignments', ['holder_name' => 'Walk-in', 'parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-03', 'end_date' => '2031-03-04'])->assertSessionHasNoErrors();
        $this->post("/admin/parking/spaces/{$space->id}", ['code' => 'P-4A', 'ownership' => 'own', 'status' => 'inactive'])->assertSessionHasErrors('status');
    }

    public function test_the_summary_filters_and_the_dashboard_counts(): void
    {
        $this->travelTo('2031-03-04 10:00:00');
        $space = $this->space('P-5');
        $now = $this->request($this->confirmed('venice', '2031-03-03', '2031-03-06'), ['parking_space_id' => $space->id, 'status' => 'assigned', 'start_date' => '2031-03-03', 'end_date' => '2031-03-06']);
        $waiting = $this->request($this->confirmed('bali', '2031-03-10', '2031-03-12'), ['start_date' => '2031-03-10', 'end_date' => '2031-03-12']);
        $this->pay($now, (int) $now->fee_cents);

        $summary = fn (string $q = '') => collect($this->get('/admin/parking'.$q)->assertOk()->original->getData()['page']['props']['summary']);
        $this->assertSame(['occupied', 'pending_slot'], $summary()->pluck('timing')->all());
        $this->assertSame([$waiting->id], $summary('?space=tba')->pluck('id')->all());
        $this->assertSame([$now->id], $summary('?payment=paid')->pluck('id')->all());
        $this->assertSame([$waiting->id], $summary('?assignment=requested')->pluck('id')->all());
        $this->assertSame([$now->id], $summary('?q='.$now->booking->reference)->pluck('id')->all());
        $this->assertSame([$now->id], $summary('?room='.$this->room('venice')->id)->pluck('id')->all());

        $dash = $this->get('/admin')->assertOk()->original->getData()['page']['props']['parking'];
        $this->assertSame([1, 2, 1, 1], [$dash['occupied'], $dash['reservations'], $dash['upcoming'], $dash['unassigned']]);
        $this->assertSame((int) $waiting->fee_cents, $dash['money']['outstanding_cents']);
        $this->assertSame(1, $dash['money']['unpaid']);
    }
}
