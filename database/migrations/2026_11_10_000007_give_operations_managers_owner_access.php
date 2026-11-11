<?php

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * By owner decision an Operations Manager has the same access as the Owner, Confirm bookings included.
 * Existing managers get every page the Owner starts with, added to what they already have.
 * The Team screen still keeps Owner and Administrator accounts away from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (! Role::where('name', 'Operations Manager')->where('guard_name', 'web')->exists()) {
            return; // a fresh install: the seeder gives new managers this access
        }
        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (User::role('Operations Manager')->get() as $manager) {
            $manager->givePermissionTo(Permissions::forPages(Permissions::ROLE_PAGES['Owner']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Access given here cannot be told apart from access ticked by hand afterwards, so it is left in place.
    }
};
