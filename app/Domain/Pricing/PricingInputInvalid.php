<?php

namespace App\Domain\Pricing;

use App\Domain\Availability\RoomConflict;

/** A mistake in quote options must not make a physically free room look occupied. */
class PricingInputInvalid extends RoomConflict
{
}
