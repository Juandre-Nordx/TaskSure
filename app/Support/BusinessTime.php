<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class BusinessTime
{
    public static function utc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('tasksure.timezone'))->utc();
    }

    public static function show($value, string $format = 'd M Y, H:i'): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone(config('tasksure.timezone'))->format($format) : '—';
    }
}
