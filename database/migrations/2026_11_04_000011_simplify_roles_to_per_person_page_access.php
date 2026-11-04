<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Five roles remain, and access now belongs to the person. Everyone keeps exactly what they had:
 * the permissions of their old role become their own, and anyone on a removed role becomes Staff.
 */
return new class extends Migration
{
    private const REMOVED = ['Finance / Bookkeeper', 'Housekeeping / Laundry', 'Maintenance', 'Staff Advances / Payroll', 'Auditor'];

    private const KEPT_LIMITED = ['Operations Manager', 'Booking / Reception'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $staff = Role::findOrCreate('Staff', 'web');
        $admin = Role::findOrCreate('Administrator', 'web');
        $admin->syncPermissions(Permission::all());

        foreach ([...self::KEPT_LIMITED, ...self::REMOVED] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                continue;
            }
            $permissions = $role->permissions->pluck('name')->all();
            foreach (User::role($name)->get() as $user) {
                $user->givePermissionTo($permissions);
                if (in_array($name, self::REMOVED, true)) {
                    $user->syncRoles([$staff]);
                }
            }
            in_array($name, self::REMOVED, true) ? $role->delete() : $role->syncPermissions([]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Access is kept on each person, so there is nothing to restore safely.
    }
};
