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

    /** Each extra action works on one page, so ticking the action also ticks that page. */
    public const REQUIRES = [
        'dashboard_money' => 'dashboard',
        'confirm' => 'bookings', 'discount' => 'bookings', 'override' => 'bookings',
        'room_edit' => 'rooms', 'wifi' => 'rooms',
        'verify' => 'payments', 'refunds' => 'payments', 'lock' => 'payments',
        'expense_approve' => 'expenses',
        'damage_charge' => 'incidents',
        'reports_money' => 'reports',
    ];

    /** How the Team screen groups the pages, in order. */
    public const AREAS = [
        'Front desk' => ['dashboard', 'bookings', 'quotes', 'guests', 'rooms', 'parking'],
        'Cleaning & repairs' => ['housekeeping_own', 'housekeeping', 'inspect', 'maintenance_own', 'maintenance', 'incidents', 'inventory'],
        'Money' => ['payments', 'expenses', 'reports', 'advances'],
        'Admin' => ['team', 'settings', 'audit'],
    ];

    /** One line under each box on the Team screen, saying what it lets the person do. */
    public const HELP = [
        'dashboard' => "Today's arrivals, pickups, departures and alerts.",
        'bookings' => 'Calendar and reservations for suites and camera rentals: create, change, check in and out.',
        'quotes' => 'Prepare quotations and copy ready-to-send messages.',
        'guests' => 'Guest and renter records and their booking history.',
        'rooms' => 'Suites, cameras and their rate cards (view only unless "Edit rooms" is ticked).',
        'parking' => 'Parking calendar and assignments.',
        'housekeeping_own' => 'Only the cleaning tasks given to this person, plus using supplies.',
        'housekeeping' => 'All cleaning tasks and laundry: assign, schedule and follow up.',
        'inspect' => 'Pass or fail a cleaned room so it can be checked in.',
        'maintenance_own' => 'Only the repair jobs given to this person.',
        'maintenance' => 'All repair jobs: create, assign and close.',
        'incidents' => 'Damage reports with photos and notes.',
        'inventory' => 'Supplies, stock levels and movements.',
        'payments' => 'Record payments, see receipts, channel payouts and reconciliation.',
        'expenses' => 'Expenses and the cashbook.',
        'reports' => 'Occupancy and booking reports.',
        'advances' => 'Staff cash advances and repayments.',
        'team' => 'This screen: invite people and choose their access.',
        'settings' => 'Business rules, rates policy, message templates and security.',
        'audit' => 'Who changed what, and when.',
        'dashboard_money' => 'Earnings, balances and other peso amounts on the Dashboard.',
        'confirm' => 'Turn an inquiry or hold into a confirmed booking.',
        'discount' => 'Lower the price of a booking.',
        'override' => 'Check a guest in before housekeeping marks the room ready.',
        'room_edit' => 'Change room and camera details, rates and Wi-Fi details.',
        'wifi' => 'Reveal the saved Wi-Fi passwords.',
        'verify' => 'Mark payments and channel payouts as verified.',
        'refunds' => 'Approve refunds.',
        'lock' => 'Close a finished month so it cannot be changed silently.',
        'expense_approve' => 'Approve submitted expenses.',
        'damage_charge' => 'Approve charging a guest for damage.',
        'reports_money' => 'Revenue and finance reports, and exporting them.',
    ];

    /** Adds the page each ticked action works on. */
    public static function withRequired(array $pages): array
    {
        foreach ($pages as $page) {
            if (isset(self::REQUIRES[$page])) {
                $pages[] = self::REQUIRES[$page];
            }
        }

        return array_values(array_unique($pages));
    }

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
