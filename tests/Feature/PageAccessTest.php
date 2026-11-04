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
        $form = $this->get('/admin/team/create')->assertOk()->original->getData()['page']['props'];
        $this->assertSame(Permissions::ROLES, $form['roles']);

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

    public function test_full_access_roles_always_have_everything_whatever_is_ticked(): void
    {
        foreach (['Administrator', 'Owner'] as $role) {
            $person = $this->person($role, ['reports']);
            $this->assertCount(count(Permissions::ALL), $person->getAllPermissions(), $role);
            $this->assertCount(0, $person->getDirectPermissions(), $role);
            $this->actingAs($person)->get('/admin/settings')->assertOk();
            $this->actingAs($this->owner);
        }
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

    public function test_only_an_owner_can_change_an_owner_or_make_one(): void
    {
        $admin = $this->person('Administrator');
        $second = $this->person('Owner');
        $person = $this->person('Staff', []);
        $this->actingAs($admin);

        $this->post("/admin/team/{$second->id}", ['role' => 'Staff', 'is_active' => false])->assertForbidden();
        $this->post("/admin/team/{$person->id}", ['role' => 'Owner', 'is_active' => true])->assertForbidden();
        $this->get("/admin/team/{$second->id}/edit")->assertForbidden();

        $this->post("/admin/team/{$person->id}", ['role' => 'Administrator', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole('Administrator'));
    }

    public function test_the_two_factor_of_an_owner_can_only_be_reset_by_an_owner(): void
    {
        $this->actingAs($this->person('Administrator'));

        $this->post("/admin/team/{$this->owner->id}/reset-two-factor")->assertForbidden();
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
}
