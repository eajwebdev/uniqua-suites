<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Property-local (Asia/Manila) calendar helpers. Timestamps are stored in UTC. */
class Local
{
    public static function tz(): string
    {
        return config('uniqa.display_timezone');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }
}
