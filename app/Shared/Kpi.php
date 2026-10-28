<?php

namespace App\Shared;

/** KPI formulas exactly as SRS section 5 (same as web/shared/lib/kpi.ts). Money in paise, % as 0–100. */
class Kpi
{
    private static function div(float $a, float $b): float { return $b == 0 ? 0 : $a / $b; }

    public static function netSales(int $gross, int $discounts, int $refunds): int { return $gross - $discounts - $refunds; }
    public static function aov(int $net, int $bills): int { return (int) round(self::div($net, $bills)); }
    public static function foodCostPct(int $opening, int $purchases, int $closing, int $net): float { return self::div($opening + $purchases - $closing, $net) * 100; }
    public static function itemMargin(int $price, int $cost): int { return $price - $cost; }
    public static function labourPct(int $labour, int $net): float { return self::div($labour, $net) * 100; }
    public static function grossProfit(int $net, int $cogs): int { return $net - $cogs; }
    public static function netProfit(int $gp, int $labour, int $expenses): int { return $gp - $labour - $expenses; }
    public static function closingCash(int $opening, int $in, int $out): int { return $opening + $in - $out; }
    public static function budgetVariance(int $actual, int $budget): array { return ['amount' => $actual - $budget, 'pct' => self::div($actual - $budget, $budget) * 100]; }
    public static function tableTurnover(int $bills, int $tables, float $hours): float { return self::div(self::div($bills, $tables), $hours); }
    public static function avgPrepSec(array $pairs): float { return self::div(array_sum(array_map(fn ($p) => ($p[1] - $p[0]), $pairs)), count($pairs)); }
    public static function repeatRate(int $repeat, int $total): float { return self::div($repeat, $total) * 100; }
    public static function avgRating(array $stars): float { return self::div(array_sum($stars), count($stars)); }

    /** Menu engineering: popularity = qty ÷ average qty; Star / Plowhorse / Puzzle / Dog by popularity and margin. */
    public static function menuEngineering(array $rows): array
    {
        $avgQty = self::div(array_sum(array_column($rows, 'qty')), count($rows));
        $avgMargin = self::div(array_sum(array_column($rows, 'margin')), count($rows));
        return array_map(function ($r) use ($avgQty, $avgMargin) {
            $pop = self::div($r['qty'], $avgQty);
            $hiP = $pop >= 1;
            $hiM = $r['margin'] >= $avgMargin;
            return $r + ['popularity' => $pop, 'cls' => $hiP && $hiM ? 'star' : ($hiP ? 'plowhorse' : ($hiM ? 'puzzle' : 'dog'))];
        }, $rows);
    }
}
