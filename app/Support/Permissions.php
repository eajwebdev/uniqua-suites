<?php

namespace App\Support;

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
        'imports.manage',
        'team.manage', 'settings.manage', 'audit.view',
    ];

    public const ROLES = [
        'Owner' => self::ALL,
        'Operations Manager' => [
            'dashboard.view', 'bookings.view', 'bookings.manage', 'bookings.override_readiness', 'guests.view', 'guests.manage', 'quotes.manage',
            'rooms.view', 'reports.view', 'housekeeping.manage', 'housekeeping.inspect', 'housekeeping.own',
            'maintenance.manage', 'maintenance.own', 'incidents.view', 'incidents.manage', 'inventory.view', 'inventory.manage', 'inventory.consume',
            'parking.view', 'parking.manage', 'secrets.reveal',
        ],
        'Booking / Reception' => [
            'dashboard.view', 'bookings.view', 'bookings.manage', 'bookings.confirm', 'guests.view', 'guests.manage', 'quotes.manage',
            'rooms.view', 'payments.view', 'payments.record', 'housekeeping.inspect', 'incidents.view', 'incidents.manage', 'parking.view', 'parking.manage', 'secrets.reveal',
        ],
        'Finance / Bookkeeper' => [
            'dashboard.view', 'dashboard.finance', 'bookings.view', 'guests.view', 'rooms.view',
            'payments.view', 'payments.record', 'payments.verify', 'settlements.manage',
            'expenses.view', 'expenses.manage', 'reports.view', 'reports.finance', 'reports.export', 'incidents.view',
        ],
        'Housekeeping / Laundry' => ['housekeeping.own', 'inventory.consume'],
        'Maintenance' => ['maintenance.own'],
        'Staff Advances / Payroll' => ['advances.manage'],
        'Auditor' => ['dashboard.view', 'dashboard.finance', 'bookings.view', 'rooms.view', 'payments.view', 'expenses.view', 'reports.view', 'reports.finance', 'reports.export', 'audit.view'],
    ];
}
