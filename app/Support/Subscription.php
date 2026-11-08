<?php

namespace App\Support;

use App\Models\SubscriptionBill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * The system's own monthly subscription, set by the Administrator (EAJ).
 *
 * The system is paid through the later of the trial end and the subscription end. After that
 * comes a grace period with daily reminders; once it passes, the admin side locks for everyone
 * but the Administrator. The public website always stays online. With no dates set, nothing locks.
 */
final class Subscription
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const DUE = 'due';           // past the paid-through date, still inside the grace period

    public const LOCKED = 'locked';

    public const NOT_SET = 'not_set';

    /** Remind this many days before the paid-through date, and every day of the grace period. */
    public const REMIND_BEFORE = [3, 1];

    public const DEFAULT_FEE_CENTS = 400000;

    public const DEFAULT_GRACE_DAYS = 3;

    private const KEYS = ['monthly_fee_cents', 'trial_start', 'trial_end', 'start', 'end', 'grace_days'];

    public static function settings(): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = Settings::get("subscription.{$key}");
        }
        $values['monthly_fee_cents'] ??= self::DEFAULT_FEE_CENTS;
        $values['grace_days'] = (int) ($values['grace_days'] ?? self::DEFAULT_GRACE_DAYS);

        return $values;
    }

    public static function save(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                Settings::set("subscription.{$key}", $values[$key]);
            }
        }
    }

    /** The last day the system is paid for: the later of the trial end and the subscription end. */
    public static function paidThrough(?array $s = null): ?string
    {
        $s ??= self::settings();
        $dates = array_filter([$s['trial_end'], $s['end']]);

        return $dates ? max($dates) : null;
    }

    /** The first day the admin side is locked if nothing is paid. */
    public static function locksOn(?array $s = null): ?string
    {
        $s ??= self::settings();
        $through = self::paidThrough($s);

        return $through ? CarbonImmutable::parse($through)->addDays($s['grace_days'] + 1)->toDateString() : null;
    }

    public static function status(?string $today = null): string
    {
        $s = self::settings();
        $today ??= Local::today();
        $through = self::paidThrough($s);

        return match (true) {
            $through === null => self::NOT_SET,
            $today <= $through => self::inTrial($s, $today) ? self::TRIAL : self::ACTIVE,
            $today < self::locksOn($s) => self::DUE,
            default => self::LOCKED,
        };
    }

    /** Up to the trial end, while the paid subscription has not started yet. */
    private static function inTrial(array $s, string $today): bool
    {
        // Days before the trial starts count as trial too: nothing is billed until the subscription starts.
        return $s['trial_end'] && $today <= $s['trial_end']
            && (! $s['start'] || $today < $s['start']);
    }

    public static function date(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('M j, Y') : '';
    }

    public static function isLocked(): bool
    {
        return self::status() === self::LOCKED;
    }

    /** The Administrator manages the subscription and is never locked out; everyone else waits for payment. */
    public static function canManage(?User $user): bool
    {
        return (bool) $user?->hasRole('Administrator');
    }

    /** Whole days from today to a date: positive in the future, negative in the past. */
    public static function daysUntil(?string $date, ?string $today = null): ?int
    {
        if (! $date) {
            return null;
        }

        return (int) CarbonImmutable::parse($today ?? Local::today())->diffInDays(CarbonImmutable::parse($date), false);
    }

    /** Everything a page needs to show where the subscription stands. */
    public static function summary(): array
    {
        $s = self::settings();
        $status = self::status();
        $through = self::paidThrough($s);
        $locksOn = self::locksOn($s);

        return [
            'status' => $status,
            'label' => self::label($status),
            'fee_cents' => (int) $s['monthly_fee_cents'],
            'trial_start' => $s['trial_start'],
            'trial_end' => $s['trial_end'],
            'start' => $s['start'],
            'end' => $s['end'],
            'grace_days' => $s['grace_days'],
            'paid_through' => $through,
            'days_left' => self::daysUntil($through),
            'locks_on' => $locksOn,
            'days_to_lock' => self::daysUntil($locksOn),
            'message' => self::message($status, $s, $through, $locksOn),
        ];
    }

    public static function label(string $status): string
    {
        return [
            self::TRIAL => 'Free trial',
            self::ACTIVE => 'Active',
            self::DUE => 'Payment due',
            self::LOCKED => 'Locked',
            self::NOT_SET => 'Not set up',
        ][$status];
    }

    /** One sentence for the banner, the bell and the locked screen. Null when there is nothing to say. */
    public static function message(string $status, array $s, ?string $through, ?string $locksOn, ?string $today = null): ?string
    {
        // The open bill's amount, so a fee change only affects bills made after it.
        $fee = Money::format(self::openBill()?->amount_cents ?? (int) $s['monthly_fee_cents']);
        $days = self::daysUntil($through, $today);

        return match ($status) {
            self::TRIAL, self::ACTIVE => $days !== null && $days <= max(self::REMIND_BEFORE)
                ? ($status === self::TRIAL ? 'The free trial' : 'The system subscription').' ends '.self::when($days).". Please pay {$fee} to keep using the system."
                : null,
            self::DUE => 'The system subscription ended on '.self::date($through).". Please pay {$fee} by ".self::date(CarbonImmutable::parse($locksOn)->subDay()->toDateString()).' or the system locks on '.self::date($locksOn).'. The website stays online.',
            self::LOCKED => 'The system is locked because the subscription that ended on '.self::date($through)." is unpaid. Please pay {$fee} to unlock it. Your website stays online and no data is lost.",
            default => null,
        };
    }

    /** The corner badge on admin pages: on trial, expired or locked. Paid (or not set up) shows nothing. */
    public static function badge(?string $today = null): ?array
    {
        $today ??= Local::today();
        $s = self::settings();
        $status = self::status($today);
        $days = fn (?string $date) => self::daysUntil($date, $today);
        $count = fn (int $n) => $n === 1 ? '1 day' : "{$n} days";

        return match ($status) {
            // Paid ahead during the trial counts as paid: nothing to show.
            self::TRIAL => SubscriptionBill::where('status', SubscriptionBill::PAID)->exists() ? null : ['status' => $status, 'title' => 'Free trial', 'detail' => $days($s['trial_end']) === 0 ? 'ends today' : $count($days($s['trial_end'])).' left'],
            self::DUE => ['status' => $status, 'title' => 'Subscription expired', 'detail' => 'locks in '.$count($days(self::locksOn($s)))],
            self::LOCKED => ['status' => $status, 'title' => 'System locked', 'detail' => 'unpaid'],
            default => null,
        };
    }

    /** For the banner on admin pages: only while the end is near, payment is due, or the system is locked. */
    public static function banner(): ?array
    {
        $summary = self::summary();

        return $summary['message'] ? [
            'status' => $summary['status'],
            'label' => $summary['label'],
            'message' => $summary['message'],
            'url' => '/admin/subscription',
        ] : null;
    }

    /**
     * The daily bell reminder for the Owner and Administrator: 3 days and 1 day before the end, on the
     * end day, every day of the grace period, and the day it locks. At most once a day. Returns the
     * message sent, or null.
     */
    public static function remind(?string $today = null): ?string
    {
        $today ??= Local::today();
        $s = self::settings();
        $status = self::status($today);
        $days = self::daysUntil(self::paidThrough($s), $today);

        $due = match ($status) {
            self::TRIAL, self::ACTIVE => in_array($days, [...self::REMIND_BEFORE, 0], true),
            self::DUE => true,
            self::LOCKED => self::daysUntil(self::locksOn($s), $today) === 0,
            default => false,
        };
        if (! $due || Settings::get('subscription.last_reminder') === $today) {
            return null;
        }

        $message = self::message($status, $s, self::paidThrough($s), self::locksOn($s), $today);
        User::role(['Owner', 'Administrator'])->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new SystemNotice($message, '/admin/subscription')));
        Settings::set('subscription.last_reminder', $today);

        return $message;
    }

    private static function when(int $days): string
    {
        return match (true) {
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            default => "in {$days} days",
        };
    }

    /**
     * Makes sure the bill after the last one exists: the first from the subscription start, then one
     * month after another. Returns the open (unpaid) bill, if any.
     */
    public static function ensureBill(): ?SubscriptionBill
    {
        $s = self::settings();
        if (! $s['start']) {
            return null;
        }
        if ($open = self::openBill()) {
            return $open;
        }

        $start = CarbonImmutable::parse($s['start']);
        $last = SubscriptionBill::orderByDesc('period_start')->first();
        $from = $last ? CarbonImmutable::parse($last->period_end)->addDay() : $start;
        // Every period ends the day before the start's day of the month (Nov 23 → Dec 22 → Jan 22),
        // so a start on the 31st does not drift after a short month.
        $months = 1;
        while ($start->addMonthsNoOverflow($months)->subDay()->lt($from)) {
            $months++;
        }

        return SubscriptionBill::create([
            'period_start' => $from->toDateString(),
            'period_end' => $start->addMonthsNoOverflow($months)->subDay()->toDateString(),
            'due_on' => $from->toDateString(),
            'amount_cents' => (int) $s['monthly_fee_cents'],
            'status' => SubscriptionBill::UNPAID,
        ]);
    }

    /**
     * Marks a bill paid: the system is then paid through the end of its month, which unlocks it at once,
     * and the next month's bill is created. Returns that next bill.
     */
    public static function markPaid(SubscriptionBill $bill, string $paidOn, int $amountCents, ?string $note, ?int $by): ?SubscriptionBill
    {
        return DB::transaction(function () use ($bill, $paidOn, $amountCents, $note, $by) {
            $bill->update(['status' => SubscriptionBill::PAID, 'paid_on' => $paidOn, 'paid_amount_cents' => $amountCents, 'note' => $note, 'recorded_by' => $by]);

            $end = self::settings()['end'];
            $through = $bill->period_end->toDateString();
            self::save(['end' => $end && $end > $through ? $end : $through]);

            $next = self::ensureBill();
            self::clearReminders();

            return $next;
        });
    }

    /**
     * Removes subscription reminders from every bell once they no longer apply (a bill was paid or the dates
     * changed), and lets today's reminder go out again if one is still due.
     */
    public static function clearReminders(): int
    {
        $stale = DatabaseNotification::where('type', SystemNotice::class)->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['url'] ?? null) === '/admin/subscription');
        DatabaseNotification::whereIn('id', $stale->pluck('id'))->delete();
        Settings::set('subscription.last_reminder', null);

        return $stale->count();
    }

    /** The bill to pay next. */
    public static function openBill(): ?SubscriptionBill
    {
        return SubscriptionBill::where('status', SubscriptionBill::UNPAID)->orderBy('period_start')->first();
    }
}
