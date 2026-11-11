<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceService;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Local;
use Tests\DomainTestCase;

class BookingListBalanceTest extends DomainTestCase
{
    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function rows(string $url): array
    {
        return $this->props($url)['bookings']['data'];
    }

    private function receipt(int $bookingId, int $cents, string $status): void
    {
        Receipt::create([
            'reference' => 'RC-T-'.uniqid(), 'booking_id' => $bookingId, 'received_at' => now(), 'receipt_date' => Local::today(),
            'amount_cents' => $cents, 'method' => 'cash', 'verification_status' => $status,
        ]);
    }

    public function test_list_shows_the_same_balance_as_the_booking_page(): void
    {
        $owing = $this->confirmed('venice');
        $paid = $this->confirmed('berlin');
        $this->inquiry('venice', '2031-04-03', '2031-04-05');

        $finance = app(FinanceService::class);
        $this->receipt($owing->id, 1000_00, 'verified');
        $this->receipt($owing->id, 500_00, 'pending');
        $this->receipt($paid->id, $finance->balance($paid)['charges_cents'], 'verified');

        $byRef = collect($this->rows('/admin/bookings'))->keyBy('reference');
        $this->assertSame($finance->balance($owing)['balance_cents'], $byRef[$owing->reference]['balance_cents']);
        $this->assertSame(1000_00, $byRef[$owing->reference]['paid_cents']);
        $this->assertSame(500_00, $byRef[$owing->reference]['pending_cents']);
        $this->assertSame(0, $byRef[$paid->reference]['balance_cents']);

        $unpaid = array_column($this->rows('/admin/bookings?view=unpaid'), 'reference');
        $this->assertSame([$owing->reference], $unpaid);
        $this->assertSame([$owing->reference], array_column($this->rows('/admin/bookings?payment=review'), 'reference'));
        $this->assertSame([$paid->reference], array_column($this->rows('/admin/bookings?payment=paid'), 'reference'));

        $props = $this->props('/admin/bookings');
        $this->assertSame(1, $props['summary']['unpaid_count']);
        $this->assertSame($finance->balance($owing)['balance_cents'], $props['summary']['unpaid_cents']);
        $this->assertSame(3, $props['counts']['all']);
        $this->assertSame(3, $props['counts']['open']);
    }

    public function test_sort_by_balance_and_date_range(): void
    {
        $small = $this->confirmed('venice');
        $big = $this->confirmed('berlin', '2031-05-01', '2031-05-06');
        $this->receipt($small->id, 100_00, 'verified');

        $this->assertSame([$big->reference, $small->reference], array_column($this->rows('/admin/bookings?sort=balance'), 'reference'));
        $this->assertSame([$big->reference], array_column($this->rows('/admin/bookings?from=2031-04-01&to=2031-05-31'), 'reference'));
    }

    public function test_staff_without_payment_access_see_no_amounts_or_money_filters(): void
    {
        $this->confirmed('venice');
        $desk = User::factory()->create();
        $desk->givePermissionTo('bookings.view');
        $this->actingAs($desk);

        $props = $this->props('/admin/bookings?view=unpaid&payment=unpaid&sort=balance');
        $this->assertFalse($props['canPay']);
        $this->assertNull($props['summary']);
        $this->assertArrayNotHasKey('unpaid', $props['counts']);
        $this->assertNull($props['bookings']['data'][0]['balance_cents']);
        $this->assertCount(1, $props['bookings']['data']);
    }
}
