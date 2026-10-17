<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SpecificDates
{
    public const LIMIT = 366;

    public static function normalise(array $dates, array $existing = [], ?string $after = null): array
    {
        Validator::make(['specific_dates' => $dates], ['specific_dates' => 'required|array|min:1|max:'.self::LIMIT, 'specific_dates.*' => 'required|date_format:Y-m-d|distinct'])->validate();
        $today = now()->timezone(config('tasksure.timezone'))->toDateString();
        foreach ($dates as $date) {
            if ((! in_array($date, $existing, true) && $date < $today) || ($after !== null && $date <= $after)) {
                throw ValidationException::withMessages(['specific_dates' => 'New dates must be today or later, and repeats must follow the original task date.']);
            }
        }
        sort($dates, SORT_STRING);

        return array_values($dates);
    }
}
