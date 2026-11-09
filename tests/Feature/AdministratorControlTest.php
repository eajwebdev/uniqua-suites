<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Tests\DomainTestCase;

class AdministratorControlTest extends DomainTestCase
{
    private function person(string $role, ?array $pages = null): User
    {
        $user = User::factory()->create();
        Permissions::apply($user, $role, $pages);

        return $user;
    }

    public function test_an_owner_starts_with_every_page_but_it_is_ticked_not_built_in(): void
    {
        $owner = $this->person('Owner');

        $this->assertSame(array_keys(Permissions::PAGES), Permissions::pagesOf($owner));
        $this->assertTrue($owner->can('settings.manage'));
        $this->assertSame([], \Spatie\Permission\Models\Role::findByName('Owner')->permissions->pluck('name')->all(), 'The Owner role itself carries nothing, so its pages can be changed.');
    }

    public function test_the_administrator_chooses_an_owners_pages(): void
    {
        $admin = $this->person('Administrator');
        $owner = $this->person('Owner');

        $this->actingAs($admin)->get("/admin/team/{$owner->id}/edit")->assertOk();
        $this->post("/admin/team/{$owner->id}", ['role' => 'Owner', 'is_active' => true, 'pages' => ['dashboard', 'bookings', 'payments']])->assertSessionHasNoErrors();

        $owner->refresh();
        $this->assertSame(['dashboard', 'bookings', 'payments'], Permissions::pagesOf($owner));
        $this->assertFalse($owner->can('settings.manage'));
        $this->assertTrue($owner->hasRole('Owner'), 'Still the Owner: subscription notices and the last-owner rule still apply.');
        $this->actingAs($owner)->get('/admin/settings')->assertForbidden();
    }

    public function test_an_owner_cannot_see_or_change_the_administrator(): void
    {
        $admin = $this->person('Administrator');
        $admin->update(['name' => 'EAJ Administrator']);
        $owner = $this->person('Owner');

        $names = array_column(array_column($this->actingAs($owner)->get('/admin/team')->original->getData()['page']['props']['rows'], 'name'), 'label');
        $this->assertNotContains('EAJ Administrator', $names);
        $this->assertContains($owner->name, $names);
        $this->get("/admin/team/{$admin->id}/edit")->assertForbidden();
        $this->post("/admin/team/{$admin->id}", ['role' => 'Administrator', 'is_active' => false])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);

        // The Administrator sees everyone.
        $names = array_column(array_column($this->actingAs($admin)->get('/admin/team')->original->getData()['page']['props']['rows'], 'name'), 'label');
        $this->assertContains('EAJ Administrator', $names);
        $this->assertContains($owner->name, $names);
    }
}
