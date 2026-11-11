<?php

namespace Tests\Feature;

use App\Domain\Reporting\ReportService;
use App\Models\Booking;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Support\Local;
use Tests\DomainTestCase;

/**
 * A stall borrowed from another condo owner: the guest pays for it as usual, but the money is the owner's.
 * It is not a sale, it is remitted to the owner, and the remittance is money out in the Cashbook.
 */
class BorrowedParkingTest extends DomainTestCase
{
    private function space(string $ownership, ?string $owner = null): ParkingSpace
    {
        return ParkingSpace::create(['property_id' => $this->room('venice')->property_id, 'code' => 'P-'.uniqid(), 'ownership' => $ownership, 'vendor_name' => $owner, 'rate_cents' => 30000]);
    }

    private function park(ParkingSpace $space, Booking $booking): ParkingAssignment
    {
        $this->post('/admin/parking/assignments', ['parking_space_id' => $space->id, 'status' => 'assigned', 'holder_name' => 'Synthetic Driver', 'booking_id' => $booking->id, 'start_date' => '2031-03-03', 'end_date' => '2031-03-05', 'rate' => '300', 'owner_confirmed' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        return ParkingAssignment::latest('id')->firstOrFail();
    }

    public function test_a_borrowed_space_needs_its_owners_name(): void
    {
        $property = $this->room('venice')->property_id;
        $this->post('/admin/parking/spaces', ['property_id' => $property, 'code' => 'B-1', 'ownership' => 'borrowed'])->assertSessionHasErrors('vendor_name');
        $this->post('/admin/parking/spaces', ['property_id' => $property, 'code' => 'B-1', 'ownership' => 'borrowed', 'vendor_name' => 'Mr. Reyes, unit 12F', 'rate' => '300'])->assertSessionHasNoErrors();
        $this->assertTrue(ParkingSpace::where('code', 'B-1')->sole()->isBorrowed());

        $own = ParkingSpace::where('code', 'B-1')->sole();
        $this->post("/admin/parking/spaces/{$own->id}", ['code' => 'B-1', 'ownership' => 'own', 'vendor_name' => '', 'rate' => '300'])->assertSessionHasNoErrors();
        $this->assertFalse($own->fresh()->isBorrowed());
    }

    public function test_the_guest_pays_but_borrowed_parking_is_not_a_sale(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $before = app(ReportService::class)->kpis('2031-03-01', '2031-03-31');
        $owed = (int) $booking->total_cents;

        $this->park($this->space('borrowed', 'Mr. Reyes'), $booking);
        $booking->refresh();

        $this->assertSame($owed + 60000, (int) $booking->total_cents, 'The guest still pays 2 days × ₱300.');
        $this->assertSame(1, $booking->activeCharges()->where('type', 'parking_remit')->count());
        $after = app(ReportService::class)->kpis('2031-03-01', '2031-03-31');
        $this->assertSame($before['gross_operating_cents'], $after['gross_operating_cents'], 'Not counted as sales.');
        $this->assertSame($before['booked_cents'], $after['booked_cents']);

        // Our own stall is a sale as before.
        $bali = $this->confirmed('bali', '2031-03-03', '2031-03-05');
        $withBali = app(ReportService::class)->kpis('2031-03-01', '2031-03-31')['gross_operating_cents'];
        $this->park($this->space('own'), $bali);
        $this->assertSame($withBali + 60000, app(ReportService::class)->kpis('2031-03-01', '2031-03-31')['gross_operating_cents']);
    }

    public function test_remitting_records_the_payment_and_shows_in_the_cashbook(): void
    {
        $assignment = $this->park($this->space('borrowed', 'Mr. Reyes'), $this->confirmed('venice', '2031-03-03', '2031-03-05'));
        $this->assertSame([60000, 'Mr. Reyes'], [(int) $assignment->remit_cents, $assignment->remit_to]);

        $page = $this->get('/admin/parking/remittances')->assertOk()->original->getData()['page']['props'];
        $this->assertSame(60000, $page['stats'][0]['value'], 'To remit');
        $this->assertSame('to_remit', $page['rows'][0]['state']);

        $today = Local::today();
        $this->post("/admin/parking/assignments/{$assignment->id}/remit", ['remitted_on' => $today, 'amount' => '600', 'method' => 'gcash', 'reference' => 'GC-123'])->assertSessionHasNoErrors();
        $this->assertSame([$today, 60000], [$assignment->fresh()->remitted_on->toDateString(), (int) $assignment->fresh()->remitted_cents]);
        $this->post("/admin/parking/assignments/{$assignment->id}/remit", ['remitted_on' => $today, 'amount' => '600', 'method' => 'cash'])->assertSessionHasErrors('amount');

        $cashbook = collect($this->get("/admin/cashbook?from={$today}&to={$today}")->assertOk()->original->getData()['page']['props']['rows'])->firstWhere('type', 'Parking remitted');
        $this->assertSame(60000, $cashbook['out_cents']);

        // Already paid out: cancelling must not silently void it.
        $this->post("/admin/parking/assignments/{$assignment->id}/cancel")->assertSessionHasErrors('assignment');

        $this->post("/admin/parking/assignments/{$assignment->id}/unremit")->assertSessionHasNoErrors();
        $this->assertNull($assignment->fresh()->remitted_on);
        $this->post("/admin/parking/assignments/{$assignment->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame(0, ParkingAssignment::toRemit()->count(), 'Cancelled parking is owed to nobody.');
    }

    public function test_a_borrowed_space_is_only_assigned_after_calling_its_owner(): void
    {
        $space = $this->space('borrowed', 'Mr. Reyes');
        $data = ['parking_space_id' => $space->id, 'status' => 'assigned', 'holder_name' => 'Synthetic Driver', 'start_date' => '2031-03-03', 'end_date' => '2031-03-05'];

        $this->post('/admin/parking/assignments', $data)->assertSessionHasErrors('owner_confirmed');
        $this->post('/admin/parking/assignments', $data + ['owner_confirmed' => true])->assertSessionHasNoErrors();

        // Our own stall needs no call: the system knows when it is taken.
        $this->post('/admin/parking/assignments', ['parking_space_id' => $this->space('own')->id] + $data)->assertSessionHasNoErrors();
        $this->assertSame(2, ParkingAssignment::count());
    }

    public function test_the_spaces_list_tags_each_stall_and_says_whether_it_is_free_today(): void
    {
        $ours = $this->space('own');
        $borrowed = ParkingSpace::create(['property_id' => $ours->property_id, 'code' => 'B-9', 'ownership' => 'borrowed', 'vendor_name' => 'Ms. Lim', 'vendor_contact' => '0917 000 0000', 'rate_cents' => 30000]);
        $taken = $this->space('own');
        ParkingAssignment::create(['parking_space_id' => $taken->id, 'holder_name' => 'Synthetic Guest', 'start_date' => Local::today(), 'end_date' => now()->addDays(2)->toDateString(), 'status' => 'assigned']);

        $rows = collect($this->get('/admin/parking/spaces')->assertOk()->original->getData()['page']['props']['rows'])->keyBy('space');

        $this->assertSame(['condo_owned', 'Free'], [$rows[$ours->code]['ownership'], $rows[$ours->code]['today']]);
        $this->assertSame('third_party', $rows['B-9']['ownership']);
        $this->assertSame('Not ours: call Ms. Lim (0917 000 0000) to check', $rows['B-9']['today']);
        $this->assertStringStartsWith('In use: Synthetic Guest', $rows[$taken->code]['today']);
    }
}

