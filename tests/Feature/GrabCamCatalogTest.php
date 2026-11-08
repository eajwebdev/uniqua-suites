<?php

namespace Tests\Feature;

use App\Models\RentalRateTier;
use App\Models\Room;
use App\Models\RoomType;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

class GrabCamCatalogTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    public function test_seeder_creates_nine_models_and_ten_units(): void
    {
        $this->assertSame(10, Room::cameras()->count());
        $this->assertSame(9, RoomType::has('rateTiers')->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(GrabCamSeeder::class);

        $this->assertSame(10, Room::cameras()->count());
        $this->assertSame(27, RentalRateTier::count());
    }

    public function test_g7x_mark_ii_tiers_match_rate_card(): void
    {
        $tiers = RoomType::where('name', 'Canon G7X Mark II')->firstOrFail()->rateTiers()->orderBy('min_days')->get()
            ->map(fn ($t) => [$t->min_days, $t->max_days, (int) $t->daily_cents])->all();

        $this->assertSame([[1, 2, 50000], [3, 5, 45000], [6, null, 40000]], $tiers);
        $this->assertSame(['N', 'O'], Room::cameras()->where('slug', 'like', 'canon-g7x-mark-ii-%')->orderBy('unit_code')->pluck('unit_code')->all());
    }

    public function test_suites_scope_excludes_cameras(): void
    {
        $this->assertSame(9, Room::suites()->count());
        $this->assertFalse($this->room('venice')->isCamera());
        $this->assertTrue($this->room('canon-g7x-mark-ii-n')->isCamera());
    }
}
