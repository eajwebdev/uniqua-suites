<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Local;
use Carbon\CarbonImmutable;
use Tests\DomainTestCase;

class DashboardAnalyticsTest extends DomainTestCase
{
    private function props(string $url = '/admin'): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function days(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function boardRoom(array $props, string $name): array
    {
        return collect($props['board'])->firstWhere('name', $name);
    }

    public function test_the_room_board_says_what_each_room_is_doing_and_who_is_next(): void
    {
        $staying = $this->confirmed('venice', $this->days(0), $this->days(2));
        $this->bookings->checkIn($staying);
        $this->confirmed('bali', $this->days(0), $this->days(1));
        $this->confirmed('tokyo', $this->days(3), $this->days(5));
        RoomBlock::create(['room_id' => $this->room('milan')->id, 'start_date' => $this->days(0), 'end_date' => $this->days(1), 'kind' => 'repair', 'reason' => 'Synthetic']);
        $this->room('paris')->update(['operational_status' => 'maintenance']);

        $props = $this->props();

        $venice = $this->boardRoom($props, 'VENICE');
        $this->assertSame('occupied', $venice['state']);
        $this->assertSame($staying->id, $venice['stay']['booking_id']);
        $this->assertSame($this->days(2), $venice['stay']['check_out']);

        $this->assertSame('arriving', $this->boardRoom($props, 'BALI')['state']);
        $this->assertSame('vacant', $this->boardRoom($props, 'TOKYO')['state']);
        $this->assertSame($this->days(3), $this->boardRoom($props, 'TOKYO')['next']['check_in']);
        $this->assertSame('blocked', $this->boardRoom($props, 'MILAN')['state']);
        $this->assertSame('maintenance', $this->boardRoom($props, 'PARIS')['state']);
        $this->assertNull($this->boardRoom($props, 'RIO')['next']);
    }

    public function test_the_outlook_counts_rooms_for_the_next_14_days_and_only_certain_stays(): void
    {
        $this->confirmed('venice', $this->days(1), $this->days(3));
        $this->confirmed('bali', $this->days(2), $this->days(3));
        $this->inquiry('tokyo', $this->days(2), $this->days(3));

        $forecast = $this->props()['forecast'];

        $this->assertCount(14, $forecast);
        $this->assertSame($this->days(0), $forecast[0]['date']);
        $this->assertSame($this->days(13), $forecast[13]['date']);
        $this->assertSame([0, 1, 2, 0], array_column(array_slice($forecast, 0, 4), 'rooms'));
    }

    public function test_this_month_is_compared_with_the_same_number_of_days_last_month(): void
    {
        $now = Local::now();
        $last = $now->subMonthNoOverflow()->startOfMonth();
        $period = $this->props()['period'];

        $this->assertSame('month', $period['key']);
        $this->assertSame($now->startOfMonth()->toDateString(), $period['from']);
        $this->assertSame($now->toDateString(), $period['to']);
        $this->assertSame($last->toDateString(), $period['prior_from']);
        $expected = min($last->addDays($now->day - 1)->toDateString(), $last->endOfMonth()->toDateString());
        $this->assertSame($expected, $period['prior_to']);
    }

    public function test_other_periods_are_compared_with_one_of_the_same_length(): void
    {
        $now = Local::now();

        $ninety = $this->props('/admin?period=90d')['period'];
        $this->assertSame(89, (int) CarbonImmutable::parse($ninety['from'])->diffInDays($ninety['to']));
        $this->assertSame(89, (int) CarbonImmutable::parse($ninety['prior_from'])->diffInDays($ninety['prior_to']));
        $this->assertSame(CarbonImmutable::parse($ninety['from'])->subDay()->toDateString(), $ninety['prior_to']);

        $lastMonth = $this->props('/admin?period=last_month')['period'];
        $this->assertSame($now->subMonthNoOverflow()->startOfMonth()->toDateString(), $lastMonth['from']);
        $this->assertSame($now->subMonthNoOverflow()->endOfMonth()->toDateString(), $lastMonth['to']);
    }

    public function test_an_unknown_period_is_refused(): void
    {
        $this->get('/admin?period=forever')->assertSessionHasErrors('period');
    }

    public function test_the_period_changes_the_figures(): void
    {
        $now = Local::now();
        $inPrior = $now->subMonthNoOverflow()->startOfMonth()->addDays(2)->toDateString();
        $booking = $this->confirmed('venice', $inPrior, CarbonImmutable::parse($inPrior)->addDay()->toDateString());
        $rate = (int) $booking->nights()->where('is_current', true)->sum('rate_cents');

        $thisMonth = $this->props()['money'];
        $lastMonth = $this->props('/admin?period=last_month')['money'];

        $this->assertSame(0, $thisMonth['earned_cents']);
        $this->assertSame($rate, $lastMonth['earned_cents']);
        $this->assertGreaterThan(0, $rate);
    }

    public function test_every_room_appears_in_performance_including_idle_ones(): void
    {
        $this->confirmed('venice', $this->days(0), $this->days(1));

        $rooms = collect($this->props()['performance']['rooms']);

        $this->assertCount(9, $rooms);
        $this->assertSame('VENICE', $rooms->first()['name'], 'The best earner comes first.');
        $this->assertSame(1, $rooms->first()['nights']);
        $this->assertSame(0, $rooms->firstWhere('name', 'RIO')['nights']);
    }

    public function test_staff_without_finance_access_see_the_board_but_no_money(): void
    {
        $this->confirmed('venice', $this->days(0), $this->days(1));
        $reception = User::factory()->create();
        $reception->assignRole('Booking / Reception');
        $this->actingAs($reception);

        $props = $this->props();

        $this->assertNull($props['money']);
        $this->assertNull($props['performance']);
        $this->assertCount(9, $props['board']);
        $this->assertSame(1, $props['forecast'][0]['rooms']);
        $this->assertStringNotContainsString('rate_cents', json_encode($props['board']));
    }

    public function test_the_calendar_knows_the_weekend_nights_for_shading(): void
    {
        $this->assertSame([6, 7], $this->get('/admin/calendar')->assertOk()->original->getData()['page']['props']['weekend_days']);
    }
}
