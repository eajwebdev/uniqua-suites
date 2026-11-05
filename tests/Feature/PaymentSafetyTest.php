<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Local;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\DomainTestCase;

/** People make mistakes at a busy front desk. These stop the common ones without getting in the way of real payments. */
class PaymentSafetyTest extends DomainTestCase
{
    private function booking(): Booking
    {
        return $this->confirmed('venice', Local::now()->addDays(2)->toDateString(), Local::now()->addDays(4)->toDateString());
    }

    private function checkedIn(): Booking
    {
        $booking = $this->confirmed('venice', Local::today(), Local::now()->addDay()->toDateString());
        $this->bookings->checkIn($booking);

        return $booking->fresh();
    }

    private function pay(Booking $booking, array $extra = [])
    {
        return $this->post('/admin/receipts', $extra + [
            'booking_reference' => $booking->reference,
            'received_at' => Local::now()->format('Y-m-d\TH:i'),
            'amount' => '100.00',
            'method' => 'cash',
        ]);
    }

    public function test_a_normal_payment_goes_straight_through(): void
    {
        $this->pay($this->booking())->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(10000, (int) Receipt::sole()->amount_cents);
        $this->assertSame('pending', Receipt::sole()->verification_status);
    }

    public function test_zero_can_never_be_saved(): void
    {
        $this->pay($this->booking(), ['amount' => '0', 'confirm_unusual' => true])->assertSessionHasErrors('amount');

        $this->assertSame(0, Receipt::count());
    }

    public function test_a_future_date_can_never_be_saved(): void
    {
        $this->pay($this->booking(), ['received_at' => Local::now()->addDay()->format('Y-m-d\TH:i'), 'confirm_unusual' => true])->assertSessionHasErrors('received_at');

        $this->assertSame(0, Receipt::count());
    }

    public function test_gcash_and_bank_need_a_reference_or_proof(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $this->pay($booking, ['method' => 'gcash', 'confirm_unusual' => true])->assertSessionHasErrors('external_reference');
        $this->pay($booking, ['method' => 'bank_transfer', 'external_reference' => '   ', 'confirm_unusual' => true])->assertSessionHasErrors('external_reference');
        $this->assertSame(0, Receipt::count());

        $this->pay($booking, ['method' => 'gcash', 'external_reference' => 'GC-1'])->assertSessionHasNoErrors();
        $this->pay($booking, ['method' => 'gcash', 'amount' => '200.00', 'evidence' => UploadedFile::fake()->image('proof.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(2, Receipt::count());
    }

    public function test_entering_the_same_payment_twice_needs_a_second_look(): void
    {
        $booking = $this->booking();
        $this->pay($booking)->assertSessionHasNoErrors();

        $this->pay($booking)->assertSessionHasErrors(['amount', 'confirm_unusual']);
        $this->assertSame(1, Receipt::count());

        $this->pay($booking, ['confirm_unusual' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, Receipt::count());
    }

    public function test_a_rejected_payment_can_be_entered_again_without_a_warning(): void
    {
        $booking = $this->booking();
        $this->pay($booking);
        app(FinanceService::class)->review(Receipt::sole(), false, 'Wrong amount');

        $this->pay($booking)->assertSessionHasNoErrors();
        $this->assertSame(2, Receipt::count());
    }

    public function test_the_same_reference_on_any_booking_needs_a_second_look(): void
    {
        $this->pay($this->booking(), ['method' => 'gcash', 'external_reference' => 'GC-SAME']);
        $other = $this->confirmed('bali', Local::now()->addDays(2)->toDateString(), Local::now()->addDays(4)->toDateString());

        $this->pay($other, ['method' => 'gcash', 'external_reference' => ' GC-SAME '])->assertSessionHasErrors('external_reference');
        $this->assertSame(1, Receipt::count());
    }

    public function test_paying_more_than_is_left_needs_a_second_look_and_counts_pending_money(): void
    {
        $booking = $this->booking();
        $total = (int) $booking->fresh()->total_cents;
        $amount = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        $this->pay($booking, ['amount' => $amount($total + 100)])->assertSessionHasErrors(['amount', 'confirm_unusual']);
        $this->assertSame(0, Receipt::count());

        $this->pay($booking, ['amount' => $amount($total - 5000)])->assertSessionHasNoErrors();
        $this->pay($booking, ['amount' => $amount(6000)])->assertSessionHasErrors('amount');
        $this->pay($booking, ['amount' => $amount(5000)])->assertSessionHasNoErrors();
        $this->assertSame(2, Receipt::count());
    }

    public function test_an_old_date_needs_a_second_look_and_confirming_is_on_record(): void
    {
        $booking = $this->booking();
        $old = Local::now()->subYear()->format('Y-m-d\TH:i');

        $this->pay($booking, ['received_at' => $old])->assertSessionHasErrors('received_at');
        $this->pay($booking, ['received_at' => $old, 'confirm_unusual' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs',['action' => 'receipt.warning_confirmed']);
    }

    public function test_a_guest_who_owes_money_can_check_out_but_it_needs_a_reason(): void
    {
        $booking = $this->checkedIn();
        $props = $this->get("/admin/bookings/{$booking->id}")->original->getData()['page']['props'];
        $this->assertTrue($props['checkout']['unpaid']);
        $this->assertSame((int) $booking->total_cents, $props['checkout']['balance_cents']);

        $this->post("/admin/bookings/{$booking->id}/check-out")->assertSessionHasErrors('unpaid_reason');
        $this->assertSame(Booking::CHECKED_IN, $booking->fresh()->status);

        $this->post("/admin/bookings/{$booking->id}/check-out", ['unpaid_reason' => 'Paying by bank tomorrow'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(Booking::CHECKED_OUT, $booking->fresh()->status);

        $note = $booking->statusEvents()->latest('id')->value('note');
        $this->assertStringContainsString('unpaid', $note);
        $this->assertStringContainsString('Paying by bank tomorrow', $note);
    }

    public function test_the_balance_stays_visible_and_can_be_paid_after_check_out(): void
    {
        $booking = $this->checkedIn();
        $this->post("/admin/bookings/{$booking->id}/check-out", ['unpaid_reason' => 'Later']);

        $actions = $this->get('/admin')->original->getData()['page']['props']['actions'];
        $this->assertContains('guest(s) checked out with an unpaid balance', array_column($actions, 'label'));

        $total = number_format($booking->total_cents / 100, 2, '.', '');
        $this->pay($booking, ['amount' => $total])->assertSessionHasNoErrors();
        app(FinanceService::class)->review(Receipt::sole(), true);

        $this->assertSame(0, app(FinanceService::class)->balance($booking->fresh())['balance_cents']);
        $actions = $this->get('/admin')->original->getData()['page']['props']['actions'];
        $this->assertNotContains('guest(s) checked out with an unpaid balance', array_column($actions, 'label'));
    }

    public function test_a_fully_paid_guest_checks_out_with_no_reason_needed(): void
    {
        $booking = $this->checkedIn();
        $this->pay($booking, ['amount' => number_format($booking->total_cents / 100, 2, '.', '')]);
        app(FinanceService::class)->review(Receipt::sole(), true);

        $this->post("/admin/bookings/{$booking->id}/check-out")->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(Booking::CHECKED_OUT, $booking->fresh()->status);
    }

    public function test_an_airbnb_guest_needs_no_reason(): void
    {
        $booking = $this->checkedIn();
        $booking->update(['booking_source_id' => BookingSource::where('code', 'airbnb')->value('id')]);

        $this->post("/admin/bookings/{$booking->id}/check-out")->assertSessionHasNoErrors();
        $this->assertSame(Booking::CHECKED_OUT, $booking->fresh()->status);
    }

    public function test_clicking_check_out_twice_is_harmless(): void
    {
        $booking = $this->checkedIn();
        $this->post("/admin/bookings/{$booking->id}/check-out", ['unpaid_reason' => 'Later']);

        $this->post("/admin/bookings/{$booking->id}/check-out")->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_someone_who_cannot_see_payments_is_warned_without_the_amount(): void
    {
        $booking = $this->checkedIn();
        $staff = User::factory()->create();
        $staff->givePermissionTo(['bookings.view', 'bookings.manage']);
        $this->actingAs($staff);

        $checkout = $this->get("/admin/bookings/{$booking->id}")->original->getData()['page']['props']['checkout'];

        $this->assertTrue($checkout['unpaid']);
        $this->assertNull($checkout['balance_cents']);
    }

    public function test_the_booking_page_can_undo_a_wrong_verified_payment(): void
    {
        $booking = $this->booking();
        $this->pay($booking);
        $receipt = Receipt::sole();
        app(FinanceService::class)->review($receipt, true);

        $this->post("/admin/receipts/{$receipt->id}/reverse", ['reason' => 'Wrong booking'])->assertSessionHasNoErrors();

        $rows = collect($this->get("/admin/bookings/{$booking->id}")->original->getData()['page']['props']['receipts']);
        $this->assertTrue($rows->firstWhere('id', $receipt->id)['reversed']);
        $this->assertSame(-10000, $rows->firstWhere('reversed', false)['amount_cents']);
        $this->assertSame(0, app(FinanceService::class)->balance($booking->fresh())['verified_cents']);
    }
}
