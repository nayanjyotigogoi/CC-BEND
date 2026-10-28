<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Kpi;

/**
 * Finance and reports (M10, SRS 5). Every report takes range (today, 7d, 30d, month) or from/to, and type.
 * Numbers come from the same records the POS writes; formulas live in App\Shared\Kpi.
 */
class Reports
{
    private static function range(Req $r): array
    {
        $t = ctx()->today();
        $shift = fn (int $n) => date('Y-m-d', strtotime("$t -$n days"));
        $from = $r->q('from') ?? match ($r->q('range')) { '7d' => $shift(6), '30d' => $shift(29), 'month' => substr($t, 0, 8) . '01', default => $t };
        return ['from' => $from, 'to' => $r->q('to', $t), 'type' => $r->q('type'), 'staff' => $r->q('staff')];
    }

    private static function within(string $col, array $f): \Closure
    {
        return fn ($q) => $q->where($col, '>=', Orders::dayStart($f['from']))->where($col, '<', Orders::dayStart($f['to'], 1));
    }

    private static function sales(array $f): array
    {
        $orders = Db::rows('orders', function ($q) use ($f) {
            self::within('created_at', $f)($q);
            $q->whereIn('payment_status', ['paid', 'partially_refunded', 'refunded']);
            if ($f['type']) $q->where('type', $f['type']);
            if ($f['staff']) $q->where(fn ($w) => $w->where('captain_id', $f['staff'])->orWhere('cashier_id', $f['staff']));
        });
        $ids = array_column($orders, 'id');
        $invoices = Db::rows('invoices', fn ($q) => $q->whereIn('order_id', $ids ?: ['-']));
        $refunds = sum_by(Db::rows('refunds', fn ($q) => $q->whereIn('invoice_id', array_column($invoices, 'id') ?: ['-'])), 'amount');
        $discounts = sum_by($orders, 'discount');
        $gross = sum_by($orders, fn ($o) => $o['subtotal'] + $o['service_charge']) + $discounts;
        return ['orders' => $orders, 'ids' => $ids, 'invoices' => $invoices, 'gross' => $gross, 'discounts' => $discounts, 'refunds' => $refunds,
            'net' => Kpi::netSales($gross, $discounts, $refunds), 'tax' => sum_by($orders, 'tax'), 'total' => sum_by($orders, 'total')];
    }

    private static function items(array $ids): array
    {
        return Db::rows('order_items', fn ($q) => $q->whereIn('order_id', $ids ?: ['-'])->where('status', '!=', 'void'));
    }

    /** Cost of goods sold from recipes (theoretical food cost). */
    private static function cogs(array $ids): int
    {
        $cache = [];
        $total = 0;
        foreach (self::items($ids) as $it) {
            $k = $it['item_id'] . ':' . $it['variant_id'];
            $cache[$k] ??= Menu::recipeCost($it['item_id'], $it['variant_id']);
            $total += $cache[$k] * $it['qty'];
        }
        return (int) round($total);
    }

    /** Labour for the period: each month's salary bill pro-rated to the days selected. */
    private static function labour(array $f): int
    {
        $days = max(1, (strtotime($f['to']) - strtotime($f['from'])) / 86400 + 1);
        $monthly = 0;
        foreach (Db::rows('staff_profiles') as $p) $monthly += $p['salary_type'] === 'monthly' ? $p['salary_amount'] : $p['salary_amount'] * 26;
        return (int) round($monthly / 30 * $days);
    }

    private static function expenses(array $f, bool $withSalary = false): array
    {
        $sal = Db::first('expense_categories', fn ($q) => $q->where('name', 'Salary'))['id'] ?? '-';
        return Db::rows('expenses', fn ($q) => $q->where('status', 'approved')->where('spent_on', '>=', $f['from'])->where('spent_on', '<=', $f['to'])->when(!$withSalary, fn ($x) => $x->where('category_id', '!=', $sal)));
    }

    private static function hourOf(string $iso): int
    {
        return (int) (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(config('cafechalao.timezone')))->format('G');
    }

    public static function build(string $name, array $f): mixed
    {
        switch ($name) {
            case 'dashboard': {
                $s = self::sales($f);
                $days = (strtotime($f['to']) - strtotime($f['from'])) / 86400 + 1;
                $prev = ['from' => date('Y-m-d', strtotime($f['from'] . " -$days days")), 'to' => date('Y-m-d', strtotime($f['from'] . ' -1 day'))] + $f;
                $p = self::sales($prev);
                $pays = Db::rows('payments', fn ($q) => $q->whereIn('order_id', $s['ids'] ?: ['-'])->where('status', 'success'));
                $cash = sum_by(array_filter($pays, fn ($x) => in_array($x['mode'], ['cash', 'cod'], true)), 'amount');
                $exp = sum_by(self::expenses($f), 'amount');
                $c = self::cogs($s['ids']);
                $lab = self::labour($f);
                $hours = array_map(fn ($h) => ['hour' => $h, 'sales' => 0, 'orders' => 0], range(0, 23));
                foreach ($s['orders'] as $o) { $h = self::hourOf($o['created_at']); $hours[$h]['sales'] += $o['total']; $hours[$h]['orders']++; }
                $top = [];
                foreach (self::items($s['ids']) as $it) { $top[$it['item_id']]['name'] = $it['name']; $top[$it['item_id']]['qty'] = ($top[$it['item_id']]['qty'] ?? 0) + $it['qty']; $top[$it['item_id']]['sales'] = ($top[$it['item_id']]['sales'] ?? 0) + $it['unit_price'] * $it['qty']; }
                usort($top, fn ($a, $b) => $b['qty'] <=> $a['qty']);
                $flags = Db::settings('business_type_flags');
                $late = Db::q('orders')->whereIn('status', ['accepted', 'preparing', 'placed'])->where('created_at', '<', ctx()->iso(ctx()->now()->modify("-{$flags['kds_amber_min']} minutes")))->count();
                $tables = Db::rows('dining_tables');
                $alerts = [];
                foreach (array_slice(array_filter(Db::rows('raw_materials'), fn ($m) => $m['stock'] < $m['min_level']), 0, 4) as $m) $alerts[] = ['kind' => 'low_stock', 'text' => $m['stock'] <= 0 ? "{$m['name']} is out of stock" : "{$m['name']} below {$m['min_level']} {$m['unit']}", 'link' => '/admin/stock'];
                if ($late) $alerts[] = ['kind' => 'late', 'text' => "$late order" . ($late > 1 ? 's' : '') . " waiting > {$flags['kds_amber_min']} min", 'link' => '/admin/orders'];
                if ($n = Db::q('approvals')->where('status', 'pending')->count()) $alerts[] = ['kind' => 'approvals', 'text' => "$n approval" . ($n > 1 ? 's' : '') . ' pending', 'link' => '/admin/approvals'];
                foreach (array_slice(Db::rows('feedback', fn ($q) => $q->where('replied', 0)->where('created_at', '>=', Orders::dayStart($f['from']))->where(fn ($w) => $w->where('food', '<=', 2)->orWhere('service', '<=', 2)->orWhere('ambience', '<=', 2))), 0, 3) as $x) {
                    $alerts[] = ['kind' => 'rating', 'text' => 'Low rating ' . min($x['food'], $x['service'], $x['ambience']) . '★: "' . ($x['comment'] ?: 'no comment') . '"', 'link' => '/admin/marketing/feedback'];
                }
                $last = Db::first('day_closes', fn ($q) => $q->orderByDesc('business_date'));
                if ($last && $last['variance'] !== 0) $alerts[] = ['kind' => 'variance', 'text' => 'Cash variance ' . rupees($last['variance']) . " on {$last['business_date']}", 'link' => '/admin/reports'];
                $mb = Marketing::budget();
                if ($mb['amount'] && $mb['remaining'] < $mb['amount'] * 0.1) $alerts[] = ['kind' => 'budget', 'text' => 'Marketing budget almost used: ' . rupees(max(0, $mb['remaining'])) . ' left', 'link' => '/admin/marketing'];
                $today = Billing::dayFigures(ctx()->today());
                return [
                    'range' => $f, 'sales' => $s['total'], 'net_sales' => $s['net'], 'orders' => count($s['orders']), 'aov' => Kpi::aov($s['net'], count($s['orders'])),
                    'sales_change_pct' => $p['total'] ? ($s['total'] - $p['total']) / $p['total'] * 100 : null, 'online' => sum_by($pays, 'amount') - $cash, 'cash' => $cash, 'expenses' => $exp,
                    'food_cost_pct' => $s['net'] ? $c / $s['net'] * 100 : 0, 'labour_cost_pct' => Kpi::labourPct($lab, $s['net']),
                    'profit_estimate' => Kpi::netProfit(Kpi::grossProfit($s['net'], $c), $lab, $exp),
                    'cash_in_hand' => $today['opening_cash'] + $today['cash_sales'] - $today['petty_cash_out'],
                    'live_tables' => ['occupied' => count(array_filter($tables, fn ($t) => in_array($t['status'], ['occupied', 'bill_requested'], true))), 'total' => count($tables)],
                    'by_hour' => array_values(array_filter($hours, fn ($h) => $h['orders'] > 0 || ($h['hour'] >= 8 && $h['hour'] <= 23))),
                    'by_type' => array_values(array_filter(array_map(fn ($t) => ['type' => $t, 'orders' => count(where_eq($s['orders'], 'type', $t)), 'sales' => sum_by(where_eq($s['orders'], 'type', $t), 'total')], ['dine_in', 'takeaway', 'delivery', 'counter']), fn ($x) => $x['orders'])),
                    'top_items' => array_slice($top, 0, 5), 'alerts' => $alerts, 'marketing_budget' => $mb,
                ];
            }
            case 'sales': {
                $s = self::sales($f);
                $byDay = [];
                foreach ($s['orders'] as $o) { $d = day_of($o['created_at']); $byDay[$d] ??= ['date' => $d, 'orders' => 0, 'sales' => 0, 'net' => 0]; $byDay[$d]['orders']++; $byDay[$d]['sales'] += $o['total']; $byDay[$d]['net'] += $o['subtotal'] + $o['service_charge']; }
                ksort($byDay);
                $modes = [];
                foreach (Db::rows('payments', fn ($q) => $q->whereIn('order_id', $s['ids'] ?: ['-'])->where('status', '!=', 'failed')) as $p) $modes[$p['mode']] = ($modes[$p['mode']] ?? 0) + $p['amount'];
                $ch = [];
                foreach ($s['orders'] as $o) $ch[$o['channel']] = ($ch[$o['channel']] ?? 0) + $o['total'];
                return ['summary' => ['gross' => $s['gross'], 'discounts' => $s['discounts'], 'refunds' => $s['refunds'], 'net' => $s['net'], 'tax' => $s['tax'], 'total' => $s['total'], 'bills' => count($s['orders']), 'aov' => Kpi::aov($s['net'], count($s['orders']))],
                    'by_day' => array_values($byDay), 'by_mode' => array_map(fn ($m, $a) => ['mode' => $m, 'amount' => $a], array_keys($modes), $modes),
                    'by_channel' => array_map(fn ($c, $a) => ['channel' => $c, 'amount' => $a], array_keys($ch), $ch),
                    'by_type' => array_map(fn ($t) => ['type' => $t, 'orders' => count(where_eq($s['orders'], 'type', $t)), 'sales' => sum_by(where_eq($s['orders'], 'type', $t), 'total')], ['dine_in', 'takeaway', 'delivery', 'counter'])];
            }
            case 'tax': {
                $s = self::sales($f);
                $by = [];
                foreach (Db::rows('invoice_taxes', fn ($q) => $q->whereIn('invoice_id', array_column($s['invoices'], 'id') ?: ['-'])) as $t) {
                    $k = (string) $t['rate'];
                    $by[$k] ??= ['rate' => $t['rate'], 'taxable' => 0, 'cgst' => 0, 'sgst' => 0];
                    foreach (['taxable', 'cgst', 'sgst'] as $x) $by[$k][$x] += $t[$x];
                }
                $nums = array_column($s['invoices'], 'number');
                sort($nums);
                $ts = Db::settings('tax_settings');
                return ['scheme' => $ts['gst_scheme'], 'gstin' => $ts['gstin'], 'hsn_sac' => $ts['hsn_sac'], 'invoices' => count($nums), 'first_invoice' => $nums[0] ?? null, 'last_invoice' => end($nums) ?: null,
                    'by_rate' => array_values($by), 'turnover' => sum_by($s['invoices'], 'total'), 'composition_threshold' => 1500000000];
            }
            case 'pnl': {
                $s = self::sales($f);
                $c = self::cogs($s['ids']);
                $lab = self::labour($f);
                $exps = self::expenses($f);
                $cats = array_column(Db::rows('expense_categories'), 'name', 'id');
                $byCat = [];
                foreach ($exps as $e) $byCat[$cats[$e['category_id']] ?? 'Other'] = ($byCat[$cats[$e['category_id']] ?? 'Other'] ?? 0) + $e['amount'];
                $waste = sum_by(Db::rows('wastage', self::within('created_at', $f)), 'value');
                $gp = Kpi::grossProfit($s['net'], $c + $waste);
                $et = array_sum($byCat);
                return ['direct_sales' => $s['net'], 'aggregator_net' => 0, 'net_sales' => $s['net'], 'cogs' => $c, 'wastage' => $waste, 'gross_profit' => $gp, 'labour' => $lab,
                    'expenses' => array_map(fn ($k, $v) => ['category' => $k, 'amount' => $v], array_keys($byCat), $byCat), 'expenses_total' => $et,
                    'net_profit' => Kpi::netProfit($gp, $lab, $et), 'food_cost_pct' => $s['net'] ? ($c + $waste) / $s['net'] * 100 : 0, 'labour_cost_pct' => Kpi::labourPct($lab, $s['net']),
                    'note' => 'COGS is recipe-based (theoretical). Actual food cost uses opening + purchases − closing stock from stock counts.'];
            }
            case 'cash_flow': {
                $in = sum_by(Db::rows('payments', fn ($q) => self::within('created_at', $f)($q)->whereIn('status', ['success', 'refunded'])->whereNotIn('mode', ['points', 'gift_card'])), 'amount');
                $sup = sum_by(Db::rows('supplier_payments', fn ($q) => $q->where('paid_on', '>=', $f['from'])->where('paid_on', '<=', $f['to'])), 'amount');
                $sal = Db::first('expense_categories', fn ($q) => $q->where('name', 'Salary'))['id'] ?? '-';
                $all = self::expenses($f, true);
                $salaries = sum_by(where_eq($all, 'category_id', $sal), 'amount');
                $other = sum_by($all, 'amount') - $salaries;
                $riders = sum_by(Db::rows('rider_settlements', fn ($q) => $q->where('business_date', '>=', $f['from'])->where('business_date', '<=', $f['to'])), 'charges_earned');
                $out = $sup + $other + $salaries + $riders;
                return ['opening' => 0, 'cash_in' => ['sales' => $in, 'aggregators' => 0, 'total' => $in], 'cash_out' => ['purchases' => $sup, 'expenses' => $other, 'salaries' => $salaries, 'rider_payouts' => $riders, 'total' => $out], 'closing' => Kpi::closingCash(0, $in, $out)];
            }
            case 'menu': {
                $s = self::sales($f);
                $sold = array_filter(self::items($s['ids']), fn ($i) => !$i['complimentary']);
                $rows = array_map(function ($it) use ($sold) {
                    $lines = array_filter($sold, fn ($l) => $l['item_id'] === $it['id']);
                    $qty = sum_by($lines, 'qty');
                    $rev = sum_by($lines, fn ($l) => $l['unit_price'] * $l['qty']);
                    $cost = (int) round(Menu::recipeCost($it['id']));
                    $price = $qty ? (int) round($rev / $qty) : $it['price'];
                    return ['item_id' => $it['id'], 'name' => $it['name'], 'qty' => $qty, 'revenue' => $rev, 'price' => $price, 'recipe_cost' => $cost, 'margin' => Kpi::itemMargin($price, $cost), 'food_cost_pct' => $price ? $cost / $price * 100 : 0];
                }, Db::rows('items'));
                $out = Kpi::menuEngineering($rows);
                usort($out, fn ($a, $b) => $b['qty'] <=> $a['qty']);
                return $out;
            }
            case 'stock_variance': {
                $ledger = Db::rows('stock_ledger', self::within('created_at', $f));
                return array_values(array_filter(array_map(function ($m) use ($ledger) {
                    $mine = where_eq($ledger, 'raw_material_id', $m['id']);
                    $theo = -sum_by(where_eq($mine, 'reason', 'sale'), 'qty');
                    $adj = sum_by(where_eq($mine, 'reason', 'count_adjust'), 'qty');
                    $waste = -sum_by(where_eq($mine, 'reason', 'wastage'), 'qty');
                    $actual = $theo + $waste - $adj;
                    $var = $theo - $actual;
                    return ['raw_material_id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'theoretical' => round($theo, 3), 'wastage' => $waste, 'actual' => round($actual, 3), 'variance' => round($var, 3), 'value' => (int) round($var * $m['avg_cost'])];
                }, Db::rows('raw_materials')), fn ($r) => $r['theoretical'] || $r['actual']));
            }
            case 'food_cost': {
                $s = self::sales($f);
                $purchases = sum_by(Db::rows('grns', fn ($q) => $q->where('received_on', '>=', $f['from'])->where('received_on', '<=', $f['to'])), 'total');
                $closing = (int) round(sum_by(Db::rows('raw_materials'), fn ($m) => max(0, $m['stock']) * $m['avg_cost']));
                $used = self::cogs($s['ids']);
                $opening = $closing + $used - $purchases;
                return ['opening' => $opening, 'purchases' => $purchases, 'closing' => $closing, 'net_sales' => $s['net'], 'food_cost_pct' => Kpi::foodCostPct($opening, $purchases, $closing, $s['net']), 'theoretical' => $used, 'theoretical_pct' => $s['net'] ? $used / $s['net'] * 100 : 0];
            }
            case 'kitchen': {
                $kots = Db::rows('kots', fn ($q) => self::within('created_at', $f)($q)->whereNotNull('ready_at'));
                $pair = fn ($k) => [strtotime($k['created_at']), strtotime($k['ready_at'])];
                $red = Db::settings('business_type_flags')['kds_red_min'];
                $by = [];
                foreach ($kots as $k) $by[$k['station']][] = $pair($k);
                return ['average_sec' => Kpi::avgPrepSec(array_map($pair, $kots)), 'kots' => count($kots), 'late' => count(array_filter($kots, fn ($k) => (strtotime($k['ready_at']) - strtotime($k['created_at'])) / 60 > $red)),
                    'by_station' => array_map(fn ($st, $p) => ['station' => $st, 'kots' => count($p), 'average_sec' => Kpi::avgPrepSec($p)], array_keys($by), $by)];
            }
            case 'delivery': {
                $dels = Db::rows('deliveries', fn ($q) => self::within('delivered_at', $f)($q)->where('status', 'delivered'));
                $times = array_map(fn ($d) => (strtotime($d['delivered_at']) - strtotime($d['accepted_at'])) / 60, array_filter($dels, fn ($d) => $d['accepted_at']));
                return ['deliveries' => count($dels), 'average_min' => $times ? array_sum($times) / count($times) : 0, 'charges' => sum_by($dels, 'charge'),
                    'by_rider' => array_map(fn ($r) => ['rider' => $r['name'], 'type' => $r['type'], 'deliveries' => count(where_eq($dels, 'rider_id', $r['id'])), 'charges' => sum_by(where_eq($dels, 'rider_id', $r['id']), 'charge'),
                        'cash' => sum_by(array_filter(where_eq($dels, 'rider_id', $r['id']), fn ($d) => $d['collected_mode'] === 'cash'), 'collected')], Db::rows('riders'))];
            }
            case 'customers': {
                $s = self::sales($f);
                $by = [];
                foreach ($s['orders'] as $o) if ($o['customer_id']) $by[$o['customer_id']] = ($by[$o['customer_id']] ?? 0) + 1;
                $repeat = count(array_filter($by, fn ($n) => $n >= 2));
                $custs = array_column(Db::rows('customers'), null, 'id');
                $top = array_map(fn ($id, $n) => ['id' => $id, 'name' => $custs[$id]['name'] ?? '', 'orders' => $n, 'spend' => sum_by(where_eq($s['orders'], 'customer_id', $id), 'total')], array_keys($by), $by);
                usort($top, fn ($a, $b) => $b['spend'] <=> $a['spend']);
                $tags = [];
                foreach ($custs as $c) foreach ($c['tags'] ?? [] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
                return ['customers' => count($by), 'repeat_customers' => $repeat, 'repeat_rate' => Kpi::repeatRate($repeat, count($by)), 'new_signups' => Db::q('customers')->where('created_at', '>=', Orders::dayStart($f['from']))->where('created_at', '<', Orders::dayStart($f['to'], 1))->count(),
                    'tags' => $tags ?: new \stdClass(), 'top' => array_slice($top, 0, 10)];
            }
            case 'feedback': {
                $fb = Db::rows('feedback', self::within('created_at', $f));
                return ['count' => count($fb), 'food' => Kpi::avgRating(array_column($fb, 'food')), 'service' => Kpi::avgRating(array_column($fb, 'service')), 'ambience' => Kpi::avgRating(array_column($fb, 'ambience')),
                    'overall' => Kpi::avgRating(array_map(fn ($x) => ($x['food'] + $x['service'] + $x['ambience']) / 3, $fb)), 'low' => count(array_filter($fb, fn ($x) => min($x['food'], $x['service'], $x['ambience']) <= 2)),
                    'distribution' => array_map(fn ($n) => ['stars' => $n, 'count' => count(array_filter($fb, fn ($x) => (int) round(($x['food'] + $x['service'] + $x['ambience']) / 3) === $n))], [1, 2, 3, 4, 5])];
            }
            case 'dine_in': {
                $s = self::sales(['type' => 'dine_in'] + $f);
                $tables = Db::rows('dining_tables');
                $o = Db::settings('outlets');
                $hours = max(1, (int) substr($o['closing_time'], 0, 2) - (int) substr($o['opening_time'], 0, 2)) * max(1, (strtotime($f['to']) - strtotime($f['from'])) / 86400 + 1);
                $bt = array_map(fn ($t) => ['table' => $t['name'], 'bills' => count(where_eq($s['orders'], 'table_id', $t['id'])), 'sales' => sum_by(where_eq($s['orders'], 'table_id', $t['id']), 'total')], $tables);
                usort($bt, fn ($a, $b) => $b['sales'] <=> $a['sales']);
                return ['bills' => count($s['orders']), 'tables' => count($tables), 'turnover_per_hour' => Kpi::tableTurnover(count($s['orders']), count($tables), $hours), 'by_table' => $bt,
                    'by_captain' => array_map(fn ($u) => ['captain' => $u['name'], 'bills' => count(where_eq($s['orders'], 'captain_id', $u['id'])), 'sales' => sum_by(where_eq($s['orders'], 'captain_id', $u['id']), 'total')], Db::rows('users', fn ($q) => $q->where('role', 'captain')))];
            }
            case 'budget':
                return array_map(fn ($b) => ['category' => $b['category'], 'budget' => $b['budget'], 'actual' => $b['actual']] + Kpi::budgetVariance($b['actual'], $b['budget']), Expenses::budgetRows(substr($f['from'], 0, 7)));
            case 'day_close': {
                $users = array_column(Db::rows('users'), 'name', 'id');
                return array_map(fn ($d) => $d + ['closed_by_name' => $users[$d['closed_by']] ?? null], Db::rows('day_closes', fn ($q) => $q->where('business_date', '>=', $f['from'])->where('business_date', '<=', $f['to']), 'business_date', 'desc'));
            }
            case 'items': {
                $s = self::sales($f);
                $m = [];
                foreach (self::items($s['ids']) as $it) { $m[$it['name']]['name'] = $it['name']; $m[$it['name']]['qty'] = ($m[$it['name']]['qty'] ?? 0) + $it['qty']; $m[$it['name']]['sales'] = ($m[$it['name']]['sales'] ?? 0) + $it['unit_price'] * $it['qty']; }
                usort($m, fn ($a, $b) => $b['sales'] <=> $a['sales']);
                return array_values($m);
            }
        }
        throw HttpError::invalid(['name' => 'Unknown report']);
    }

    public static function routes(): void
    {
        Api::get('/reports/:name', fn (Req $r) => self::build(str_replace('-', '_', $r->p('name')), self::range($r)), 'staff', 'reports.view');
    }
}
