<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\RoomConflict;
use App\Support\Local;
use Carbon\CarbonImmutable;

/**
 * A camera rental's exact span: from the pickup moment to the agreed return moment.
 *
 * - Billed days are whole 24-hour blocks, at least 1. Hours past the last full block are never
 *   charged automatically: they are shown so the owner can add a charge by hand if they want.
 * - check_in / check_out are the calendar days the rental touches, kept so calendars, reports and
 *   month locks work as they do for suites. check_out is always after check_in.
 */
final class RentalPeriod
{
    public const MINUTES_PER_DAY = 1440;

    private function __construct(public readonly CarbonImmutable $starts, public readonly CarbonImmutable $ends)
    {
    }

    /** From the form: pickup date and time, return date and time. A missing time means midnight (the old whole-day rule). */
    public static function fromParts(string $pickupDate, ?string $pickupTime, string $returnDate, ?string $returnTime): self
    {
        $starts = CarbonImmutable::parse($pickupDate.' '.self::time($pickupTime), Local::tz());
        $ends = CarbonImmutable::parse($returnDate.' '.self::time($returnTime), Local::tz());
        if ($ends->lte($starts)) {
            throw new RoomConflict('The return time must be after the pickup time.');
        }

        return new self($starts, $ends);
    }

    public static function between(string|\DateTimeInterface $starts, string|\DateTimeInterface $ends): self
    {
        $s = CarbonImmutable::parse($starts, Local::tz());
        $e = CarbonImmutable::parse($ends, Local::tz());
        if ($e->lte($s)) {
            throw new RoomConflict('The return time must be after the pickup time.');
        }

        return new self($s, $e);
    }

    public function minutes(): int
    {
        return (int) $this->starts->diffInMinutes($this->ends);
    }

    /** Whole 24-hour blocks, never less than one. 24 h = 1, 28 h = 1, 48 h = 2. */
    public function days(): int
    {
        return max(1, intdiv($this->minutes(), self::MINUTES_PER_DAY));
    }

    /** Booked time past the billed days. Not charged automatically. */
    public function extraMinutes(): int
    {
        return max(0, $this->minutes() - $this->days() * self::MINUTES_PER_DAY);
    }

    public function checkIn(): string
    {
        return $this->starts->toDateString();
    }

    /** The return day, or the day after pickup for a same-day rental, so the day span is never empty. */
    public function checkOut(): string
    {
        $returnDay = $this->ends->toDateString();
        $next = $this->starts->addDay()->toDateString();

        return max($returnDay, $next);
    }

    public function startsAt(): string
    {
        return $this->starts->format('Y-m-d H:i:s');
    }

    public function endsAt(): string
    {
        return $this->ends->format('Y-m-d H:i:s');
    }

    public function pickupTime(): string
    {
        return $this->starts->format('H:i');
    }

    public function returnTime(): string
    {
        return $this->ends->format('H:i');
    }

    private static function time(?string $time): string
    {
        $time = trim((string) $time);

        return $time === '' ? '00:00:00' : (strlen($time) === 5 ? $time.':00' : $time);
    }
}
