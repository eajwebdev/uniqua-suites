<?php

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Owner role used to carry every permission, so nobody could narrow an Owner's access.
 * Now the role carries nothing and each Owner holds their pages directly, starting with all of
 * them, so they keep exactly what they had and the Administrator can untick pages per Owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (! Role::where('name', 'Owner')->where('guard_name', 'web')->exists()) {
            return; // a fresh install: the seeder sets roles up this way
        }
        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (User::role('Owner')->get() as $owner) {
            $owner->givePermissionTo(Permissions::forPages(Permissions::ROLE_PAGES['Owner']));
        }
        Role::findByName('Owner', 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (Role::where('name', 'Owner')->where('guard_name', 'web')->exists()) {
            Role::findByName('Owner', 'web')->syncPermissions(Permissions::ALL);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
