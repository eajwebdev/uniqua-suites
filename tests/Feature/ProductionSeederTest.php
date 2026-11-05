<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config([
            'app.env' => 'production',
            'uniqa.bootstrap_owner.name' => 'Production Owner',
            'uniqa.bootstrap_owner.email' => 'owner@example.com',
        ]);
        Storage::fake('public');
    }

    public function test_a_clean_production_seed_creates_the_catalog_and_one_owner_only(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(9, Room::count());
        $this->assertSame(0, Room::where('is_published', true)->count());
        $this->assertSame(1, User::count());
        $this->assertTrue(User::where('email', 'owner@example.com')->firstOrFail()->hasRole('Owner'));

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(9, Room::count());
        $this->assertSame(1, User::count());
    }

    public function test_demo_accounts_and_data_are_refused_in_production(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        try {
            app(DemoSeeder::class)->run();
            $this->fail('The demo seeder should be blocked in production.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@uniqa-suites.test']);
        $this->assertDatabaseCount('bookings', 0);
    }
}
