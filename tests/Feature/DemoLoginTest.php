<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\DomainTestCase;

class DemoLoginTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth()->logout();
    }

    private function accounts(): array
    {
        return $this->get('/login')->assertOk()->original->getData()['page']['props']['demoAccounts'];
    }

    public function test_it_is_off_unless_switched_on(): void
    {
        $this->assertSame([], $this->accounts());

        $this->post('/login/demo/owner')->assertNotFound();
        $this->assertGuest();
    }

    public function test_production_refuses_it_even_when_switched_on(): void
    {
        config(['uniqa.demo' => true]);
        $this->app['env'] = 'production';

        $this->assertSame([], $this->accounts());

        // Production also turns CSRF checks back on, which would hide whether the guard itself holds.
        $this->withoutMiddleware(ValidateCsrfToken::class)->post('/login/demo/owner')->assertNotFound();
        $this->assertGuest();
    }

    public function test_the_doctor_fails_a_production_site_with_it_left_on(): void
    {
        config(['app.env' => 'production', 'uniqa.demo' => true]);
        $this->app['env'] = 'production';

        $this->artisan('uniqa:doctor')->expectsOutputToContain('Demo one-click sign-in is off')->assertFailed();
    }

    public function test_every_role_signs_in_with_one_click_and_gets_only_that_roles_access(): void
    {
        config(['uniqa.demo' => true]);
        Role::findByName('Staff')->syncPermissions([]);
        Role::findByName('Operations Manager')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertCount(5, $this->accounts());

        $this->post('/login/demo/staff')->assertRedirect('/admin');
        $user = auth()->user();
        $this->assertSame('staff@demo.uniqa-suites.test', $user->email);
        $this->assertSame(['Staff'], $user->getRoleNames()->all());
        $this->get('/admin/reports')->assertForbidden();
        $this->post('/admin/logout');

        $this->post('/login/demo/operations-manager')->assertRedirect('/admin');
        $this->get('/admin/reports')->assertOk();
    }

    public function test_the_owner_button_opens_the_existing_owner_and_leaves_its_password_alone(): void
    {
        config(['uniqa.demo' => true]);
        $before = $this->owner->fresh()->password;

        $this->post('/login/demo/owner')->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->owner->fresh());
        $this->assertSame($before, $this->owner->fresh()->password);
        $this->assertSame(1, User::role('Owner')->count());
    }

    public function test_clicking_again_reuses_the_account_and_each_sign_in_is_on_record(): void
    {
        config(['uniqa.demo' => true]);
        $this->post('/login/demo/staff');
        $this->post('/admin/logout');
        $this->post('/login/demo/staff');

        $this->assertSame(1, User::where('email', 'staff@demo.uniqa-suites.test')->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.demo_login']);
    }

    public function test_an_unknown_role_or_a_deactivated_account_is_refused(): void
    {
        config(['uniqa.demo' => true]);
        $this->post('/login/demo/superuser')->assertNotFound();

        $this->post('/login/demo/staff');
        $this->post('/admin/logout');
        User::where('email', 'staff@demo.uniqa-suites.test')->update(['is_active' => false]);

        $this->post('/login/demo/staff')->assertNotFound();
        $this->assertGuest();
    }
}
