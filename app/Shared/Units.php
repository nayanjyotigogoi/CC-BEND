<?php

namespace App\Shared;

use App\Core\HttpError;

/** Stock unit conversion (M5.1): kg↔g, L↔ml, pcs only to pcs. */
class Units
{
    private const BASE = ['kg' => ['mass', 1000], 'g' => ['mass', 1], 'L' => ['volume', 1000], 'ml' => ['volume', 1], 'pcs' => ['count', 1]];

    public static function convert(float $qty, string $from, string $to): float
    {
        if ($from === $to) return $qty;
        [$da, $fa] = self::BASE[$from] ?? throw HttpError::invalid(['unit' => "Unknown unit $from"]);
        [$db, $fb] = self::BASE[$to] ?? throw HttpError::invalid(['unit' => "Unknown unit $to"]);
        if ($da !== $db) throw HttpError::invalid(['unit' => "Cannot convert $from to $to"]);
        return $qty * $fa / $fb;
    }
}
