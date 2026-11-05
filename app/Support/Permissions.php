<?php

namespace App\Support;

use App\Models\User;

class Permissions
{
    public const ALL = [
        'dashboard.view', 'dashboard.finance',
        'bookings.view', 'bookings.manage', 'bookings.confirm', 'bookings.discount', 'bookings.override_readiness',
        'guests.view', 'guests.manage', 'quotes.manage',
        'rooms.view', 'rooms.manage', 'rates.manage',
        'payments.view', 'payments.record', 'payments.verify', 'refunds.approve', 'settlements.manage', 'periods.lock',
        'expenses.view', 'expenses.manage', 'expenses.approve',
        'reports.view', 'reports.finance', 'reports.export',
        'housekeeping.own', 'housekeeping.manage', 'housekeeping.inspect',
        'maintenance.own', 'maintenance.manage',
        'incidents.view', 'incidents.manage', 'incidents.approve',
        'inventory.view', 'inventory.manage', 'inventory.consume',
        'parking.view', 'parking.manage',
        'secrets.reveal', 'secrets.manage',
        'advances.manage',
        'team.manage', 'settings.manage', 'audit.view',
    ];

    public const ROLES = ['Administrator', 'Owner', 'Operations Manager', 'Booking / Reception', 'Staff'];

    /** These always have everything. Every other role is only a starting point for the person's own ticks. */
    public const FULL_ACCESS = ['Administrator', 'Owner'];

    /** What can be ticked for a person. Every permission belongs to exactly one entry. */
    public const PAGES = [
        'dashboard' => ['Dashboard', 'Pages', ['dashboard.view']],
        'bookings' => ['Booking Calendar and Reservations', 'Pages', ['bookings.view', 'bookings.manage']],
        'quotes' => ['Quotes & Messages', 'Pages', ['quotes.manage']],
        'guests' => ['Guests', 'Pages', ['guests.view', 'guests.manage']],
        'rooms' => ['Rooms & Properties', 'Pages', ['rooms.view']],
        'housekeeping_own' => ['Housekeeping: own cleaning tasks', 'Pages', ['housekeeping.own', 'inventory.consume']],
        'housekeeping' => ['Housekeeping: manage all tasks and laundry', 'Pages', ['housekeeping.manage']],
        'inspect' => ['Housekeeping: inspect rooms', 'Pages', ['housekeeping.inspect']],
        'maintenance_own' => ['Maintenance: own repair jobs', 'Pages', ['maintenance.own']],
        'maintenance' => ['Maintenance: manage all repairs', 'Pages', ['maintenance.manage']],
        'incidents' => ['Damage Claims', 'Pages', ['incidents.view', 'incidents.manage']],
        'inventory' => ['Inventory', 'Pages', ['inventory.view', 'inventory.manage']],
        'parking' => ['Parking', 'Pages', ['parking.view', 'parking.manage']],
        'payments' => ['Payments', 'Pages', ['payments.view', 'payments.record']],
        'expenses' => ['Expenses & Cashbook', 'Pages', ['expenses.view', 'expenses.manage']],
        'reports' => ['Reports', 'Pages', ['reports.view']],
        'advances' => ['Staff Advances', 'Pages', ['advances.manage']],
        'team' => ['Team & Permissions', 'Pages', ['team.manage']],
        'settings' => ['Settings', 'Pages', ['settings.manage']],
        'audit' => ['Audit Log', 'Pages', ['audit.view']],

        'dashboard_money' => ['See money figures on the Dashboard', 'Extra actions', ['dashboard.finance']],
        'confirm' => ['Confirm bookings', 'Extra actions', ['bookings.confirm']],
        'discount' => ['Give discounts', 'Extra actions', ['bookings.discount']],
        'override' => ['Check in before a room is ready', 'Extra actions', ['bookings.override_readiness']],
        'room_edit' => ['Edit rooms, prices and Wi-Fi details', 'Extra actions', ['rooms.manage', 'rates.manage', 'secrets.manage']],
        'wifi' => ['See Wi-Fi passwords', 'Extra actions', ['secrets.reveal']],
        'verify' => ['Verify payments and channel payouts', 'Extra actions', ['payments.verify', 'settlements.manage']],
        'refunds' => ['Approve refunds', 'Extra actions', ['refunds.approve']],
        'expense_approve' => ['Approve expenses', 'Extra actions', ['expenses.approve']],
        'lock' => ['Lock finished months', 'Extra actions', ['periods.lock']],
        'damage_charge' => ['Approve damage charges', 'Extra actions', ['incidents.approve']],
        'reports_money' => ['See money reports and export them', 'Extra actions', ['reports.finance', 'reports.export']],
    ];

    /** The ticks a new person starts with, before anyone adjusts them. */
    public const ROLE_PAGES = [
        'Operations Manager' => ['dashboard', 'bookings', 'quotes', 'guests', 'rooms', 'housekeeping_own', 'housekeeping', 'inspect', 'maintenance_own', 'maintenance', 'incidents', 'inventory', 'parking', 'reports', 'override', 'wifi'],
        'Booking / Reception' => ['dashboard', 'bookings', 'quotes', 'guests', 'rooms', 'inspect', 'incidents', 'parking', 'payments', 'confirm', 'wifi'],
        'Staff' => ['housekeeping_own', 'maintenance_own'],
    ];

    public static function isFullAccess(string $role): bool
    {
        return in_array($role, self::FULL_ACCESS, true);
    }

    /** What the role itself carries. Other roles carry nothing, so unticking a page really removes it. */
    public static function forRole(string $role): array
    {
        return self::isFullAccess($role) ? self::ALL : [];
    }

    public static function forPages(array $pages): array
    {
        return collect($pages)->flatMap(fn ($page) => self::PAGES[$page][2] ?? [])->unique()->values()->all();
    }

    /** Passing no pages gives the role's starting set. */
    public static function apply(User $user, string $role, ?array $pages = null): void
    {
        $user->syncRoles([$role]);
        $user->syncPermissions(self::isFullAccess($role) ? [] : self::forPages($pages ?? self::ROLE_PAGES[$role] ?? []));
    }

    /** Ticked only when the person has the whole entry, so a tick always means the same thing. */
    public static function pagesOf(User $user): array
    {
        $held = $user->getAllPermissions()->pluck('name')->all();

        return collect(self::PAGES)->filter(fn ($page) => array_diff($page[2], $held) === [])->keys()->all();
    }
}
