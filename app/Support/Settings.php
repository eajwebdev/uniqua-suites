<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Owner-configurable policy values. A null value means "pending owner decision":
 * callers must surface that state rather than assume a default.
 */
class Settings
{
    public const DEFAULTS = [
        'pricing.weekend_days' => null, // ISO weekdays (1=Mon..7=Sun); source does not define "weekend"
        'pricing.extra_guest_cents' => 20000,
        'pricing.extra_guest_unit' => null, // per_night | per_stay; historical 200/PAX is not proof of either
        'pricing.early_fee_cents_per_hour' => 20000,
        'pricing.standard_check_in' => '14:00',
        'pricing.standard_check_out' => null, // source does not establish this
        'pricing.late_fee_cents_per_hour' => null,
        'pricing.parking_night_cents' => 30000,
        'finance.downpayment_percent' => 50, // 0 turns it off
        'holds.default_hours' => 24,
        'laundry.auto_create' => true,
        'finance.require_verification_to_confirm' => false,
        'finance.expense_approval_threshold_cents' => null, // null: no amount needs approval before payment
        'finance.second_reviewer' => false, // off by default: a one-person office could not approve anything
        'housekeeping.turnover_due_hours' => 4,
        'security.require_two_factor' => false,
        'stay.min_nights' => null, // null: no limit until the owner sets one
        'stay.max_advance_days' => null,
        'policy.house_rules' => null, // shown publicly only once written
        'policy.cancellation' => null,
        'alerts.arrival_lead_days' => 1,
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = DB::table('application_settings')->where('key', $key)->value('value');

        if ($row === null) {
            return self::DEFAULTS[$key] ?? $default;
        }

        return json_decode($row, true);
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('application_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public static function all(): array
    {
        $stored = DB::table('application_settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))->all();

        return array_merge(self::DEFAULTS, $stored);
    }
}
