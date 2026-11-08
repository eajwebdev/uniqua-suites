<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ExternalSettlement;
use App\Models\Receipt;
use App\Models\RentalRateTier;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use Database\Seeders\GrabCamSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\DomainTestCase;

/** The admin modules that complete Grab Cam: camera edit, new units and models, effective-dated free day, live estimate, business-aware links, reconciliation and alerts. */
class CameraModulesTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrabCamSeeder::class);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function cameras(): void
    {
        $this->post('/admin/category', ['category' => 'cameras']);
    }

    // Camera edit page

    public function test_a_camera_opens_its_own_edit_page_and_saves_camera_fields(): void
    {
        $nano = $this->room('dji-nano');
        $page = $this->get("/admin/rooms/{$nano->id}/edit")->assertOk()->original->getData()['page'];
        $this->assertSame('Admin/CameraEdit', $page['component']);
        $this->assertSame([40000, 35000, 30000], array_column($page['props']['rates'], 'daily_cents'));

        $this->post("/admin/rooms/{$nano->id}", [
            'name' => 'DJI Nano', 'unit_code' => 'NANO-1', 'specs' => 'With 2 batteries', 'description' => 'Tiny 4K camera.',
            'min_nights' => 2, 'max_advance_days' => '', 'operational_status' => 'maintenance', 'operational_notes' => 'Lens scratched',
        ])->assertSessionHasNoErrors();

        $nano->refresh();
        $this->assertSame(['NANO-1', 'With 2 batteries', 'maintenance', 2], [$nano->unit_code, $nano->specs, $nano->operational_status, (int) $nano->min_nights]);
        $this->assertSame(1, (int) $nano->max_guests, 'Suite fields stay fixed for cameras.');
    }

    public function test_camera_unit_codes_stay_unique(): void
    {
        $n = $this->room('canon-g7x-mark-ii-n');

        $this->post("/admin/rooms/{$n->id}", ['name' => $n->name, 'unit_code' => 'O', 'operational_status' => 'active'])->assertSessionHasErrors('unit_code');
    }

    public function test_a_suite_still_saves_with_the_suite_form(): void
    {
        $page = $this->get('/admin/rooms/'.$this->room('venice')->id.'/edit')->original->getData()['page'];

        $this->assertSame('Admin/RoomEdit', $page['component']);
    }

    // New units and models

    public function test_staff_add_a_unit_to_an_existing_model(): void
    {
        $type = RoomType::where('name', 'DJI Nano')->first();

        $this->post('/admin/cameras', ['room_type_id' => $type->id, 'unit_code' => 'B', 'specs' => 'Second unit'])->assertSessionHasNoErrors();

        $unit = Room::cameras()->where('unit_code', 'B')->firstOrFail();
        $this->assertSame([$type->id, 'DJI Nano (B)', true, 'approved'], [$unit->room_type_id, $unit->name, $unit->is_published, $unit->catalog_status]);
        $this->assertSame(1, (int) $unit->max_guests);
    }

    public function test_staff_add_a_new_model_with_its_rates_and_first_unit(): void
    {
        $this->post('/admin/camera-models', ['name' => 'Sony ZV-1', 'brand' => 'Sony', 'specs' => 'Vlog kit', 'one_two' => '600', 'three_five' => '550', 'six_plus' => '500', 'free_day_from' => 8])
            ->assertSessionHasNoErrors();

        $type = RoomType::where('name', 'Sony ZV-1')->firstOrFail();
        $this->assertSame('Sony', $type->brand);
        $this->assertSame(1, Room::cameras()->where('room_type_id', $type->id)->count());
        $this->assertSame(165000, (int) $this->confirmed('sony-zv-1', '2031-03-03', '2031-03-06', adults: 1)->fresh()->total_cents);

        $this->post('/admin/camera-models', ['name' => 'Sony ZV-1', 'brand' => 'Sony', 'one_two' => '1', 'three_five' => '1', 'six_plus' => '1', 'free_day_from' => 8])->assertSessionHasErrors('name');
    }

    // Free day with a start date

    public function test_changing_the_free_day_rule_never_changes_rentals_before_its_start_date(): void
    {
        $early = $this->confirmed('dji-nano', '2031-03-01', '2031-03-09', adults: 1); // 8 days: 7 paid at the 6+ rate
        $this->assertSame(210000, (int) $early->fresh()->total_cents);

        $this->post('/admin/camera-rates/free-day', ['threshold' => 0, 'effective_from' => '2031-04-01'])->assertSessionHasNoErrors();

        $this->bookings->amend($early->fresh(), ['special_requests' => 'Extra SD card', 'reason' => 'note']);
        $this->assertSame(210000, (int) $early->fresh()->total_cents, 'Repricing an earlier rental keeps its free day.');
        $this->assertSame(240000, (int) $this->confirmed('dji-nano', '2031-04-01', '2031-04-09', adults: 1)->fresh()->total_cents, 'From April there is no free day.');
        $this->assertSame(0, RentalRateTier::where('effective_from', '2031-04-01')->where('free_day_from', '>', 0)->count());

        $this->post('/admin/camera-rates/free-day', ['threshold' => 5, 'effective_from' => Local::now()->subDay()->toDateString()])->assertSessionHasErrors('effective_from');
    }

    // Public estimate comes from the server

    public function test_grabcam_check_dates_returns_the_real_price_for_those_days(): void
    {
        auth()->logout();

        $this->getJson('/grabcam/dji-nano/check-dates?check_in=2031-03-01&check_out=2031-03-09')->assertOk()
            ->assertJson(['available' => true, 'days' => 8, 'estimate_cents' => 210000, 'daily_cents' => 30000, 'free_day' => true]);
    }

    // Links across businesses

    public function test_opening_a_booking_of_the_other_business_switches_to_it(): void
    {
        $rental = $this->confirmed('dji-nano', adults: 1);

        $this->assertSame('cameras', $this->props("/admin/bookings/{$rental->id}")['category']);
        $this->assertSame('cameras', session('admin_category'));
    }

    public function test_a_business_link_switches_the_admin_to_it(): void
    {
        $this->assertSame('cameras', $this->props('/admin/calendar?business=cameras')['category']);
        $this->assertSame('cameras', $this->props('/admin/calendar')['category']);
        $this->assertSame('cameras', $this->props('/admin/calendar?business=boats')['category'], 'An unknown value is ignored.');
    }

    // Reconciliation and alerts

    public function test_reconciliation_lists_only_the_selected_business(): void
    {
        $suite = $this->confirmed('venice');
        $camera = $this->confirmed('dji-nano', adults: 1);
        foreach ([[$suite, 'SUITE-PENDING'], [$camera, 'CAM-PENDING']] as [$b, $ref]) {
            Receipt::create(['booking_id' => $b->id, 'amount_cents' => 1000, 'method' => 'gcash', 'reference' => $ref, 'received_at' => now(), 'receipt_date' => Local::today(), 'verification_status' => 'pending']);
        }

        $details = fn () => implode(' | ', array_column(collect($this->props('/admin/reconciliation')['rows'])->all(), 'detail'));
        $this->assertStringContainsString('SUITE-PENDING', $details());
        $this->assertStringNotContainsString('CAM-PENDING', $details());

        $this->cameras();
        $this->assertStringContainsString('CAM-PENDING', $details());
        $this->assertStringNotContainsString('SUITE-PENDING', $details());
        $this->assertSame(2, app(\App\Domain\Finance\FinanceService::class)->reconciliationExceptions()->where('type', 'Pending verification')->count(), 'The month-end job still sees both.');
    }

    public function test_daily_alerts_tell_each_business_apart(): void
    {
        \App\Support\Settings::set('alerts.arrival_lead_days', 1);
        $tomorrow = Local::now()->addDay()->toDateString();
        $after = Local::now()->addDays(3)->toDateString();
        $this->confirmed('venice', $tomorrow, $after);
        $this->confirmed('dji-nano', $tomorrow, $after, adults: 1);
        $this->owner->notifications()->delete();

        Artisan::call('uniqa:daily-alerts');

        $notices = $this->owner->fresh()->notifications->map(fn ($n) => [$n->data['title'] ?? '', $n->data['url'] ?? ''])->all();
        $this->assertContains(['1 arrival(s) tomorrow. Check balances and special requests.', '/admin?business=suites'], $notices);
        $this->assertContains(['1 camera pickup(s) tomorrow. Check balances and the renter\'s ID.', '/admin?business=cameras'], $notices);
    }
}
