<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TwoFactorController;
use App\Models\User;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Resource', [
            'title' => 'Team & Permissions',
            'subtitle' => 'Click a name to choose the pages that person can open. Invited staff set their own password through a reset link. Deactivating removes access on the next request.',
            'columns' => [['key' => 'name', 'label' => 'Name', 'type' => 'link'], ['key' => 'email', 'label' => 'Email'], ['key' => 'role', 'label' => 'Role'], ['key' => 'access', 'label' => 'Access'], ['key' => 'state', 'label' => 'Status', 'type' => 'status'], ['key' => 'two_factor', 'label' => 'Two-factor']],
            'rows' => User::with('roles')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => ['label' => $u->name, 'href' => "/admin/team/{$u->id}/edit"],
                'email' => $u->email,
                'role' => $u->roles->pluck('name')->join(', '),
                'access' => $this->accessLabel($u),
                'state' => $u->is_active ? 'active' : 'deactivated',
                'two_factor' => $u->hasTwoFactor() ? 'On' : 'Off',
                '_actions' => $u->hasTwoFactor() ? [[
                    'label' => 'Reset two-factor',
                    'url' => "/admin/team/{$u->id}/reset-two-factor",
                    'confirm' => "Turn off two-factor sign-in for {$u->name}? Use this only after confirming their identity, for example a lost phone.",
                    'variant' => 'danger',
                ]] : [],
            ]),
            'headerLink' => ['label' => 'Invite staff', 'href' => '/admin/team/create'],
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Admin/TeamEdit', $this->formProps($request->user()) + ['person' => (object) []]);
    }

    public function edit(Request $request, User $user)
    {
        $this->assertCanManage($request->user(), $user);

        return Inertia::render('Admin/TeamEdit', $this->formProps($request->user()) + ['person' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roles->first()?->name ?? 'Staff',
            'is_active' => (bool) $user->is_active,
            'pages' => Permissions::pagesOf($user),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:120|unique:users,email', 'role' => ['required', Rule::in(Permissions::ROLES)], 'pages' => 'nullable|array', 'pages.*' => ['string', Rule::in(array_keys(Permissions::PAGES))]]);
        $this->assertCanGiveRole($request->user(), $data['role']);
        $pages = $data['pages'] ?? Permissions::ROLE_PAGES[$data['role']] ?? [];
        $this->assertCanGrant($request->user(), Permissions::isFullAccess($data['role']) ? [] : $pages, []);

        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40)]);
        Permissions::apply($user, $data['role'], $pages);
        Password::sendResetLink(['email' => $user->email]);
        Audit::log('team.invited', $user, ['role' => $data['role'], 'pages' => $pages]);

        return redirect('/admin/team')->with('success', "Invitation sent to {$user->email}.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(Permissions::ROLES)], 'is_active' => 'nullable|boolean', 'pages' => 'nullable|array', 'pages.*' => ['string', Rule::in(array_keys(Permissions::PAGES))]]);
        $active = (bool) ($data['is_active'] ?? false);
        $editor = $request->user();

        // Stops the system being left with nobody able to administer it.
        $losingOwner = $user->hasRole('Owner') && ($data['role'] !== 'Owner' || ! $active);
        if ($losingOwner && User::role('Owner')->where('is_active', true)->count() <= 1) {
            throw new RoomConflict('This is the only active Owner. Add another Owner first.');
        }

        $this->assertCanManage($editor, $user);
        $this->assertCanGiveRole($editor, $data['role']);

        $before = Permissions::pagesOf($user);
        $roleChanged = ! $user->hasRole($data['role']);
        // No ticks sent: keep what they have, unless a new role brings its own starting set.
        $pages = $request->has('pages') ? ($data['pages'] ?? []) : ($roleChanged ? (Permissions::ROLE_PAGES[$data['role']] ?? []) : $before);

        if (! Permissions::isFullAccess($data['role'])) {
            $pages = $this->assertCanGrant($editor, $pages, $before);
        }

        Permissions::apply($user, $data['role'], $pages);
        $user->update(['is_active' => $active]);
        Audit::log('team.updated', $user, ['role' => $data['role'], 'active' => $active, 'pages' => Permissions::isFullAccess($data['role']) ? 'all' : $pages]);

        return redirect('/admin/team')->with('success', "Access saved for {$user->name}.");
    }

    private function accessLabel(User $user): string
    {
        if ($user->roles->pluck('name')->contains(fn ($r) => Permissions::isFullAccess($r))) {
            return 'Everything';
        }
        $count = count(Permissions::pagesOf($user));

        return $count === 1 ? '1 item' : "{$count} items";
    }

    private function formProps(User $editor): array
    {
        return [
            'roles' => array_values(array_filter(Permissions::ROLES, fn ($role) => $this->mayGiveRole($editor, $role))),
            'fullAccess' => Permissions::FULL_ACCESS,
            'presets' => Permissions::ROLE_PAGES + ['Administrator' => [], 'Owner' => []],
            'pages' => collect(Permissions::PAGES)->map(fn ($page, $key) => ['key' => $key, 'label' => $page[0], 'group' => $page[1]])->values(),
            'allowed' => Permissions::pagesOf($editor),
        ];
    }

    private function mayGiveRole(User $editor, string $role): bool
    {
        return ! Permissions::isFullAccess($role) || $editor->hasRole('Owner') || ($role === 'Administrator' && $editor->hasRole('Administrator'));
    }

    private function assertCanGiveRole(User $editor, string $role): void
    {
        abort_unless($this->mayGiveRole($editor, $role), 403, "You cannot give the {$role} role.");
    }

    /** Only an Owner may change an Owner, and only full-access people may change an Administrator. */
    private function assertCanManage(User $editor, User $target): void
    {
        $allowed = match (true) {
            $target->hasRole('Owner') => $editor->hasRole('Owner'),
            $target->hasRole('Administrator') => $editor->hasAnyRole(Permissions::FULL_ACCESS),
            default => true,
        };
        abort_unless($allowed, 403, "You cannot change {$target->name}'s access.");
    }

    /**
     * Nobody can hand out access they lack themselves, which also stops them raising their own.
     * Boxes they cannot change stay as they were. Returns the pages to save.
     */
    private function assertCanGrant(User $editor, array $pages, array $before): array
    {
        $own = Permissions::pagesOf($editor);
        $beyond = array_values(array_diff($pages, $own, $before));
        if ($beyond !== []) {
            throw ValidationException::withMessages(['pages' => 'You can only give access you have yourself. Not allowed: '.collect($beyond)->map(fn ($key) => Permissions::PAGES[$key][0])->join(', ').'.']);
        }

        return array_values(array_unique([...$pages, ...array_diff($before, $own)]));
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $this->assertCanManage($request->user(), $user);
        TwoFactorController::clear($user);
        Audit::log('team.two_factor_reset', $user);

        return back()->with('success', "Two-factor sign-in is off for {$user->name}. They can set it up again under Account.");
    }
}
