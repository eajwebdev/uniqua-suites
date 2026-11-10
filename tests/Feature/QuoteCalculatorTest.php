<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\RateRule;
use App\Support\Settings;
use Tests\DomainTestCase;

/** The owner's own calculator is the reference: Milan, four nights, six guests, three parking nights. */
class QuoteCalculatorTest extends DomainTestCase
{
    private function milanAtCalculatorPrices(): void
    {
        RateRule::create(['room_id' => $this->room('milan')->id, 'effective_from' => '2031-01-01', 'weekday_cents' => 260000, 'weekend_cents' => 280000]);
        Settings::set('pricing.weekend_days', [5, 6]);
        Settings::set('pricing.extra_guest_unit', 'per_night');
        Settings::set('pricing.standard_check_out', '11:00');
    }

    /** Thursday to Monday: two weekday nights and two weekend nights, like the calculator's Nov 5 to 9. */
    private function query(array $extra = []): array
    {
        return $extra + ['room_id' => $this->room('milan')->id, 'check_in' => '2031-03-06', 'check_out' => '2031-03-10', 'guests' => 6, 'parking_nights' => 3, 'parking_rate' => '300', 'guest_name' => 'Test Guest'];
    }

    private function preview(array $extra = []): array
    {
        return $this->get('/admin/quotes/create?'.http_build_query($this->query($extra)))->assertOk()->original->getData()['page']['props']['preview'];
    }

    public function test_it_reaches_the_same_total_as_the_owners_calculator(): void
    {
        $this->milanAtCalculatorPrices();

        $preview = $this->preview();

        $this->assertSame(1330000, $preview['total_cents']);
        $this->assertSame(50, $preview['downpayment_percent']);
        $this->assertSame(665000, $preview['downpayment_cents']);
        $this->assertSame(665000, $preview['balance_cents']);
    }

    public function test_the_copied_message_matches_the_calculators_format(): void
    {
        $this->milanAtCalculatorPrices();

        $text = $this->preview()['copyText'];

        foreach ([
            'UNIQA SUITES CDO BOOKING QUOTATION',
            'Room: MILAN',
            'Check-in: Mar 6, 2031 (2:00 PM)',
            'Check-out: Mar 10, 2031 (11:00 AM)',
            'Duration: 4 night(s)',
            'Guests: 6',
            'RATE BREAKDOWN',
            'Weekday (2 × ₱2,600): ₱5,200',
            'Weekend (2 × ₱2,800): ₱5,600',
            'Additional guests: ₱1,600',
            'Parking (3 nights × ₱300): ₱900',
            'TOTAL: ₱13,300',
            '50% Downpayment: ₱6,650',
            'Balance upon check-in: ₱6,650',
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }
    }

    public function test_charges_that_do_not_apply_stay_out_of_the_message(): void
    {
        $this->milanAtCalculatorPrices();

        $text = $this->preview(['guests' => 4, 'parking_nights' => '', 'discount' => ''])['copyText'];

        foreach (['Additional guests', 'Parking', 'Discount', 'Early'] as $absent) {
            $this->assertStringNotContainsString($absent, $text);
        }
    }

    public function test_the_percentage_comes_from_settings_and_rounds_to_the_centavo(): void
    {
        $this->milanAtCalculatorPrices();
        Settings::set('finance.downpayment_percent', 30);

        $preview = $this->preview();
        $this->assertSame(399000, $preview['downpayment_cents']);
        $this->assertSame(931000, $preview['balance_cents']);
        $this->assertStringContainsString('30% Downpayment: ₱3,990', $preview['copyText']);

        Settings::set('finance.downpayment_percent', 33);
        $odd = $this->preview(['parking_rate' => '333.33']);
        $this->assertSame($odd['total_cents'], $odd['downpayment_cents'] + $odd['balance_cents'], 'the two parts always add up to the total');
    }

    public function test_setting_it_to_zero_removes_the_downpayment(): void
    {
        $this->milanAtCalculatorPrices();
        Settings::set('finance.downpayment_percent', 0);

        $preview = $this->preview();

        $this->assertNull($preview['downpayment_percent']);
        $this->assertStringNotContainsString('Downpayment', $preview['copyText']);
        $this->assertStringNotContainsString('Balance', $preview['copyText']);
    }

    public function test_airbnb_quotes_use_the_same_downpayment_as_other_sources(): void
    {
        $this->milanAtCalculatorPrices();

        $preview = $this->preview(['source_id' => $this->source('airbnb')]);

        $this->assertSame(50, $preview['downpayment_percent']);
        $this->assertStringContainsString('Downpayment', $preview['copyText']);
    }

    public function test_a_saved_quote_keeps_the_percentage_it_was_issued_with(): void
    {
        $this->milanAtCalculatorPrices();
        $this->post('/admin/quotes', $this->query(['guest_name' => 'Synthetic']))->assertSessionHasNoErrors();
        $quote = Quote::sole();
        $this->assertSame(50, (int) $quote->downpayment_percent);

        Settings::set('finance.downpayment_percent', 20);

        $props = $this->get("/admin/quotes/{$quote->id}")->assertOk()->original->getData()['page']['props'];
        $this->assertSame(665000, $props['quote']['downpayment_cents']);
        $this->assertStringContainsString('50% Downpayment: ₱6,650', $props['copyText']);
        $this->assertStringContainsString('Hi Synthetic', $props['copyText']);
        $this->get("/admin/quotes/{$quote->id}/pdf")->assertOk();
    }

    public function test_parking_cannot_run_longer_than_the_stay(): void
    {
        $this->milanAtCalculatorPrices();

        $this->assertStringContainsString('Parking nights', $this->preview(['parking_nights' => 5])['error']);
        $this->post('/admin/quotes', $this->query(['parking_nights' => 5]))->assertSessionHas('error');
        $this->assertSame(0, Quote::count());
    }

    public function test_sensitive_quote_inputs_are_rejected_by_the_pricing_engine(): void
    {
        $this->milanAtCalculatorPrices();

        $this->assertStringContainsString('parking rate', $this->preview(['parking_nights' => 2, 'parking_rate' => '0'])['error']);
        $this->assertStringContainsString('must be before', $this->preview(['early_time' => '14:00'])['error']);
        $this->assertStringContainsString('discount cannot be more', $this->preview(['discount' => '999999'])['error']);
    }

    public function test_a_pricing_input_error_does_not_make_free_rooms_look_unavailable(): void
    {
        $this->milanAtCalculatorPrices();

        $props = $this->get('/admin/quotes/create?'.http_build_query($this->query(['early_time' => '14:00', 'room_id' => '', 'guests' => 2])))
            ->assertOk()->original->getData()['page']['props'];

        $this->assertCount(9, $props['vacancy']['vacant']);
        $this->assertCount(0, $props['vacancy']['unavailable']);
        $this->assertStringContainsString('must be before', $props['vacancy']['pricing_pending']);
    }

    public function test_updating_a_quote_keeps_its_parking_nights_and_rate(): void
    {
        $this->milanAtCalculatorPrices();
        $this->post('/admin/quotes', $this->query());

        $parking = Quote::sole()->items()->where('type', 'parking')->sole();

        $this->assertSame(3, (int) $parking->quantity);
        $this->assertSame(30000, (int) $parking->unit_cents);
        $this->assertSame(90000, (int) $parking->amount_cents);
    }

    public function test_settings_save_the_downpayment_and_parking_rate(): void
    {
        $base = ['extra_guest' => '200.00', 'early_fee' => '200.00', 'standard_check_in' => '14:00', 'hold_hours' => 24, 'laundry_auto' => true, 'require_verification' => false, 'turnover_due_hours' => 4, 'second_reviewer' => false, 'require_two_factor' => false];

        $this->post('/admin/settings', $base + ['downpayment_percent' => 40, 'parking_rate' => '350.00'])->assertSessionHasNoErrors();
        $this->assertSame(40, Settings::get('finance.downpayment_percent'));
        $this->assertSame(35000, Settings::get('pricing.parking_night_cents'));

        $this->post('/admin/settings', $base + ['downpayment_percent' => 101])->assertSessionHasErrors('downpayment_percent');
        $this->assertSame(40, Settings::get('finance.downpayment_percent'));
    }

    public function test_the_calculator_offers_the_preset_parking_rate(): void
    {
        $props = $this->get('/admin/quotes/create')->assertOk()->original->getData()['page']['props'];

        $this->assertSame('300.00', $props['parkingRate']);
    }

    public function test_the_new_message_format_survives_a_template_someone_rewrote(): void
    {
        $this->milanAtCalculatorPrices();
        \App\Models\MessageTemplate::where('key', 'quote')->update(['body' => 'Hello {{guest}}, total is {{total}}.']);

        $text = $this->preview()['copyText'];

        $this->assertStringContainsString('Hello {{guest}}, total is ₱13,300.', $text);
        $this->assertStringContainsString('Weekday (2 × ₱2,600): ₱5,200', $text);
        $this->assertStringContainsString('50% Downpayment: ₱6,650', $text);
    }
}
