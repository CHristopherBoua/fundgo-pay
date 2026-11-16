<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Date calendaire stockée au format Y-m-d sur tous les SGBD.
 * (Le cast `date` natif écrit "Y-m-d H:i:s", ce qui fausse les égalités et
 * comparaisons de chaînes sous SQLite : `period = '2026-12-05'`.)
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value)->format('Y-m-d');
    }
}
