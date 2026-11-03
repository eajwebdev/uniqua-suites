<?php

namespace App\Domain\Pricing;

use App\Domain\Availability\RoomConflict;

/** A pricing rule the owner must approve before it can be calculated. */
class PricingPolicyPending extends RoomConflict
{
}
