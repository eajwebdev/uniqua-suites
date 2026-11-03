<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Domain\Pricing\PricingPolicyPending;
use App\Domain\Pricing\PricingService;
use App\Models\RateRule;
use App\Support\Settings;
use Tests\DomainTestCase;

class PricingRulesTest extends DomainTestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(PricingService::class);
    }

    public function test_all_nine_rooms_match_the_room_list(): void
    {
        $expected = [
            'rio' => ['T3, 6H', 2, 2, 160000, 180000], 'venice' => ['1410', 3, 4, 170000, 190000], 'bali' => ['2520', 4, 4, 200000, 220000],
            'tokyo' => ['1725', 4, 5, 220000, 240000], 'roma' => ['1916', 4, 4, 210000, 230000], 'milan' => ['0918', 4, 6, 250000, 270000],
            'berlin' => ['T3, 14Q', 4, 6, 250000, 270000], 'paris' => ['0524', 6, 8, 350000, 400000], 'new-york' => ['2224', 6, 10, 400000, 450000],
        ];
        foreach ($expected as $slug => [$unit, $included, $max, $weekday, $weekend]) {
            $room = $this->room($slug);
            $rule = $room->rateRules()->first();
            $this->assertSame([$unit, $included, $max, $weekday, $weekend], [$room->unit_code, $room->included_guests, $room->max_guests, (int) $rule->weekday_cents, (int) $rule->weekend_cents], $slug);
        }
    }

    public function test_venice_weekday_plus_weekend_is_3600(): void
    {
        // Fri 2031-03-07 (weekday under this mapping) + Sat 2031-03-08 (weekend)
        $quote = $this->pricing->quote($this->room('venice'), '2031-03-07', '2031-03-09', 3);
        $this->assertSame([170000, 190000], array_column($quote['nights'], 'cents'));
        $this->assertSame(360000, $quote['total_cents']);
    }

    public function test_weekend_definition_is_never_assumed(): void
    {
        Settings::set('pricing.weekend_days', null);
        $this->expectException(PricingPolicyPending::class);
        $this->pricing->quote($this->room('venice'), '2031-03-07', '2031-03-09', 2);
    }

    public function test_changing_the_weekend_mapping_moves_the_rate(): void
    {
        Settings::set('pricing.weekend_days', [5]);
        $this->assertSame([190000, 170000], array_column($this->pricing->quote($this->room('venice'), '2031-03-07', '2031-03-09', 2)['nights'], 'cents'));
    }

    public function test_early_check_in_fee_per_started_hour(): void
    {
        foreach (['13:00' => 20000, '12:00' => 40000, '11:00' => 60000, '10:00' => 80000, '13:30' => 20000, '14:00' => 0, '15:00' => 0] as $time => $cents) {
            $this->assertSame($cents, $this->pricing->earlyFee($time)['cents'], $time);
        }

        $quote = $this->pricing->quote($this->room('venice'), '2031-03-07', '2031-03-09', 3, ['early_time' => '12:00']);
        $this->assertSame(400000, $quote['total_cents']);
        $this->assertStringContainsString('subject to availability', implode(' ', $quote['warnings']));
    }

    public function test_more_guests_than_venice_allows_is_rejected(): void
    {
        $this->expectException(RoomConflict::class);
        $this->pricing->quote($this->room('venice'), '2031-03-07', '2031-03-09', 5);
    }

    public function test_extra_guest_fee_stays_off_until_the_billing_unit_is_approved(): void
    {
        $pending = $this->pricing->quote($this->room('venice'), '2031-03-03', '2031-03-05', 4);
        $this->assertSame(340000, $pending['total_cents']);
        $this->assertStringContainsString('not applied', implode(' ', $pending['warnings']));

        Settings::set('pricing.extra_guest_unit', 'per_stay');
        $this->assertSame(360000, $this->pricing->quote($this->room('venice'), '2031-03-03', '2031-03-05', 4)['total_cents']);

        Settings::set('pricing.extra_guest_unit', 'per_night');
        $this->assertSame(380000, $this->pricing->quote($this->room('venice'), '2031-03-03', '2031-03-05', 4)['total_cents']);
    }

    public function test_effective_dated_rates_price_each_night_from_its_own_date(): void
    {
        $room = $this->room('venice');
        RateRule::create(['room_id' => $room->id, 'effective_from' => '2031-03-05', 'weekday_cents' => 180000, 'weekend_cents' => 200000]);

        $this->assertSame([170000, 180000], array_column($this->pricing->quote($room, '2031-03-04', '2031-03-06', 2)['nights'], 'cents'));
    }

    public function test_confirmed_booking_keeps_its_price_when_future_rates_change(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->assertSame(340000, (int) $booking->fresh()->total_cents);

        $this->room('venice')->rateRules()->update(['weekday_cents' => 999900]);

        $this->assertSame(340000, (int) $booking->fresh()->total_cents);
        $this->assertSame(340000, (int) $booking->snapshots()->first()->total_cents);
        $this->assertSame(170000, (int) $booking->nights()->where('is_current', true)->first()->rate_cents);
    }

    public function test_amendment_writes_a_new_price_version_and_keeps_the_old_one(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $booking = $this->bookings->amend($booking, ['check_out' => '2031-03-06', 'reason' => 'Extended']);

        $this->assertSame(2, (int) $booking->pricing_version);
        $this->assertSame(510000, (int) $booking->total_cents);
        $this->assertSame(2, $booking->snapshots()->count());
        $this->assertSame(340000, (int) $booking->snapshots()->where('version', 1)->first()->total_cents);
    }
}
