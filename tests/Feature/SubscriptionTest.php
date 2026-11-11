<?php

namespace Tests\Feature;

use App\Models\SubscriptionBill;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Subscription;
use Illuminate\Support\Facades\Artisan;
use Tests\DomainTestCase;

/** The system's monthly subscription: trial, paid dates, grace period, reminders and the lock. */
class SubscriptionTest extends DomainTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->person('Administrator');
        $this->travelTo('2026-12-01 09:00:00');
    }

    private function person(string $role, ?array $pages = null): User
    {
        $user = User::factory()->create();
        Permissions::apply($user, $role, $pages);

        return $user->fresh();
    }

    /** Trial Nov 1–30, paid Dec 1–31, 3 grace days: due Jan 1–3, locked from Jan 4. */
    private function plan(array $overrides = []): void
    {
        Subscription::save($overrides + ['monthly_fee_cents' => 400000, 'trial_start' => '2026-11-01', 'trial_end' => '2026-11-30', 'start' => '2026-12-01', 'end' => '2026-12-31', 'grace_days' => 3]);
    }

    private function bellTitles(User $user): array
    {
        return $user->fresh()->notifications()->pluck('data')->pluck('title')->all();
    }

    public function test_with_no_dates_set_the_system_never_locks(): void
    {
        $this->assertSame(Subscription::NOT_SET, Subscription::status());
        $this->travelTo('2030-01-01');
        $this->actingAs($this->owner)->get('/admin')->assertOk();
    }

    public function test_status_follows_the_dates_from_trial_to_lock(): void
    {
        $this->plan();

        foreach ([
            '2026-11-15' => Subscription::TRIAL,
            '2026-11-30' => Subscription::TRIAL,
            '2026-12-01' => Subscription::ACTIVE,
            '2026-12-31' => Subscription::ACTIVE,
            '2027-01-01' => Subscription::DUE,
            '2027-01-03' => Subscription::DUE,
            '2027-01-04' => Subscription::LOCKED,
        ] as $day => $status) {
            $this->assertSame($status, Subscription::status($day), $day);
        }
        $this->assertSame('2027-01-04', Subscription::locksOn());
    }

    public function test_a_trial_alone_locks_after_its_grace_period(): void
    {
        $this->plan(['start' => null, 'end' => null]);

        $this->assertSame(Subscription::TRIAL, Subscription::status('2026-11-30'));
        $this->assertSame(Subscription::DUE, Subscription::status('2026-12-03'));
        $this->assertSame(Subscription::LOCKED, Subscription::status('2026-12-04'));
    }

    public function test_once_locked_staff_and_owner_see_only_the_locked_screen_but_the_website_stays_up(): void
    {
        $this->plan();
        $this->travelTo('2027-01-04 08:00:00');
        $staff = $this->person('Booking / Reception');

        foreach ([$this->owner, $staff] as $user) {
            $this->actingAs($user);
            $this->get('/admin')->assertRedirect('/admin/locked');
            $this->get('/admin/bookings')->assertRedirect('/admin/locked');
            $this->get('/admin/settings')->assertRedirect('/admin/locked');
            $this->post('/admin/bookings', [])->assertRedirect('/admin/locked');
            $this->getJson('/admin/bookings/form-options')->assertStatus(423);

            $locked = $this->get('/admin/locked')->assertOk()->original->getData()['page'];
            $this->assertSame('Admin/Locked', $locked['component']);
            $this->assertStringContainsString('₱4,000.00', $locked['props']['subscription']['message']);
        }

        $this->post('/admin/logout')->assertRedirect();
        auth()->logout();
        $this->get('/')->assertOk();
        $this->get('/grabcam')->assertOk();
        $this->get('/policies')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_the_administrator_is_never_locked_out(): void
    {
        $this->plan();
        $this->travelTo('2027-02-01');

        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk();
        $this->get('/admin/subscription')->assertOk();
        $this->get('/admin/locked')->assertRedirect('/admin');
    }

    /** The agreed plan: trial Nov 9–22, monthly bills from Nov 23. */
    private function agreed(): void
    {
        $this->actingAs($this->admin)->post('/admin/subscription', [
            'monthly_fee' => '4000', 'trial_start' => '2026-11-09', 'trial_end' => '2026-11-22', 'start' => '2026-11-23', 'grace_days' => 3,
        ])->assertSessionHasNoErrors();
    }

    private function billPeriods(): array
    {
        return SubscriptionBill::orderBy('period_start')->get()->map(fn ($b) => [$b->period_start->toDateString(), $b->period_end->toDateString(), $b->due_on->toDateString(), $b->status, $b->amount_cents])->all();
    }

    private function pay(SubscriptionBill $bill, string $on, string $amount = '4000')
    {
        return $this->actingAs($this->admin)->post("/admin/subscription/bills/{$bill->id}/pay", ['paid_on' => $on, 'amount' => $amount, 'note' => 'GCash 123']);
    }

    public function test_marking_nov_23_paid_covers_the_month_and_creates_the_next_bill(): void
    {
        $this->travelTo('2026-11-10');
        $this->agreed();

        // The first bill exists as soon as the start date is set.
        $this->assertSame([['2026-11-23', '2026-12-22', '2026-11-23', 'unpaid', 400000]], $this->billPeriods());
        $this->assertSame(Subscription::TRIAL, Subscription::status());

        // Unpaid: due from Nov 23, locked from Nov 26.
        $this->travelTo('2026-11-24');
        $this->assertSame(Subscription::DUE, Subscription::status());
        $this->travelTo('2026-11-26');
        $this->actingAs($this->owner)->get('/admin')->assertRedirect('/admin/locked');

        // Mark it paid: paid through Dec 22, unlocked, and the Dec 23 bill appears on its own.
        $this->pay(SubscriptionBill::sole(), '2026-11-26')->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('2026-12-22', Subscription::paidThrough());
        $this->assertSame([
            ['2026-11-23', '2026-12-22', '2026-11-23', 'paid', 400000],
            ['2026-12-23', '2027-01-22', '2026-12-23', 'unpaid', 400000],
        ], $this->billPeriods());
        $this->actingAs($this->owner)->get('/admin')->assertOk();
        $paid = SubscriptionBill::where('status', 'paid')->sole();
        $this->assertSame(['2026-11-26', 400000, 'GCash 123', $this->admin->id], [$paid->paid_on->toDateString(), $paid->paid_amount_cents, $paid->note, $paid->recorded_by]);

        // Paying ahead keeps going month by month.
        $this->pay(Subscription::openBill(), '2026-12-20')->assertSessionHasNoErrors();
        $this->assertSame('2027-01-22', Subscription::paidThrough());
        $this->assertSame(['2027-01-23', '2027-02-22'], [Subscription::openBill()->period_start->toDateString(), Subscription::openBill()->period_end->toDateString()]);
    }

    public function test_a_paid_bill_cannot_be_paid_again_and_the_owner_cannot_mark_bills(): void
    {
        $this->travelTo('2026-11-10'); // inside the trial, so the Owner is not simply sent to the locked screen
        $this->agreed();
        $bill = SubscriptionBill::sole();

        $this->actingAs($this->owner)->post("/admin/subscription/bills/{$bill->id}/pay", ['paid_on' => '2026-11-23', 'amount' => '4000'])->assertForbidden();
        $this->pay($bill, '2026-11-23')->assertSessionHasNoErrors();
        $this->pay($bill->fresh(), '2026-11-24')->assertStatus(422);
        $this->assertSame(2, SubscriptionBill::count(), 'No extra bill from a repeated payment.');
    }

    public function test_months_follow_the_start_day_even_after_a_short_month(): void
    {
        $this->actingAs($this->admin)->post('/admin/subscription', ['monthly_fee' => '4000', 'start' => '2027-01-31', 'grace_days' => 3])->assertSessionHasNoErrors();
        foreach (range(1, 3) as $i) {
            $this->pay(Subscription::openBill(), '2027-01-31');
        }

        $this->assertSame([['2027-01-31', '2027-02-27'], ['2027-02-28', '2027-03-30'], ['2027-03-31', '2027-04-29'], ['2027-04-30', '2027-05-30']],
            array_map(fn ($b) => array_slice($b, 0, 2), $this->billPeriods()));
    }

    public function test_before_any_payment_the_first_bill_follows_the_dates_and_after_it_only_the_open_bill_takes_a_new_fee(): void
    {
        $this->agreed();
        $this->actingAs($this->admin)->post('/admin/subscription', ['monthly_fee' => '4500', 'trial_start' => '2026-11-09', 'trial_end' => '2026-11-24', 'start' => '2026-11-25', 'grace_days' => 3]);
        $this->assertSame([['2026-11-25', '2026-12-24', '2026-11-25', 'unpaid', 450000]], $this->billPeriods(), 'A moved start rebuilds the unpaid first bill.');

        $this->pay(Subscription::openBill(), '2026-11-25', '4500');
        $this->actingAs($this->admin)->post('/admin/subscription', ['monthly_fee' => '5000', 'trial_start' => '2026-11-09', 'trial_end' => '2026-11-24', 'start' => '2026-11-25', 'end' => '2026-12-24', 'grace_days' => 3]);
        $this->assertSame([[450000, 'paid'], [500000, 'unpaid']], SubscriptionBill::orderBy('period_start')->get()->map(fn ($b) => [$b->amount_cents, $b->status])->all());
        $this->assertSame('2026-12-24', Subscription::paidThrough());
    }

    public function test_a_save_never_drops_the_paid_through_date_below_what_was_paid(): void
    {
        $this->travelTo('2026-11-10');
        $this->agreed();
        $this->pay(SubscriptionBill::sole(), '2026-11-23');

        // A form still holding the old, empty paid-through date.
        $this->actingAs($this->admin)->post('/admin/subscription', ['monthly_fee' => '4000', 'trial_start' => '2026-11-09', 'trial_end' => '2026-11-22', 'start' => '2026-11-23', 'end' => '', 'grace_days' => 3])->assertSessionHasNoErrors();

        $this->assertSame('2026-12-22', Subscription::paidThrough());
        $this->assertSame(2, SubscriptionBill::count());
    }

    public function test_the_days_before_the_trial_starts_count_as_trial(): void
    {
        $this->agreed();

        $this->assertSame(Subscription::TRIAL, Subscription::status('2026-11-08'));
        $this->assertSame(Subscription::TRIAL, Subscription::status('2026-11-22'));
        $this->assertSame(Subscription::DUE, Subscription::status('2026-11-23'), 'Nov 23 is the first billing day; unpaid, it is due.');
    }

    public function test_paying_or_changing_the_dates_clears_reminders_that_no_longer_apply(): void
    {
        $this->travelTo('2026-11-10');
        $this->agreed();
        Subscription::remind('2026-11-24'); // a "payment due" reminder
        $this->assertCount(1, $this->bellTitles($this->owner));

        $this->pay(SubscriptionBill::sole(), '2026-11-24');
        $this->assertCount(0, $this->bellTitles($this->owner), 'Paid: the due reminder is gone from the bell.');
        $this->assertCount(0, $this->bellTitles($this->admin));

        Subscription::remind('2026-12-19'); // "ends in 3 days" (paid through Dec 22)
        $this->assertCount(1, $this->bellTitles($this->owner));
        $this->actingAs($this->admin)->post('/admin/subscription', ['monthly_fee' => '4000', 'trial_start' => '2026-11-09', 'trial_end' => '2026-11-22', 'start' => '2026-11-23', 'end' => '2027-03-22', 'grace_days' => 3]);
        $this->assertCount(0, $this->bellTitles($this->owner), 'New dates: the old reminder is gone.');
    }

    public function test_the_corner_badge_shows_trial_expired_or_locked_and_nothing_once_paid(): void
    {
        $this->travelTo('2026-11-08');
        $this->agreed();
        $staff = $this->person('Booking / Reception');
        $badge = fn (User $u) => $this->actingAs($u)->get('/admin/account')->original->getData()['page']['props']['subscriptionBadge'];

        $this->assertSame(['status' => 'trial', 'title' => 'Free trial', 'detail' => '14 days left'], $badge($this->owner));
        $this->assertNull($badge($staff), 'Only the Owner, managers and the Administrator see it.');
        $this->assertNotNull($badge($this->person('Operations Manager')), 'A manager sees what the Owner sees.');
        $this->assertSame('ends today', Subscription::badge('2026-11-22')['detail']);
        $this->assertSame(['status' => 'due', 'title' => 'Subscription expired', 'detail' => 'locks in 2 days'], Subscription::badge('2026-11-24'));
        $this->assertSame('locks in 1 day', Subscription::badge('2026-11-25')['detail']);
        $this->assertSame(['status' => 'locked', 'title' => 'System locked', 'detail' => 'unpaid'], Subscription::badge('2026-11-26'));

        $this->pay(SubscriptionBill::sole(), '2026-11-23');
        $this->assertNull(Subscription::badge('2026-11-23'), 'Paid: nothing in the corner.');
        $this->assertNull($badge($this->owner));
    }

    public function test_the_daily_check_makes_sure_the_next_bill_exists(): void
    {
        Subscription::save(['start' => '2026-11-23', 'end' => null]);
        $this->assertSame(0, SubscriptionBill::count());

        Artisan::call('uniqa:subscription-check');

        $this->assertSame(1, SubscriptionBill::count());
    }

    public function test_only_the_administrator_can_change_the_subscription_and_the_owner_can_see_it(): void
    {
        $this->plan();
        $staff = $this->person('Booking / Reception');
        $form = ['monthly_fee' => '1', 'end' => '2099-12-31', 'start' => '2026-12-01', 'grace_days' => 3];

        $this->actingAs($this->owner);
        $page = $this->get('/admin/subscription')->assertOk()->original->getData()['page']['props'];
        $this->assertFalse($page['canManage']);
        $this->post('/admin/subscription', $form)->assertForbidden();

        $this->actingAs($staff)->get('/admin/subscription')->assertForbidden();
        $this->assertSame('2026-12-31', Subscription::settings()['end']);

        $this->actingAs($this->admin);
        $this->assertTrue($this->get('/admin/subscription')->original->getData()['page']['props']['canManage']);
    }

    public function test_the_administrator_sets_the_fee_trial_dates_subscription_dates_and_grace_period(): void
    {
        $this->actingAs($this->admin)->post('/admin/subscription', [
            'monthly_fee' => '4000', 'trial_start' => '2026-11-01', 'trial_end' => '2026-11-30',
            'start' => '2026-12-01', 'end' => '2026-12-31', 'grace_days' => 5,
        ])->assertSessionHasNoErrors();

        $s = Subscription::settings();
        $this->assertSame([400000, '2026-11-01', '2026-11-30', '2026-12-01', '2026-12-31', 5], [$s['monthly_fee_cents'], $s['trial_start'], $s['trial_end'], $s['start'], $s['end'], $s['grace_days']]);
        $this->assertSame('2027-01-06', Subscription::locksOn());

        $this->post('/admin/subscription', ['monthly_fee' => '4000', 'trial_start' => '2026-11-30', 'trial_end' => '2026-11-01', 'start' => '2026-12-31', 'end' => '2026-12-01', 'grace_days' => 3])
            ->assertSessionHasErrors(['trial_end', 'end']);
        $this->assertSame('2026-12-31', Subscription::settings()['end'], 'An invalid save changes nothing.');
    }

    public function test_reminders_reach_the_owner_and_administrator_bell_on_the_right_days_only(): void
    {
        $this->plan();
        $staff = $this->person('Booking / Reception');
        $sentOn = [];

        for ($day = \Carbon\CarbonImmutable::parse('2026-12-25'); $day->lte(\Carbon\CarbonImmutable::parse('2027-01-06')); $day = $day->addDay()) {
            if (Subscription::remind($day->toDateString())) {
                $sentOn[] = $day->toDateString();
            }
        }

        // 3 and 1 days before, the end day, the 3 grace days, and the day it locks.
        $this->assertSame(['2026-12-28', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04'], $sentOn);
        $this->assertCount(7, $this->bellTitles($this->owner));
        $this->assertCount(7, $this->bellTitles($this->admin));
        $this->assertCount(0, $this->bellTitles($staff), 'Staff are not told about the subscription.');
        $this->assertContains('The system subscription ends in 3 days. Please pay ₱4,000.00 to keep using the system.', $this->bellTitles($this->owner));
        $this->assertContains('The system subscription ended on Dec 31, 2026. Please pay ₱4,000.00 by Jan 3, 2027 or the system locks on Jan 4, 2027. The website stays online.', $this->bellTitles($this->owner));
    }

    public function test_the_daily_check_sends_at_most_one_reminder_a_day_and_is_scheduled(): void
    {
        $this->plan();
        $this->travelTo('2027-01-02 07:05:00');

        Artisan::call('uniqa:subscription-check');
        Artisan::call('uniqa:subscription-check');

        $this->assertCount(1, $this->bellTitles($this->owner));
        $this->assertStringContainsString('Jan 4, 2027', $this->bellTitles($this->owner)[0]);
        $this->assertSame('/admin/subscription', $this->owner->fresh()->notifications()->first()->data['url']);

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('uniqa:subscription-check', $events);
    }

    public function test_the_banner_shows_to_owner_and_administrator_only_when_it_matters(): void
    {
        $this->plan();
        $staff = $this->person('Booking / Reception');
        $banner = fn (User $u) => $this->actingAs($u)->get('/admin/account')->original->getData()['page']['props']['subscription'];

        $this->travelTo('2026-12-10');
        $this->assertNull($banner($this->owner), 'Nothing to say three weeks before the end.');

        $this->travelTo('2027-01-02');
        $this->assertSame('due', $banner($this->owner)['status']);
        $this->assertSame('due', $banner($this->admin)['status']);
        $this->assertNull($banner($staff));
    }

    public function test_the_owner_cannot_unlock_by_making_or_changing_an_administrator(): void
    {
        $person = $this->person('Staff', []);
        $this->actingAs($this->owner);

        $this->post('/admin/team', ['name' => 'Back Door', 'email' => 'door@example.test', 'role' => 'Administrator'])->assertForbidden();
        $this->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertForbidden();
        $this->post("/admin/team/{$this->admin->id}", ['role' => 'Staff', 'is_active' => false])->assertForbidden();
        $this->post("/admin/team/{$this->admin->id}/temporary-password")->assertForbidden();
        $this->assertNotContains('Administrator', $this->get('/admin/team/create')->original->getData()['page']['props']['roles']);

        $this->actingAs($this->admin)->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole('Administrator'));
    }

    public function test_the_menu_item_is_for_owner_and_administrator(): void
    {
        $this->actingAs($this->owner)->get('/admin/subscription')->assertOk();
        $this->actingAs($this->person('Staff', ['dashboard']))->get('/admin/subscription')->assertForbidden();
    }
}
