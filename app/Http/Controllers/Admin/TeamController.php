<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index()
    {
        $roles = collect(array_keys(Permissions::ROLES))->map(fn ($r) => ['value' => $r, 'label' => $r])->all();

        return Inertia::render('Admin/Resource', [
            'title' => 'Team & Permissions',
            'subtitle' => 'Invited staff set their own password through a reset link. Deactivating removes access on the next request.',
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'role', 'label' => 'Role'], ['key' => 'state', 'label' => 'Status', 'type' => 'status']],
            'rows' => User::with('roles')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->roles->pluck('name')->join(', '),
                'state' => $u->is_active ? 'active' : 'deactivated',
                '_actions' => [[
                    'label' => 'Edit',
                    'url' => "/admin/team/{$u->id}",
                    'fields' => [
                        ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'default' => $u->roles->first()?->name, 'options' => $roles],
                        ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'default' => $u->is_active],
                    ],
                ]],
            ]),
            'create' => ['label' => 'Invite staff', 'url' => '/admin/team', 'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'required' => true],
                ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'options' => $roles],
            ]],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:120|unique:users,email', 'role' => ['required', Rule::in(array_keys(Permissions::ROLES))]]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40)]);
        $user->assignRole($data['role']);
        Password::sendResetLink(['email' => $user->email]);
        Audit::log('team.invited', $user, ['role' => $data['role']]);

        return back()->with('success', "Invitation sent to {$user->email}.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(Permissions::ROLES))], 'is_active' => 'nullable|boolean']);
        $active = (bool) ($data['is_active'] ?? false);

        // Stops the system being left with nobody able to administer it.
        $losingOwner = $user->hasRole('Owner') && ($data['role'] !== 'Owner' || ! $active);
        if ($losingOwner && User::role('Owner')->where('is_active', true)->count() <= 1) {
            throw new RoomConflict('This is the only active Owner. Add another Owner first.');
        }

        $user->syncRoles([$data['role']]);
        $user->update(['is_active' => $active]);
        Audit::log('team.updated', $user, ['role' => $data['role'], 'active' => $active]);

        return back()->with('success', 'Staff member updated.');
    }
}
