<?php

namespace Tests\Feature;

use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\TaxRule;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Settings;
use Illuminate\Support\Facades\Route;
use Tests\DomainTestCase;

class PublicSiteAndVacancyTest extends DomainTestCase
{
    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function search(string $query = 'check_in=2031-03-03&check_out=2031-03-05&guests=2'): ?array
    {
        return $this->get("/admin/quotes/create?{$query}")->assertOk()->original->getData()['page']['props']['vacancy'];
    }

    private function vacantNames(array $vacancy): array
    {
        return array_column($vacancy['vacant'], 'name');
    }

    // Public site: no prices anywhere, including in the page data

    public function test_no_public_page_carries_a_price_even_if_asked(): void
    {
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        TaxRule::create(['name' => 'Synthetic Tax', 'rate_basis_points' => 1200, 'effective_from' => '2030-01-01']);
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        auth()->logout();

        foreach ([
            '/', '/suites', '/suites/venice', '/policies',
            '/suites?check_in=2031-03-03&check_out=2031-03-05&guests=2&max_price=5000',
            '/suites/venice?check_in=2031-03-03&check_out=2031-03-05&guests=2&early=12:00',
        ] as $url) {
            $body = $this->get($url)->assertOk()->getContent();

            foreach (['_cents', 'estimate', 'included_guests', 'Synthetic Tax', '170000', '190000', '₱'] as $marker) {
                $this->assertStringNotContainsString($marker, $body, "{$url} must not contain {$marker}");
            }
        }
    }

    public function test_public_suite_filters_still_work_without_prices(): void
    {
        Room::query()->update(['is_published' => true]);
        auth()->logout();

        $names = fn (string $query) => collect($this->get("/suites?{$query}")->original->getData()['page']['props']['rooms'])->pluck('name')->all();

        $this->assertSame(['RIO', 'BERLIN'], $names('location=mesaverte'));
        $this->assertSame(['PARIS', 'NEW YORK'], $names('type=2-bedrooms'));
        $this->assertSame(['NEW YORK'], $names('guests=9'));
    }

    public function test_message_link_reaches_public_pages_only_once_configured(): void
    {
        auth()->logout();
        $contact = fn () => $this->get('/')->original->getData()['page']['props']['contact'];

        $this->assertEmpty($contact()['messenger_url']);

        config(['uniqa.contact.messenger_url' => 'https://m.me/synthetic-page']);
        $this->assertSame('https://m.me/synthetic-page', $contact()['messenger_url']);
    }

    public function test_the_public_has_no_route_to_quotes_or_prices(): void
    {
        auth()->logout();

        $this->get('/admin/quotes/create?check_in=2031-03-03&check_out=2031-03-05')->assertRedirect('/login');
    }

    // Staff vacancy search

    public function test_staff_see_vacant_units_with_the_price_for_the_stay(): void
    {
        $vacancy = $this->search();

        $this->assertCount(9, $vacancy['vacant']);
        $venice = collect($vacancy['vacant'])->firstWhere('name', 'VENICE');
        $this->assertSame(340000, $venice['total_cents']); // 2 weekday nights at 1,700
        $this->assertSame(2, $venice['nights']);
        $this->assertSame('1410', $venice['unit']);
        $this->assertSame('RIO', $vacancy['vacant'][0]['name'], 'Cheapest first.');
    }

    public function test_booked_held_blocked_and_undersized_units_are_not_listed_as_vacant(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->bookings->hold($this->inquiry('bali', '2031-03-04', '2031-03-06'));
        RoomBlock::create(['room_id' => $this->room('roma')->id, 'start_date' => '2031-03-01', 'end_date' => '2031-03-10', 'kind' => 'repair', 'reason' => 'Synthetic']);
        $this->inquiry('tokyo', '2031-03-03', '2031-03-05'); // An inquiry reserves nothing.
        WorkOrder::create(['room_id' => $this->room('milan')->id, 'title' => 'Wiring', 'severity' => 'critical', 'safety_block' => true]);
        $this->room('paris')->update(['operational_status' => 'maintenance']);
        $this->room('berlin')->update(['operational_status' => 'retired']);

        $vacancy = $this->search('check_in=2031-03-03&check_out=2031-03-05&guests=3');
        $unavailable = collect($vacancy['unavailable'])->keyBy('name');

        $this->assertEqualsCanonicalizing(['TOKYO', 'NEW YORK'], $this->vacantNames($vacancy));
        $this->assertStringContainsString('confirmed', $unavailable['VENICE']['reason']);
        $this->assertStringContainsString('tentative', $unavailable['BALI']['reason']);
        $this->assertStringContainsString('repair', $unavailable['ROMA']['reason']);
        $this->assertStringContainsString('safety', $unavailable['MILAN']['reason']);
        $this->assertSame('In maintenance.', $unavailable['PARIS']['reason']);
        $this->assertSame('Takes at most 2 guests.', $unavailable['RIO']['reason']);
        $this->assertFalse($unavailable->has('BERLIN'), 'A retired unit is not offered at all.');
    }

    public function test_a_stay_ending_the_day_another_begins_is_vacant(): void
    {
        $this->confirmed('venice', '2031-03-05', '2031-03-07');

        $this->assertContains('VENICE', $this->vacantNames($this->search('check_in=2031-03-03&check_out=2031-03-05&guests=2')));
    }

    public function test_vacancy_is_listed_without_prices_while_weekend_nights_are_undecided(): void
    {
        Settings::set('pricing.weekend_days', null);

        $vacancy = $this->search();

        $this->assertCount(9, $vacancy['vacant']);
        $this->assertNull($vacancy['vacant'][0]['total_cents']);
        $this->assertStringContainsString('Weekend days', $vacancy['pricing_pending']);
    }

    public function test_a_dirty_room_is_vacant_but_flagged(): void
    {
        $this->room('venice')->update(['housekeeping_status' => 'dirty']);

        $this->assertSame('dirty', collect($this->search()['vacant'])->firstWhere('name', 'VENICE')['housekeeping']);
    }

    public function test_searching_reserves_nothing_and_a_quote_can_be_saved_from_the_result(): void
    {
        $venice = $this->room('venice');
        $this->search();

        $this->post('/admin/quotes', ['room_id' => $venice->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guests' => 2, 'guest_name' => 'Synthetic'])->assertRedirect();

        $this->assertContains('VENICE', $this->vacantNames($this->search()), 'A saved quote does not take the unit.');
    }

    public function test_updating_a_quote_keeps_its_reference_makes_a_new_version_and_keeps_the_old_one(): void
    {
        $venice = $this->room('venice');
        $this->post('/admin/quotes', ['room_id' => $venice->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guests' => 2, 'guest_name' => 'Synthetic']);
        $first = \App\Models\Quote::latest('id')->first();

        $form = $this->get("/admin/quotes/create?reference={$first->reference}&room_id={$venice->id}&check_in=2031-03-03&check_out=2031-03-05&guests=2&guest_name=Synthetic")->assertOk()->original->getData()['page']['props'];
        $this->assertSame($first->reference, $form['filters']['reference']);
        $this->assertSame('Synthetic', $form['filters']['guest_name']);

        $this->post('/admin/quotes', ['reference' => $first->reference, 'room_id' => $venice->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-06', 'guests' => 2, 'guest_name' => 'Synthetic'])->assertRedirect();

        $second = \App\Models\Quote::latest('id')->first();
        $this->assertSame([$first->reference, 2], [$second->reference, $second->version]);
        $this->assertGreaterThan($first->total_cents, $second->total_cents);
        $this->assertSame(1, $first->fresh()->version, 'The earlier version is kept unchanged.');
    }

    public function test_a_taken_unit_gets_no_price_no_message_and_cannot_be_saved(): void
    {
        $venice = $this->room('venice');
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $url = "/admin/quotes/create?room_id={$venice->id}&check_in=2031-03-04&check_out=2031-03-06&guests=2";

        $preview = $this->get($url)->original->getData()['page']['props']['preview'];
        $this->assertStringContainsString('not available', $preview['error']);
        $this->assertArrayNotHasKey('total_cents', $preview);
        $this->assertArrayNotHasKey('copyText', $preview);

        $before = \App\Models\Quote::count();
        $this->post('/admin/quotes', ['room_id' => $venice->id, 'check_in' => '2031-03-04', 'check_out' => '2031-03-06', 'guests' => 2])->assertSessionHasErrors('room_id');
        $this->assertSame($before, \App\Models\Quote::count());

        $check = fn (string $in, string $out) => $this->getJson("/admin/quotes/check?room_id={$venice->id}&check_in={$in}&check_out={$out}&guests=2")->assertOk()->json('available');
        $this->assertFalse($check('2031-03-04', '2031-03-06'));
        $this->assertTrue($check('2031-03-05', '2031-03-06'), 'A check-out day is free for a new arrival.');
    }

    public function test_holds_and_blocks_also_stop_a_quote_and_releasing_allows_it(): void
    {
        $venice = $this->room('venice');
        $data = ['room_id' => $venice->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-04', 'guests' => 2];
        $block = RoomBlock::create(['room_id' => $venice->id, 'start_date' => '2031-03-03', 'end_date' => '2031-03-04', 'kind' => 'workbook', 'reason' => 'Synthetic']);
        $this->post('/admin/quotes', $data)->assertSessionHasErrors('room_id');

        $block->update(['released_at' => now()]);
        $hold = $this->bookings->hold($this->inquiry('venice', '2031-03-03', '2031-03-04'));
        $this->post('/admin/quotes', $data)->assertSessionHasErrors('room_id');

        // Its own booking does not count against a quote made for that booking.
        $this->post('/admin/quotes', $data + ['booking_id' => $hold->id])->assertSessionHasNoErrors();
    }

    public function test_past_dates_cannot_be_quoted(): void
    {
        $this->post('/admin/quotes', ['room_id' => $this->room('venice')->id, 'check_in' => '2020-01-01', 'check_out' => '2020-01-02', 'guests' => 2])->assertSessionHasErrors('room_id');
    }

    public function test_the_ready_to_paste_message_has_the_guest_slot_dates_and_plain_charges(): void
    {
        $venice = $this->room('venice');
        $preview = $this->get("/admin/quotes/create?room_id={$venice->id}&check_in=2031-03-03&check_out=2031-03-05&guests=2&parking=400&discount=20")->original->getData()['page']['props']['preview'];
        $text = $preview['copyText'];

        $this->assertStringContainsString('Hi {{guest}}', $text);
        $this->assertStringContainsString('VENICE', $text);
        $this->assertStringContainsString('Mar 3, 2031', $text);
        $this->assertStringContainsString('Mar 3 (Mon), weekday rate', $text);
        $this->assertStringContainsString('- Parking:', $text);
        $this->assertStringContainsString('- Discount: -', $text);
        $this->assertStringNotContainsString('approved', $text);
        $this->assertStringNotContainsString('2031-03-03', $text);
    }

    public function test_updating_needs_a_real_quote_reference(): void
    {
        $this->get('/admin/quotes/create?reference=Q-NOPE')->assertSessionHasErrors('reference');
    }

    public function test_no_search_runs_without_valid_dates(): void
    {
        $this->assertNull($this->get('/admin/quotes/create')->original->getData()['page']['props']['vacancy']);
        $this->assertNull($this->search('check_in=2031-03-05&check_out=2031-03-03'));
    }

    public function test_only_quoting_staff_can_search(): void
    {
        foreach (['Booking / Reception', 'Operations Manager'] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/quotes/create?check_in=2031-03-03&check_out=2031-03-05')->assertOk();
        }
        foreach (['Housekeeping / Laundry', 'Maintenance', 'Finance / Bookkeeper'] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/quotes/create?check_in=2031-03-03&check_out=2031-03-05')->assertForbidden();
        }
    }

    // Reception inspections

    public function test_reception_can_open_housekeeping_and_pass_a_room_but_not_clean_or_see_other_work(): void
    {
        $room = $this->room('venice');
        $cleaner = $this->staff('Housekeeping / Laundry');
        $waiting = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $room->id, 'status' => 'for_inspection', 'assigned_to' => $cleaner->id]);
        $notReady = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('bali')->id, 'status' => 'pending']);

        $this->actingAs($this->staff('Booking / Reception'));
        $this->get('/admin/housekeeping')->assertOk()->assertInertia(fn ($p) => $p->has('tasks', 1)->where('tasks.0.id', $waiting->id)->where('can.work', false)->where('can.inspect', true));
        $this->post("/admin/tasks/{$notReady->id}/start")->assertForbidden();

        $this->post("/admin/tasks/{$waiting->id}/inspect", ['accepted' => true])->assertSessionMissing('error');
        $this->assertSame('ready', $room->fresh()->housekeeping_status);
    }

    public function test_a_cleaner_still_sees_only_their_own_and_unclaimed_tasks(): void
    {
        $cleaner = $this->staff('Housekeeping / Laundry');
        $other = $this->staff('Housekeeping / Laundry');
        $mine = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'assigned', 'assigned_to' => $cleaner->id]);
        $open = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('bali')->id, 'status' => 'pending']);
        HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('roma')->id, 'status' => 'assigned', 'assigned_to' => $other->id]);

        $ids = collect($this->actingAs($cleaner)->get('/admin/housekeeping')->original->getData()['page']['props']['tasks'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$mine->id, $open->id], $ids);
    }

    public function test_route_list_keeps_cleaning_actions_behind_cleaning_permissions(): void
    {
        $start = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'admin/tasks/{task}/start');

        $this->assertContains('perm:housekeeping.own|housekeeping.manage', $start->gatherMiddleware());
    }
}
