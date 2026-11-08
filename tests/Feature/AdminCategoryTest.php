<?php

namespace Tests\Feature;

use App\Domain\Bookings\MessageService;
use App\Domain\Reporting\ReportService;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Guest;
use App\Models\PaymentAccount;
use App\Models\Property;
use App\Models\Receipt;
use App\Support\Local;
use App\Support\Settings;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

class AdminCategoryTest extends DomainTestCase
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

    /** Resource pages pass a paginator or a plain list as rows. */
    private function rows(string $url): array
    {
        $rows = $this->props($url)['rows'];

        $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : $rows;

        return array_key_exists('data', $rows) ? $rows['data'] : $rows;
    }

    private function cameras(): void
    {
        $this->post('/admin/category', ['category' => 'cameras'])->assertSessionHasNoErrors();
    }

    public function test_suites_is_the_default_and_there_is_no_all(): void
    {
        $this->assertSame('suites', $this->props('/admin/calendar')['category']);
        $this->assertCount(9, $this->props('/admin/calendar')['rooms']);

        $this->post('/admin/category', ['category' => 'all'])->assertSessionHasErrors('category');
        $this->post('/admin/category', ['category' => 'boats'])->assertSessionHasErrors('category');
    }

    public function test_calendar_shows_only_cameras_when_category_is_cameras(): void
    {
        $this->cameras();

        $names = array_column($this->props('/admin/calendar')['rooms'], 'name');
        $this->assertCount(10, $names);
        $this->assertNotContains('VENICE', $names);
    }

    public function test_switching_away_from_a_page_the_other_business_lacks_lands_on_the_dashboard(): void
    {
        $this->from('/admin/quotes')->post('/admin/category', ['category' => 'cameras'])->assertRedirect('/admin');
        $this->from('/admin/calendar')->post('/admin/category', ['category' => 'suites'])->assertRedirect('/admin/calendar');
        $this->from('/admin/camera-rates')->post('/admin/category', ['category' => 'suites'])->assertRedirect('/admin');
    }

    public function test_reservations_list_follows_the_category(): void
    {
        $this->confirmed('venice');
        $this->confirmed('dji-nano', adults: 1);

        $this->assertSame(['VENICE'], array_column($this->props('/admin/bookings')['bookings']['data'], 'rooms'));
        $this->cameras();
        $this->assertSame(['DJI Nano'], array_column($this->props('/admin/bookings')['bookings']['data'], 'rooms'));
    }

    public function test_vacancy_search_never_offers_a_camera(): void
    {
        $vacancy = $this->props('/admin/quotes/create?check_in=2031-03-03&check_out=2031-03-05&guests=1')['vacancy'];

        $names = array_merge(array_column($vacancy['vacant'], 'name'), array_column($vacancy['unavailable'], 'name'));
        $this->assertNotContains('DJI Nano', $names);
        $this->assertContains('VENICE', $names);
    }

    public function test_dashboard_counts_follow_the_category(): void
    {
        $booking = $this->confirmed('dji-nano', Local::today(), Local::now()->addDays(2)->toDateString(), adults: 1);
        $this->bookings->checkIn($booking->fresh());

        $today = $this->props('/admin')['today'];
        $this->assertSame(9, $today['rooms']);
        $this->assertSame(0, $today['occupied']);

        $this->cameras();
        $today = $this->props('/admin')['today'];
        $this->assertSame(10, $today['rooms']);
        $this->assertSame(1, $today['occupied']);
    }

    public function test_payments_guests_and_expenses_follow_the_category(): void
    {
        $suite = $this->confirmed('venice');
        $camera = $this->confirmed('dji-nano', adults: 1);
        foreach ([[$suite, 'S-1'], [$camera, 'C-1']] as [$b, $ref]) {
            Receipt::create(['booking_id' => $b->id, 'payment_account_id' => PaymentAccount::first()->id, 'amount_cents' => 1000, 'method' => 'gcash', 'reference' => $ref, 'received_at' => now(), 'receipt_date' => Local::today(), 'verification_status' => 'verified', 'entered_by' => $this->owner->id]);
        }
        Guest::create(['full_name' => 'Walk-in Nobody']);
        $category = ExpenseCategory::first()->id;
        $grabCam = Property::where('category', Property::CAMERAS)->value('id');
        Expense::create(['expense_category_id' => $category, 'description' => 'General bill', 'amount_cents' => 500, 'expense_date' => Local::today()]);
        Expense::create(['expense_category_id' => $category, 'description' => 'Camera battery', 'amount_cents' => 700, 'expense_date' => Local::today(), 'property_id' => $grabCam]);
        Expense::create(['expense_category_id' => $category, 'description' => 'Nano strap', 'amount_cents' => 300, 'expense_date' => Local::today(), 'room_id' => $this->room('dji-nano')->id]);

        $refs = fn () => array_column($this->rows('/admin/payments'), 'reference');
        $guests = fn () => array_map(fn ($g) => $g['name']['label'], $this->rows('/admin/guests'));
        $expenses = fn () => app(ReportService::class)->kpis(Local::today(), Local::today())['expenses_cents'];

        $this->assertSame(['S-1'], $refs());
        $this->assertSame(500, $expenses());
        $this->assertContains($suite->guest->full_name, $guests());
        $this->assertNotContains($camera->guest->full_name, $guests());

        $this->cameras();
        $this->assertSame(['C-1'], $refs());
        $this->assertSame(1000, $expenses());
        $this->assertContains($camera->guest->full_name, $guests());
        $this->assertNotContains($suite->guest->full_name, $guests());
        $this->assertContains('Walk-in Nobody', $guests(), 'A customer with no bookings yet shows under both.');
    }

    public function test_report_occupancy_counts_only_the_selected_units(): void
    {
        $kpis = fn () => app(ReportService::class)->kpis('2031-03-01', '2031-03-01');

        $this->assertSame(9, $kpis()['available_room_nights']);
        $this->cameras();
        $this->assertSame(10, $kpis()['available_room_nights']);
    }

    public function test_camera_rates_page_lists_models_and_saves_a_new_rate(): void
    {
        $this->cameras();
        $rows = collect($this->rows('/admin/camera-rates'))->keyBy('model');
        $this->assertCount(9, $rows);
        $this->assertSame(40000, $rows['DJI Nano']['one_two']);
        $this->assertSame(1, $rows['DJI Nano']['units']);

        $type = $this->room('dji-nano')->room_type_id;
        $this->post("/admin/camera-rates/{$type}", ['one_two' => '420', 'three_five' => '360', 'six_plus' => '310', 'free_day_from' => 8, 'effective_from' => '2031-01-01'])->assertSessionHasNoErrors();
        $this->assertSame(84000, (int) $this->confirmed('dji-nano', '2031-03-03', '2031-03-05', adults: 1)->fresh()->total_cents);

        $this->post('/admin/camera-rates/free-day', ['threshold' => 0, 'effective_from' => '2031-02-01'])->assertSessionHasNoErrors();
        $set = \App\Models\RentalRateTier::where('room_type_id', $type)->where('effective_from', '2031-02-01')->get();
        $this->assertCount(3, $set);
        $this->assertTrue($set->every(fn ($t) => $t->free_day_from === null), 'From February, no free day.');
        $this->assertSame([42000, 36000, 31000], $set->sortBy('min_days')->pluck('daily_cents')->values()->all(), 'The rates in force carry over.');
    }

    public function test_suite_minimum_stay_does_not_apply_to_cameras(): void
    {
        Settings::set('stay.min_nights', 3);

        $this->assertSame('confirmed', $this->confirmed('dji-nano', '2031-03-03', '2031-03-04', adults: 1)->fresh()->status);
    }

    public function test_camera_bookings_get_camera_messages_and_suites_do_not(): void
    {
        $service = app(MessageService::class);
        $camera = collect($service->renderAll($this->confirmed('dji-nano', '2031-03-03', '2031-03-05', adults: 1)))->pluck('key');
        $suite = collect($service->renderAll($this->confirmed('venice')))->pluck('key');

        $this->assertTrue($camera->isNotEmpty() && $camera->every(fn ($k) => str_starts_with($k, 'camera_')));
        $this->assertTrue($suite->isNotEmpty() && $suite->every(fn ($k) => ! str_starts_with($k, 'camera_')));
    }

    public function test_camera_rates_cannot_be_back_dated(): void
    {
        $type = $this->room('dji-nano')->room_type_id;

        $this->post("/admin/camera-rates/{$type}", ['one_two' => '420', 'three_five' => '360', 'six_plus' => '310', 'effective_from' => Local::now()->subDay()->toDateString()])
            ->assertSessionHasErrors('effective_from');
    }

    public function test_every_dashboard_section_follows_the_category(): void
    {
        $in = Local::now()->addDays(2)->toDateString();
        $out = Local::now()->addDays(4)->toDateString();
        $this->confirmed('venice', $in, $out);
        $this->confirmed('dji-nano', $in, $out, adults: 1);
        $this->confirmed('dji-nano', Local::now()->addDays(5)->toDateString(), Local::now()->addDays(6)->toDateString(), adults: 1);

        $check = function (int $units, array $boardHas, array $boardLacks, int $upcoming, int $bookedOnDay) {
            $props = $this->props('/admin');
            $board = array_column($props['board'], 'name');
            $this->assertCount($units, $board);
            foreach ($boardHas as $name) {
                $this->assertContains($name, $board);
            }
            foreach ($boardLacks as $name) {
                $this->assertNotContains($name, $board);
            }
            $this->assertSame($upcoming, $props['bookingsTab']['upcoming_count']);
            $this->assertSame($bookedOnDay, collect($props['forecast'])->firstWhere('date', Local::now()->addDays(2)->toDateString())['rooms']);
            $this->assertCount($units, $props['performance']['rooms']);
        };

        $check(9, ['VENICE'], ['DJI Nano'], 1, 1);
        $this->cameras();
        $check(10, ['DJI Nano'], ['VENICE'], 2, 1);
        $this->assertSame('cameras', $this->props('/admin')['category']);
    }

    public function test_switching_on_a_record_of_the_other_business_goes_to_its_list_instead_of_bouncing_back(): void
    {
        $stay = $this->confirmed('venice');
        $venice = $this->room('venice');

        $this->from("/admin/bookings/{$stay->id}")->post('/admin/category', ['category' => 'cameras'])->assertRedirect('/admin/bookings');
        $this->assertSame('cameras', $this->props('/admin/bookings')['category']);

        $this->from("/admin/rooms/{$venice->id}/edit")->post('/admin/category', ['category' => 'cameras'])->assertRedirect('/admin/rooms');

        $rental = $this->confirmed('dji-nano', adults: 1);
        $this->from("/admin/bookings/{$rental->id}")->post('/admin/category', ['category' => 'cameras'])->assertRedirect("/admin/bookings/{$rental->id}");
    }

    public function test_switching_drops_filters_that_point_at_the_other_business(): void
    {
        $loop = Property::where('slug', 'the-loop')->value('id');
        $venice = $this->room('venice')->id;

        $this->from("/admin/calendar?property={$loop}&month=2031-03")->post('/admin/category', ['category' => 'cameras'])->assertRedirect('/admin/calendar?month=2031-03');
        $this->from("/admin/bookings?room={$venice}&status=confirmed")->post('/admin/category', ['category' => 'cameras'])->assertRedirect('/admin/bookings?status=confirmed');
        $this->from('/admin?tab=rooms&period=90d')->post('/admin/category', ['category' => 'suites'])->assertRedirect('/admin?tab=rooms&period=90d');
    }

    public function test_every_shared_page_renders_in_both_businesses(): void
    {
        $this->confirmed('venice');
        $this->confirmed('dji-nano', adults: 1);
        $pages = ['/admin', '/admin?tab=bookings', '/admin?tab=rooms', '/admin?tab=income', '/admin?tab=expenses', '/admin?tab=operations',
            '/admin/calendar', '/admin/bookings', '/admin/guests', '/admin/rooms', '/admin/work-orders', '/admin/incidents',
            '/admin/payments', '/admin/settlements', '/admin/reconciliation', '/admin/expenses', '/admin/cashbook', '/admin/reports',
            '/admin/team', '/admin/settings', '/admin/audit', '/admin/bookings/form-options', '/admin/account'];

        foreach (['suites', 'cameras', 'suites'] as $business) {
            $this->post('/admin/category', ['category' => $business]);
            foreach ($pages as $url) {
                $this->get($url)->assertOk();
            }
        }
        $this->post('/admin/category', ['category' => 'cameras']);
        $this->get('/admin/camera-rates')->assertOk();
        foreach (\App\Models\Room::cameras()->pluck('id') as $id) {
            $this->get("/admin/rooms/{$id}/edit")->assertOk();
        }
    }
}
