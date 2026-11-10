<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\PermissionRegistrar;
use Tests\DomainTestCase;

/** Uses the real setup, not the test fixtures: roles other than the full-access two carry nothing of their own. */
class PageAccessTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        foreach (['Operations Manager', 'Booking / Reception', 'Staff'] as $role) {
            \Spatie\Permission\Models\Role::findByName($role)->syncPermissions([]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function person(string $role, ?array $pages = null): User
    {
        $user = User::factory()->create();
        Permissions::apply($user, $role, $pages);

        return $user->fresh();
    }

    private function pagesOnForm(User $user): array
    {
        return $this->get("/admin/team/{$user->id}/edit")->assertOk()->original->getData()['page']['props']['person']['pages'];
    }

    public function test_only_the_five_roles_exist_and_every_permission_can_be_ticked(): void
    {
        $this->assertSame(['Administrator', 'Owner', 'Operations Manager', 'Booking / Reception', 'Staff'], Permissions::ROLES);

        $mapped = collect(Permissions::PAGES)->flatMap(fn ($page) => $page[2])->all();
        $this->assertEqualsCanonicalizing(Permissions::ALL, $mapped, 'Each permission belongs to exactly one tick box.');
    }

    public function test_the_login_page_and_team_screen_offer_only_the_five_roles(): void
    {
        config(['uniqa.demo' => true]);
        // The Owner can give every role but Administrator, which only an Administrator gives (it controls the subscription lock).
        $form = $this->get('/admin/team/create')->assertOk()->original->getData()['page']['props'];
        $this->assertSame(['Owner', 'Operations Manager', 'Booking / Reception', 'Staff'], $form['roles']);
        $admin = $this->person('Administrator');
        $this->assertSame(['Administrator', 'Owner', 'Operations Manager', 'Booking / Reception', 'Staff'], $this->actingAs($admin)->get('/admin/team/create')->original->getData()['page']['props']['roles']);
        $this->actingAs($this->owner);

        auth()->logout();
        $this->assertSame(Permissions::ROLES, array_column($this->get('/login')->original->getData()['page']['props']['demoAccounts'], 'label'));
    }

    public function test_a_ticked_page_opens_and_an_unticked_one_is_refused_by_the_server(): void
    {
        $person = $this->person('Staff', ['reports', 'quotes']);
        $this->actingAs($person);

        $this->get('/admin/reports')->assertOk();
        $this->get('/admin/quotes')->assertOk();
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/team')->assertForbidden();
        $this->get('/admin/bookings')->assertForbidden();
        $this->post('/admin/bookings', [])->assertForbidden();
    }

    public function test_unticking_a_rolls_usual_page_takes_it_away(): void
    {
        $person = $this->person('Booking / Reception');
        $this->post("/admin/team/{$person->id}", ['role' => 'Booking / Reception', 'is_active' => true, 'pages' => ['bookings']])->assertSessionHasNoErrors();

        $this->actingAs($person->fresh());
        $this->get('/admin/bookings')->assertOk();
        $this->get('/admin/guests')->assertForbidden();
        $this->get('/admin/quotes')->assertForbidden();
    }

    public function test_ticking_a_page_outside_the_role_adds_it(): void
    {
        $person = $this->person('Staff');
        $this->actingAs($person)->get('/admin/reports')->assertForbidden();

        $this->actingAs($this->owner)->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['housekeeping_own', 'reports']])->assertSessionHasNoErrors();

        $this->actingAs($person->fresh())->get('/admin/reports')->assertOk();
        $this->assertEqualsCanonicalizing(['housekeeping_own', 'reports'], Permissions::pagesOf($person->fresh()));
    }

    public function test_the_form_shows_the_persons_current_ticks(): void
    {
        $person = $this->person('Staff', ['reports']);

        $this->assertSame(['reports'], $this->pagesOnForm($person));
    }

    public function test_a_new_role_starts_with_its_usual_pages(): void
    {
        Password::shouldReceive('sendResetLink')->once();
        $this->post('/admin/team', ['name' => 'Synthetic Manager', 'email' => 'manager@example.test', 'role' => 'Operations Manager'])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(Permissions::ROLE_PAGES['Operations Manager'], Permissions::pagesOf(User::where('email', 'manager@example.test')->first()));
    }

    public function test_inviting_with_chosen_pages_uses_those_pages(): void
    {
        Password::shouldReceive('sendResetLink')->once();
        $this->post('/admin/team', ['name' => 'Synthetic', 'email' => 'picked@example.test', 'role' => 'Staff', 'pages' => ['parking']])->assertSessionHasNoErrors();

        $this->assertSame(['parking'], Permissions::pagesOf(User::where('email', 'picked@example.test')->first()));
    }

    public function test_a_save_without_page_ticks_leaves_access_alone_unless_the_role_changes(): void
    {
        $person = $this->person('Staff', ['reports']);

        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true]);
        $this->assertSame(['reports'], Permissions::pagesOf($person->fresh()));

        $this->post("/admin/team/{$person->id}", ['role' => 'Booking / Reception', 'is_active' => true]);
        $this->assertEqualsCanonicalizing(Permissions::ROLE_PAGES['Booking / Reception'], Permissions::pagesOf($person->fresh()));
    }

    public function test_the_administrator_always_has_everything_whatever_is_ticked(): void
    {
        $person = $this->person('Administrator', ['reports']);
        $this->assertCount(count(Permissions::ALL), $person->getAllPermissions());
        $this->assertCount(0, $person->getDirectPermissions());
        $this->actingAs($person)->get('/admin/settings')->assertOk();
    }

    public function test_an_owner_has_only_the_pages_ticked_for_them(): void
    {
        $person = $this->person('Owner', ['reports']);
        $this->assertSame(['reports'], Permissions::pagesOf($person));
        $this->actingAs($person)->get('/admin/settings')->assertForbidden();
        $this->get('/admin/reports')->assertOk();
    }

    public function test_someone_with_the_team_page_cannot_give_more_than_they_have(): void
    {
        $manager = $this->person('Staff', ['team', 'reports']);
        $person = $this->person('Staff', []);
        $this->actingAs($manager);

        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['settings']])->assertSessionHasErrors('pages');
        $this->assertSame([], Permissions::pagesOf($person->fresh()));

        $this->post("/admin/team/{$manager->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['team', 'reports', 'settings']])->assertSessionHasErrors('pages');
        $this->get('/admin/settings')->assertForbidden();

        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['reports']])->assertSessionHasNoErrors();
    }

    public function test_someone_with_the_team_page_cannot_keep_access_they_lack_from_being_removed_by_mistake(): void
    {
        $manager = $this->person('Staff', ['team']);
        $person = $this->person('Staff', ['settings']);
        $this->actingAs($manager);

        // A box they cannot change stays as it is, instead of being cleared because they could not tick it.
        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['settings']])->assertSessionHasNoErrors();
        $this->assertSame(['settings'], Permissions::pagesOf($person->fresh()));

        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => []])->assertSessionHasNoErrors();
        $this->assertSame(['settings'], Permissions::pagesOf($person->fresh()));
    }

    public function test_only_full_access_people_can_create_full_access_people(): void
    {
        $manager = $this->person('Staff', ['team']);
        $person = $this->person('Staff', []);
        $this->actingAs($manager);

        $this->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertForbidden();
        $this->post('/admin/team', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'Owner'])->assertForbidden();
        $this->post("/admin/team/{$manager->id}", ['role' => 'Owner', 'is_active' => true])->assertForbidden();
    }

    public function test_the_administrator_controls_everyone_including_owners(): void
    {
        $admin = $this->person('Administrator');
        $second = $this->person('Owner');
        $person = $this->person('Staff', []);
        $this->actingAs($admin);

        $this->get("/admin/team/{$second->id}/edit")->assertOk();
        $this->post("/admin/team/{$second->id}", ['role' => 'Staff', 'is_active' => false])->assertSessionHasNoErrors();
        $this->post("/admin/team/{$person->id}", ['role' => 'Owner', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole('Owner'));
        $this->post("/admin/team/{$this->owner->id}/reset-two-factor")->assertSessionHasNoErrors();

        $this->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole('Administrator'));
    }

    public function test_an_owner_cannot_make_or_change_an_administrator(): void
    {
        $admin = $this->person('Administrator');
        $person = $this->person('Staff', []);

        $this->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertForbidden();
        $this->post("/admin/team/{$admin->id}/reset-two-factor")->assertForbidden();
    }

    public function test_changes_to_access_are_on_record(): void
    {
        $person = $this->person('Staff', []);
        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['reports']]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'team.updated']);
    }

    public function test_workload_assignment_uses_people_with_cleaning_tasks_not_managers(): void
    {
        $cleaner = $this->person('Staff', ['housekeeping_own']);
        $this->person('Operations Manager');
        \App\Models\HousekeepingTask::create(['room_id' => $this->room('venice')->id, 'type' => 'turnover', 'status' => 'pending', 'due_at' => now()]);

        $this->assertSame(1, app(\App\Domain\Tasks\TurnoverService::class)->assignByWorkload());
        $this->assertSame($cleaner->id, \App\Models\HousekeepingTask::first()->assigned_to);
    }

    public function test_the_suite_files_are_not_in_the_public_folder(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('suite'));
        $this->assertDirectoryExists(database_path('seeders/suite'));
    }

    // The Team screen: grouped boxes, each explained, and actions tied to their page

    public function test_every_box_is_explained_and_shown_once_on_the_team_screen(): void
    {
        $form = $this->get('/admin/team/create')->assertOk()->original->getData()['page']['props'];

        $inAreas = collect($form['areas'])->flatten();
        $actions = collect($form['pages'])->whereNotNull('requires')->pluck('key');
        $this->assertEqualsCanonicalizing(array_keys(Permissions::PAGES), $inAreas->merge($actions)->all(), 'Every box appears exactly once: a page in an area, or an action under its page.');
        $this->assertEmpty(collect($form['pages'])->whereNull('help')->pluck('key')->all(), 'Every box has a one-line explanation.');
        foreach (Permissions::REQUIRES as $action => $page) {
            $this->assertSame('Pages', Permissions::PAGES[$page][1], "{$action} must sit under a page.");
            $this->assertSame('Extra actions', Permissions::PAGES[$action][1]);
        }
        foreach (Permissions::ROLE_PAGES as $role => $pages) {
            $this->assertSame($pages, Permissions::withRequired($pages), "The usual {$role} ticks already include each action's page.");
        }
    }

    public function test_ticking_an_action_alone_also_gives_its_page(): void
    {
        $person = $this->person('Staff', []);

        $this->post("/admin/team/{$person->id}", ['role' => 'Staff', 'is_active' => true, 'pages' => ['discount', 'reports_money']])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['discount', 'bookings', 'reports_money', 'reports'], Permissions::pagesOf($person->fresh()));
        $this->actingAs($person->fresh())->get('/admin/bookings')->assertOk();
    }

    public function test_an_administrator_can_invite_and_then_edit_a_persons_pages(): void
    {
        $admin = $this->person('Administrator');
        $this->actingAs($admin);
        Password::shouldReceive('sendResetLink')->once();

        // Invite with a few boxes ticked.
        $this->post('/admin/team', ['name' => 'Synthetic Front Desk', 'email' => 'desk@example.test', 'role' => 'Booking / Reception', 'pages' => ['dashboard', 'bookings', 'confirm']])
            ->assertRedirect('/admin/team')->assertSessionHasNoErrors();
        $desk = User::where('email', 'desk@example.test')->firstOrFail();
        $this->assertEqualsCanonicalizing(['dashboard', 'bookings', 'confirm'], $this->pagesOnForm($desk));

        // Edit: add Guests and Payments, take away Confirm.
        $this->post("/admin/team/{$desk->id}", ['role' => 'Booking / Reception', 'is_active' => true, 'pages' => ['dashboard', 'bookings', 'guests', 'payments']])
            ->assertRedirect('/admin/team')->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['dashboard', 'bookings', 'guests', 'payments'], $this->pagesOnForm($desk));

        $this->actingAs($desk->fresh());
        $this->get('/admin/guests')->assertOk();
        $this->get('/admin/payments')->assertOk();
        $this->get('/admin/reports')->assertForbidden();
        $this->assertFalse($desk->fresh()->can('bookings.confirm'));

        // Clearing every box leaves no page open.
        $this->actingAs($admin)->post("/admin/team/{$desk->id}", ['role' => 'Booking / Reception', 'is_active' => true, 'pages' => []])->assertSessionHasNoErrors();
        $this->assertSame([], Permissions::pagesOf($desk->fresh()));
        $this->actingAs($desk->fresh())->get('/admin/bookings')->assertForbidden();
    }

    public function test_the_team_list_names_each_persons_access(): void
    {
        $this->person('Staff', ['reports', 'quotes', 'parking', 'guests']);
        $this->person('Staff', []);

        $access = collect($this->get('/admin/team')->assertOk()->original->getData()['page']['props']['rows'])->pluck('access');

        $this->assertContains('Every page', $access);
        $this->assertContains('No access yet', $access);
        $this->assertContains('Quotes & Messages, Guests, Parking +1 more', $access);
    }

    public function test_a_manager_starts_with_the_same_access_as_the_owner_including_confirm(): void
    {
        $this->assertEqualsCanonicalizing(Permissions::ROLE_PAGES['Owner'], Permissions::ROLE_PAGES['Operations Manager']);

        $manager = $this->person('Operations Manager');
        $this->assertTrue($manager->can('bookings.confirm'));
        $this->assertEqualsCanonicalizing(Permissions::pagesOf($this->person('Owner')), Permissions::pagesOf($manager));

        $booking = $this->inquiry();
        $this->actingAs($manager)->post("/admin/bookings/{$booking->id}/confirm")->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->get('/admin/subscription')->assertOk();
    }

    public function test_a_manager_never_sees_or_changes_owner_or_administrator_accounts(): void
    {
        $manager = $this->person('Operations Manager');
        $owner = User::role('Owner')->firstOrFail();
        $admin = User::role('Administrator')->firstOrFail();
        $reception = $this->person('Booking / Reception');

        $emails = fn () => collect($this->get('/admin/team')->assertOk()->original->getData()['page']['props']['rows'])->pluck('email')->all();

        $this->actingAs($manager);
        $listed = $emails();
        $this->assertContains($reception->email, $listed);
        $this->assertContains($manager->email, $listed);
        $this->assertNotContains($owner->email, $listed);
        $this->assertNotContains($admin->email, $listed);

        $this->get("/admin/team/{$owner->id}/edit")->assertForbidden();
        $this->get("/admin/team/{$admin->id}/edit")->assertForbidden();
        $this->post("/admin/team/{$owner->id}/temporary-password")->assertForbidden();
        $this->post("/admin/team/{$reception->id}", ['role' => 'Owner', 'is_active' => true])->assertForbidden();
        $this->assertNotContains('Owner', $this->get('/admin/team/create')->original->getData()['page']['props']['roles']);

        // The Owner still sees managers and other Owners, but not the Administrator.
        $this->actingAs($owner);
        $this->assertContains($manager->email, $emails());
        $this->assertNotContains($admin->email, $emails());
    }
}

