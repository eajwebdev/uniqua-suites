<?php

namespace App\Domain\Availability;

use RuntimeException;

/** Actionable inventory or state conflict. Shown to staff as-is, so it must not carry PII beyond the booking reference. */
class RoomConflict extends RuntimeException
{
}
