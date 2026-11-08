<?php

namespace Tests\Feature;

use App\Domain\Pricing\PricingService;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

class CameraPricingTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    private function quote(int $days, string $slug = 'canon-g7x-mark-ii-n'): array
    {
        $from = '2031-03-03';

        return app(PricingService::class)->quote($this->room($slug), $from, CarbonImmutable::parse($from)->addDays($days)->toDateString(), 1);
    }

    public function test_rental_length_picks_the_rate_card_tier_and_eight_days_get_one_free(): void
    {
        $expected = [1 => 50000, 2 => 100000, 3 => 135000, 5 => 225000, 6 => 240000, 7 => 280000, 8 => 280000, 10 => 360000];

        foreach ($expected as $days => $cents) {
            $this->assertSame($cents, $this->quote($days)['total_cents'], "{$days} day(s)");
        }
    }

    public function test_free_day_is_the_last_day_at_zero(): void
    {
        $nights = $this->quote(8)['nights'];

        $this->assertCount(8, $nights);
        $this->assertSame(0, end($nights)['cents']);
        $this->assertSame('Free day (8+ days)', end($nights)['label']);
        $this->assertSame(40000, $nights[0]['cents']);
    }

    public function test_camera_quote_has_no_tax_line(): void
    {
        TaxRule::create(['name' => 'Synthetic Tax', 'rate_basis_points' => 1200, 'effective_from' => '2030-01-01']);

        $types = array_column($this->quote(3)['lines'], 'type');

        $this->assertNotContains('tax', $types);
        $this->assertSame(['accommodation'], array_values(array_unique($types)));
    }
}
