<?php

namespace App\Shared;

/**
 * PriceCalculator + GstCalculator (SRS 16.3), identical to web/shared/lib/pricing.ts.
 * Rules (to be confirmed by a CA): discounts lower the taxable value and are spread across GST rates by value;
 * service charge (dine-in, off by default) is taxed at the bill's mix of rates; delivery charge, packaging and tip
 * carry no GST; points and gift cards reduce what is payable; composition scheme = Bill of Supply, no GST.
 */
class Pricing
{
    /** Splits an amount across weights so the parts add back exactly. */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum == 0) return array_map(fn () => 0, $weights);
        $raw = array_map(fn ($w) => $total * $w / $sum, $weights);
        $out = array_map(fn ($r) => (int) floor($r), $raw);
        $rest = $total - array_sum($out);
        $order = array_keys($raw);
        usort($order, fn ($a, $b) => ($raw[$b] - floor($raw[$b])) <=> ($raw[$a] - floor($raw[$a])));
        foreach ($order as $i) {
            if ($rest <= 0) break;
            $out[$i]++;
            $rest--;
        }
        return $out;
    }

    public static function couponDiscount(array $coupon, array $lines, string $orderType, string $today): array
    {
        if (!$coupon['active']) return [0, 'This coupon is not active'];
        if ($today < $coupon['valid_from'] || $today > $coupon['valid_to']) return [0, 'This coupon has expired'];
        if ($coupon['usage_limit_total'] !== null && $coupon['used_count'] >= $coupon['usage_limit_total']) return [0, 'This coupon has been fully used'];
        if ($coupon['order_types'] && !in_array($orderType, $coupon['order_types'], true)) return [0, 'Not valid for this order type'];
        $base = 0;
        $all = 0;
        foreach ($lines as $l) {
            if (!empty($l['complimentary'])) continue;
            $all += $l['unit_price'] * $l['qty'];
            if (!$coupon['item_ids'] || in_array($l['item_id'], $coupon['item_ids'], true)) $base += $l['unit_price'] * $l['qty'];
        }
        if ($all < $coupon['min_order']) return [0, 'Add items worth ₹' . (int) ceil(($coupon['min_order'] - $all) / 100) . ' more to use this coupon'];
        $amt = $coupon['kind'] === 'pct' ? (int) round($base * $coupon['value'] / 100) : (int) $coupon['value'];
        if ($coupon['max_discount'] !== null) $amt = min($amt, $coupon['max_discount']);
        return [min($amt, $base), null];
    }

    /**
     * @param array $in lines[], order_type, tax{gst_scheme,service_charge_enabled,service_charge_pct,round_off},
     *                  coupon?, membership_discount_pct?, offer_discount?, manual_discount?{kind,value}, remove_service_charge?,
     *                  delivery_charge?, packaging?, tip?, points_value?, gift_card_amount?, today
     */
    public static function quote(array $in): array
    {
        $lines = $in['lines'];
        $tax = $in['tax'];
        $composition = $tax['gst_scheme'] === 'composition';
        $gross = 0;
        $comp = 0;
        foreach ($lines as $l) {
            $gross += $l['unit_price'] * $l['qty'];
            if (!empty($l['complimentary'])) $comp += $l['unit_price'] * $l['qty'];
        }
        $paid = array_values(array_filter($lines, fn ($l) => empty($l['complimentary'])));
        $subtotal = $gross - $comp;

        $couponAmt = 0;
        $couponErr = null;
        if (!empty($in['coupon'])) [$couponAmt, $couponErr] = self::couponDiscount($in['coupon'], $lines, $in['order_type'], $in['today']);
        $after = $subtotal - $couponAmt;
        $membership = !empty($in['membership_discount_pct']) ? (int) round($after * $in['membership_discount_pct'] / 100) : 0;
        $offer = min((int) ($in['offer_discount'] ?? 0), $after - $membership);
        $manual = 0;
        if (!empty($in['manual_discount'])) {
            $b = $after - $membership - $offer;
            $md = $in['manual_discount'];
            $manual = $md['kind'] === 'pct' ? (int) round($b * $md['value'] / 100) : (int) round($md['value']);
            $manual = max(0, min($manual, $b));
        }
        $discount = $couponAmt + $membership + $offer + $manual;
        $netFood = $subtotal - $discount;
        $sc = ($tax['service_charge_enabled'] && empty($in['remove_service_charge']) && $in['order_type'] === 'dine_in') ? (int) round($netFood * $tax['service_charge_pct'] / 100) : 0;

        $values = array_map(fn ($l) => $l['unit_price'] * $l['qty'], $paid);
        $lineDisc = self::allocate($discount, $values);
        $byRate = [];
        foreach ($paid as $i => $l) {
            $k = (string) (float) $l['gst_rate'];
            $byRate[$k] = ($byRate[$k] ?? 0) + $values[$i] - $lineDisc[$i];
        }
        $rates = array_keys($byRate);
        $scParts = self::allocate($sc, array_values($byRate));
        foreach ($rates as $i => $r) $byRate[$r] += $scParts[$i];

        $taxLines = [];
        if (!$composition) {
            usort($rates, fn ($a, $b) => (float) $a <=> (float) $b);
            foreach ($rates as $r) {
                $half = (int) round($byRate[$r] * (float) $r / 200);
                $taxLines[] = ['rate' => (float) $r, 'taxable' => $byRate[$r], 'cgst' => $half, 'sgst' => $half];
            }
        }
        $taxAmt = array_sum(array_map(fn ($t) => $t['cgst'] + $t['sgst'], $taxLines));
        $taxable = $netFood + $sc;
        $delivery = (int) ($in['delivery_charge'] ?? 0);
        $packaging = (int) ($in['packaging'] ?? 0);
        $tip = (int) ($in['tip'] ?? 0);
        $before = $taxable + $taxAmt + $delivery + $packaging + $tip;
        $total = $tax['round_off'] ? (int) (round($before / 100) * 100) : $before;
        $points = min((int) ($in['points_value'] ?? 0), $total);
        $gift = min((int) ($in['gift_card_amount'] ?? 0), $total - $points);

        return [
            'subtotal' => $subtotal, 'complimentary' => $comp, 'coupon_discount' => $couponAmt, 'manual_discount' => $manual,
            'membership_discount' => $membership, 'offer_discount' => $offer, 'discount' => $discount, 'service_charge' => $sc,
            'taxable' => $taxable, 'tax_lines' => $taxLines, 'tax' => $taxAmt, 'delivery_charge' => $delivery, 'packaging' => $packaging,
            'tip' => $tip, 'round_off' => $total - $before, 'total' => $total, 'points_value' => $points, 'gift_card_amount' => $gift,
            'payable' => $total - $points - $gift, 'bill_of_supply' => $composition, 'coupon_error' => $couponErr,
        ];
    }

    public static function pointsEarned(int $netFood, float $earnPerRupees): int
    {
        return $earnPerRupees > 0 ? (int) floor($netFood / 100 / $earnPerRupees) : 0;
    }

    public static function deliveryCharge(array $rules, int $orderValue): int
    {
        if ($rules['charge_type'] === 'flat') return (int) $rules['flat_charge'];
        $slabs = $rules['slabs'] ?? [];
        usort($slabs, fn ($a, $b) => $a['upto_amount'] <=> $b['upto_amount']);
        foreach ($slabs as $s) if ($orderValue <= $s['upto_amount']) return (int) $s['charge'];
        return 0;
    }

    /** Indian financial year: Apr–Mar → "2026-27". */
    public static function financialYear(\DateTimeInterface $d): string
    {
        $y = (int) $d->format('n') >= 4 ? (int) $d->format('Y') : (int) $d->format('Y') - 1;
        return $y . '-' . str_pad((string) (($y + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
}
