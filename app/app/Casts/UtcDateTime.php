<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stores timestamps as naive UTC strings and returns them in the app timezone.
 * Duplicati reports times in UTC; the database column has no timezone.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');

        return $date->utc()->format('Y-m-d H:i:s');
    }
}
