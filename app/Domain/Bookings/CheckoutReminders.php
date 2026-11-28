<?php

namespace App\Domain\Bookings;

use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\Property;
use App\Support\Local;
use App\Support\Money;
use App\Support\Settings;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;

/**
 * A few hours before an in-house guest checks out, or a rented camera is due back, the people who
 * handle it are told: front desk to settle the balance, housekeeping to line up the cleaner.
 * Runs every few minutes from the scheduler. Each check-out time is reminded once; a stay that is
 * extended to a later time is reminded again for the new time.
 */
class CheckoutReminders
{
    public const DEFAULT_HOURS = 2;

    public function __construct(private FinanceService $finance)
    {
    }

    public static function leadHours(): int
    {
        return max(1, (int) (Settings::get('alerts.checkout_lead_hours') ?? self::DEFAULT_HOURS));
    }

    /** @return int how many reminders went out */
    public function send(): int
    {
        $now = Local::now();
        $until = $now->addHours(self::leadHours());
        $standard = Settings::get('pricing.standard_check_out') ?? '12:00';
        $sent = 0;

        Booking::where('status', Booking::CHECKED_IN)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->whereNotNull('return_at')->where('return_at', '<=', $until->format('Y-m-d H:i:s')))
                ->orWhere(fn ($s) => $s->whereNull('return_at')->where('check_out', '<=', $until->toDateString())))
            ->with(['property', 'activeRooms.room'])
            ->get()
            ->each(function (Booking $booking) use ($now, $until, $standard, &$sent) {
                $due = $booking->return_at
                    ? CarbonImmutable::parse($booking->return_at, Local::tz())
                    : CarbonImmutable::parse($booking->check_out->toDateString().' '.$standard, Local::tz());
                $key = $due->format('Y-m-d H:i:s');
                // Already past is a late check-out, which the dashboard shows; this is a heads-up only.
                if ($due->lte($now) || $due->gt($until) || $booking->checkout_reminded_for === $key) {
                    return;
                }
                $booking->forceFill(['checkout_reminded_for' => $key])->save();
                $this->notify($booking, $due, $now);
                $sent++;
            });

        return $sent;
    }

    private function notify(Booking $booking, CarbonImmutable $due, CarbonImmutable $now): void
    {
        $camera = $booking->property?->category === Property::CAMERAS;
        $what = $booking->activeRooms->map(fn ($r) => $r->room?->name)->filter()->implode(', ') ?: $booking->reference;
        $balance = $this->finance->balance($booking)['balance_cents'] ?? 0;
        $minutes = (int) round($now->diffInMinutes($due, true));
        $in = $minutes < 90 ? "in {$minutes} minutes" : 'in about '.(int) round($minutes / 60).' hours';
        $at = $due->format('g:i A').($due->toDateString() !== $now->toDateString() ? ' '.$due->format('D') : '');
        $money = $balance > 0 ? ' Balance '.Money::format($balance).' still to collect.' : '';

        if ($camera) {
            SystemNotice::toPermission('bookings.manage', "{$what} is due back at {$at}, {$in} ({$booking->reference}).{$money}",
                "/admin/bookings/{$booking->id}", 'checkouts', heading: 'Camera due back soon');

            return;
        }
        SystemNotice::toPermission(['bookings.manage', 'housekeeping.manage'], "{$what}: guest checks out at {$at}, {$in} ({$booking->reference}).{$money}",
            "/admin/bookings/{$booking->id}", 'checkouts', heading: 'Checking out soon');
    }
}
