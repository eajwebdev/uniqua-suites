<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Support\Local;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

/** Renters see the exact times a camera cannot be booked, and the live check uses their times. */
class GrabCamBusyTimesTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    private function day(int $n): string
    {
        return Local::now()->addDays($n)->toDateString();
    }

    private function rent(string $unit, string $in, string $pickup, string $out, string $return): Booking
    {
        return $this->bookings->confirm($this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => 'Renter '.uniqid()])->id,
            'room_ids' => [$this->room($unit)->id], 'booking_source_id' => $this->source(),
            'check_in' => $in, 'pickup_time' => $pickup, 'check_out' => $out, 'return_time' => $return, 'adults' => 1,
        ]));
    }

    private function model(string $slug): array
    {
        auth()->logout();
        $models = collect($this->get('/grabcam')->assertOk()->original->getData()['page']['props']['models'])->keyBy('slug');
        $this->actingAs($this->owner);

        return $models[$slug];
    }

    public function test_page_shows_booked_times_without_renter_details(): void
    {
        $this->rent('dji-nano', $this->day(10), '10:00', $this->day(11), '10:00');

        $nano = $this->model('dji-nano');
        $this->assertSame([['from' => $this->day(10).' 10:00', 'to' => $this->day(11).' 10:00']], $nano['busy']);
        $this->assertSame([], $nano['unavailable'], 'no day is booked from midnight to midnight');
        $this->assertStringNotContainsString('Renter', json_encode($nano));
    }

    public function test_a_day_with_no_free_minute_is_crossed_out(): void
    {
        $this->rent('dji-nano', $this->day(10), '10:00', $this->day(13), '10:00');

        $this->assertSame([['from' => $this->day(11), 'to' => $this->day(13)]], $this->model('dji-nano')['unavailable']);
    }

    public function test_a_model_is_busy_only_while_every_unit_is_out(): void
    {
        $this->rent('canon-g7x-mark-ii-n', $this->day(10), '10:00', $this->day(11), '10:00');
        $this->assertSame([], $this->model('canon-g7x-mark-ii')['busy']);

        $this->rent('canon-g7x-mark-ii-o', $this->day(10), '14:00', $this->day(11), '14:00');
        $this->assertSame([['from' => $this->day(10).' 14:00', 'to' => $this->day(11).' 10:00']], $this->model('canon-g7x-mark-ii')['busy']);
    }

    public function test_live_check_uses_the_renters_times(): void
    {
        $this->rent('dji-nano', $this->day(10), '10:00', $this->day(11), '10:00');
        auth()->logout();
        $ask = fn ($in, $pickup, $out, $return) => $this->getJson("/grabcam/dji-nano/check-dates?check_in={$in}&pickup_time={$pickup}&check_out={$out}&return_time={$return}")->assertOk();

        $ask($this->day(11), '10:00', $this->day(12), '10:00')->assertJson(['available' => true, 'days' => 1, 'estimate_cents' => 40000]);
        $taken = $ask($this->day(11), '09:00', $this->day(12), '09:00')->assertJson(['available' => false]);
        $this->assertStringContainsString('10:00 AM', $taken->json('message'), 'says which time is booked');
        $ask($this->day(9), '08:00', $this->day(9), '17:00')->assertJson(['available' => true, 'days' => 1]);
        $ask($this->day(12), '10:00', $this->day(12), '09:00')->assertJson(['available' => false]);
    }
}
