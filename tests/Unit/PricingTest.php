<?php

namespace Tests\Unit;

use App\Shared\Kpi;
use App\Shared\Pricing;
use PHPUnit\Framework\TestCase;

/** Same cases as web/tests/unit/pricing.test.ts: both sides must price a bill identically. */
class PricingTest extends TestCase
{
    private array $tax = ['gst_scheme' => 'regular', 'service_charge_enabled' => false, 'service_charge_pct' => 5, 'round_off' => true];

    private function line(int $rupees, int $qty = 1, float $rate = 5): array
    {
        return ['item_id' => "i$rupees", 'unit_price' => $rupees * 100, 'qty' => $qty, 'gst_rate' => $rate];
    }

    private function q(array $lines, string $type = 'dine_in', array $extra = []): array
    {
        return Pricing::quote(['lines' => $lines, 'order_type' => $type, 'tax' => $extra['tax'] ?? $this->tax, 'today' => '2026-10-27'] + $extra);
    }

    public function test_wireframe_cart(): void
    {
        $q = $this->q([$this->line(40, 2), $this->line(90)]);
        $this->assertSame(17000, $q['subtotal']);
        $this->assertSame(850, $q['tax']);
        $this->assertSame(17900, $q['total']);
        $this->assertSame(50, $q['round_off']);
    }

    public function test_composition_is_bill_of_supply(): void
    {
        $q = $this->q([$this->line(99, 2)], 'counter', ['tax' => ['gst_scheme' => 'composition'] + $this->tax]);
        $this->assertTrue($q['bill_of_supply']);
        $this->assertSame(0, $q['tax']);
    }

    public function test_service_charge_dine_in_only_and_taxed(): void
    {
        $sc = ['service_charge_enabled' => true, 'round_off' => false] + $this->tax;
        $q = $this->q([$this->line(200)], 'dine_in', ['tax' => $sc]);
        $this->assertSame(1000, $q['service_charge']);
        $this->assertSame(1050, $q['tax']);
        $this->assertSame(0, $this->q([$this->line(200)], 'takeaway', ['tax' => $sc])['service_charge']);
    }

    public function test_discount_spread_across_rates(): void
    {
        $q = $this->q([$this->line(100, 1, 5), $this->line(100, 1, 18)], 'takeaway', ['tax' => ['round_off' => false] + $this->tax, 'manual_discount' => ['kind' => 'flat', 'value' => 2000]]);
        $this->assertSame([9000, 9000], array_column($q['tax_lines'], 'taxable'));
    }

    public function test_financial_year(): void
    {
        $this->assertSame('2026-27', Pricing::financialYear(new \DateTime('2027-03-31')));
        $this->assertSame('2027-28', Pricing::financialYear(new \DateTime('2027-04-01')));
    }

    public function test_menu_engineering(): void
    {
        $r = Kpi::menuEngineering([['qty' => 100, 'margin' => 80], ['qty' => 100, 'margin' => 10], ['qty' => 5, 'margin' => 90], ['qty' => 5, 'margin' => 5]]);
        $this->assertSame(['star', 'plowhorse', 'puzzle', 'dog'], array_column($r, 'cls'));
    }
}
