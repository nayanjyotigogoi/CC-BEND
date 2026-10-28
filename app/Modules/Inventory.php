<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Approvals;
use App\Core\Audit;
use App\Core\Counter;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Units;

/** Stock and purchase (M5): materials, PR → approval → PO → GRN, quotations, supplier payments, wastage, counts, approval queue. */
class Inventory
{
    private static function ledger(array $m, float $qty, string $reason, ?string $ref): void
    {
        $bal = round($m['stock'] + $qty, 3);
        Db::update('raw_materials', $m['id'], ['stock' => $bal]);
        Db::create('stock_ledger', ['raw_material_id' => $m['id'], 'qty' => $qty, 'reason' => $reason, 'ref_id' => $ref, 'balance_after' => $bal], 'sl');
    }

    private static function makePO(string $supplierId, array $lines, ?string $prId, string $status): array
    {
        $total = (int) sum_by($lines, fn ($l) => round($l['qty'] * $l['rate']));
        $po = Db::create('purchase_orders', ['number' => 'PO-' . str_pad((string) Counter::next('po', 42), 4, '0', STR_PAD_LEFT), 'supplier_id' => $supplierId, 'purchase_request_id' => $prId, 'status' => $status,
            'expected_on' => ctx()->now()->modify('+2 days')->format('Y-m-d'), 'total' => $total], 'po');
        foreach ($lines as $l) Db::create('po_lines', ['purchase_order_id' => $po['id'], 'raw_material_id' => $l['raw_material_id'], 'qty' => (float) $l['qty'], 'rate' => (int) round($l['rate']), 'received_qty' => 0], 'pol');
        if ($status === 'pending') Approvals::queue('purchase_order', $po['id'], $total, "Purchase order {$po['number']}", Approvals::roleFor('po_value', $total));
        return $po;
    }

    public static function poDetail(array $po): array
    {
        $mats = array_column(Db::rows('raw_materials'), null, 'id');
        return $po + [
            'supplier' => Db::first('suppliers', fn ($q) => $q->where('id', $po['supplier_id'])),
            'lines' => array_map(fn ($l) => $l + ['name' => $mats[$l['raw_material_id']]['name'] ?? null, 'unit' => $mats[$l['raw_material_id']]['unit'] ?? null], Db::rows('po_lines', fn ($q) => $q->where('purchase_order_id', $po['id']))),
            'grns' => Db::rows('grns', fn ($q) => $q->where('purchase_order_id', $po['id'])),
        ];
    }

    public static function routes(): void
    {
        Api::crud('/raw-materials', 'raw_materials', 'rm', ['perm' => 'stock.receive', 'readPerm' => 'stock.view', 'required' => ['name', 'unit'], 'audit' => 'raw_material', 'sort' => 'name',
            'defaults' => ['category' => 'General', 'storage' => 'Dry store', 'min_level' => 0, 'stock' => 0, 'avg_cost' => 0, 'last_cost' => 0]]);
        Api::crud('/suppliers', 'suppliers', 'su', ['perm' => 'stock.approve', 'readPerm' => 'stock.view', 'required' => ['name', 'phone'], 'defaults' => ['contact_person' => '', 'payment_terms_days' => 15, 'items_supplied' => '', 'outstanding' => 0]]);

        Api::get('/stock/summary', function () {
            $mats = Db::rows('raw_materials');
            $sup = array_column(Db::rows('suppliers'), 'name', 'id');
            $low = array_filter($mats, fn ($m) => $m['stock'] > 0 && $m['stock'] < $m['min_level']);
            $out = array_filter($mats, fn ($m) => $m['stock'] <= 0);
            $by = [];
            foreach ([...$low, ...$out] as $m) $by[$sup[$m['supplier_id']] ?? 'No supplier'][] = $m['name'];
            return ['stock_value' => (int) round(sum_by($mats, fn ($m) => max(0, $m['stock']) * $m['avg_cost'])), 'low' => count($low), 'out' => count($out), 'total' => count($mats),
                'open_pos' => Db::q('purchase_orders')->whereIn('status', ['approved', 'sent', 'partially_received', 'pending'])->count(),
                'suggestion' => array_map(fn ($s, $i) => ['supplier' => $s, 'items' => $i], array_keys($by), $by)];
        }, 'staff', 'stock.view');

        Api::get('/stock/ledger', fn (Req $r) => Db::rows('stock_ledger', fn ($q) => $q->when($r->q('raw_material_id'), fn ($x, $v) => $x->where('raw_material_id', $v))->limit(300), 'created_at', 'desc'), 'staff', 'stock.view');

        /* Wastage (kitchen and back office) */
        Api::get('/wastage/materials', fn () => array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit']], Db::rows('raw_materials', null, 'name')), 'staff', 'kds.wastage');
        Api::get('/wastage', fn () => Db::rows('wastage', null, 'created_at', 'desc'), 'staff', 'kds.wastage');
        Api::post('/wastage', function (Req $r) {
            need($r->body, ['qty', 'unit', 'reason']);
            if (!$r->b('raw_material_id') && !$r->b('item_id')) throw HttpError::invalid(['raw_material_id' => 'Pick an ingredient or a menu item']);
            if ($r->b('raw_material_id')) {
                $m = Db::find('raw_materials', $r->b('raw_material_id'), 'Ingredient');
                $q = Units::convert((float) $r->b('qty'), $r->b('unit'), $m['unit']);
                $value = (int) round($q * $m['avg_cost']);
                self::ledger($m, -$q, 'wastage', null);
            } else {
                $value = Db::find('items', $r->b('item_id'), 'Item')['price'] * (float) $r->b('qty');
            }
            return Db::create('wastage', ['raw_material_id' => $r->b('raw_material_id'), 'item_id' => $r->b('item_id'), 'qty' => (float) $r->b('qty'), 'unit' => $r->b('unit'), 'reason' => $r->b('reason'), 'note' => (string) $r->b('note', ''), 'value' => (int) $value], 'ws');
        }, 'staff', 'kds.wastage');

        /* Stock count with variance (M5.9) */
        Api::get('/stock-counts', fn () => Db::rows('stock_counts', null, 'created_at', 'desc'), 'staff', 'stock.view');
        Api::post('/stock-counts', function (Req $r) {
            need($r->body, ['lines']);
            $lines = array_map(function ($l) {
                $m = Db::find('raw_materials', $l['raw_material_id'], 'Ingredient');
                return ['raw_material_id' => $m['id'], 'system_qty' => $m['stock'], 'counted_qty' => (float) $l['counted_qty']];
            }, $r->b('lines'));
            $sc = Db::create('stock_counts', ['counted_on' => ctx()->today(), 'lines' => $lines, 'status' => $r->b('post') ? 'posted' : 'draft'], 'sc');
            if ($r->b('post')) {
                foreach ($lines as $l) if ($diff = $l['counted_qty'] - $l['system_qty']) self::ledger(Db::find('raw_materials', $l['raw_material_id']), $diff, 'count_adjust', $sc['id']);
                Audit::log('stock_count', 'stock_count', $sc['id'], null, ['lines' => count($lines)]);
            }
            return $sc;
        }, 'staff', 'stock.receive');

        Api::post('/stock/transfer', function (Req $r) {
            need($r->body, ['raw_material_id', 'qty', 'from', 'to']);
            $m = Db::find('raw_materials', $r->b('raw_material_id'), 'Ingredient');
            Db::create('stock_ledger', ['raw_material_id' => $m['id'], 'qty' => 0, 'reason' => 'transfer', 'ref_id' => "{$r->b('from')}→{$r->b('to')}:{$r->b('qty')}", 'balance_after' => $m['stock']], 'sl');
            return ['ok' => true];
        }, 'staff', 'stock.receive');

        /* Purchase requests (M5.4) */
        Api::get('/purchase-requests', fn () => Db::rows('purchase_requests', null, 'created_at', 'desc'), 'staff', 'stock.view');
        Api::post('/purchase-requests', function (Req $r) {
            need($r->body, ['lines']);
            $lines = array_values(array_filter($r->b('lines'), fn ($l) => ($l['qty'] ?? 0) > 0));
            if (!$lines) throw HttpError::invalid(['lines' => 'Add at least one ingredient']);
            $est = (int) sum_by($lines, fn ($l) => round($l['qty'] * Db::find('raw_materials', $l['raw_material_id'])['last_cost']));
            $pr = Db::create('purchase_requests', ['number' => 'PR-' . str_pad((string) Counter::next('pr', 12), 4, '0', STR_PAD_LEFT), 'lines' => array_map(fn ($l) => $l + ['note' => $l['note'] ?? ''], $lines), 'status' => 'pending', 'estimated_value' => $est], 'pq');
            Approvals::queue('purchase_request', $pr['id'], $est, "Purchase request {$pr['number']} · " . count($lines) . ' items', Approvals::roleFor('po_value', $est));
            return $pr;
        }, 'staff', 'stock.request');

        /* Quotations and price comparison (M5.5) */
        Api::post('/quotations', function (Req $r) {
            need($r->body, ['supplier_id', 'lines']);
            $q = Db::create('quotations', ['purchase_request_id' => $r->b('purchase_request_id'), 'supplier_id' => $r->b('supplier_id'), 'received_on' => ctx()->today()], 'qt');
            foreach ($r->b('lines') as $l) Db::create('quotation_lines', ['quotation_id' => $q['id'], 'raw_material_id' => $l['raw_material_id'], 'rate' => (int) round($l['rate'])], 'ql');
            return $q;
        }, 'staff', 'stock.request');

        Api::get('/purchase-requests/:id/comparison', function (Req $r) {
            $pr = Db::find('purchase_requests', $r->p('id'), 'Request');
            $quotes = Db::rows('quotations', fn ($q) => $q->where('purchase_request_id', $pr['id']));
            $ql = Db::rows('quotation_lines', fn ($q) => $q->whereIn('quotation_id', array_column($quotes, 'id') ?: ['-']));
            $sup = array_column(Db::rows('suppliers'), 'name', 'id');
            return [
                'request' => $pr,
                'suppliers' => array_map(fn ($q) => ['quotation_id' => $q['id'], 'supplier_id' => $q['supplier_id'], 'name' => $sup[$q['supplier_id']] ?? null], $quotes),
                'rows' => array_map(function ($l) use ($quotes, $ql) {
                    $m = Db::find('raw_materials', $l['raw_material_id']);
                    $rates = array_map(fn ($q) => ['supplier_id' => $q['supplier_id'], 'rate' => first_where($ql, fn ($x) => $x['quotation_id'] === $q['id'] && $x['raw_material_id'] === $m['id'])['rate'] ?? null], $quotes);
                    $valid = array_filter($rates, fn ($x) => $x['rate'] !== null);
                    usort($valid, fn ($a, $b) => $a['rate'] <=> $b['rate']);
                    $lo = $valid[0] ?? null;
                    return ['raw_material_id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'qty' => $l['qty'], 'last_price' => $m['last_cost'], 'rates' => $rates,
                        'lowest_supplier_id' => $lo['supplier_id'] ?? null, 'lowest_price' => $lo['rate'] ?? null, 'change_pct' => $lo && $m['last_cost'] ? ($lo['rate'] - $m['last_cost']) / $m['last_cost'] * 100 : null];
                }, $pr['lines']),
            ];
        }, 'staff', 'stock.view');

        Api::post('/purchase-requests/:id/convert', function (Req $r) {
            $pr = Db::find('purchase_requests', $r->p('id'), 'Request');
            if ($pr['status'] !== 'approved') throw HttpError::invalid(['status' => 'Approve the request first']);
            $choice = (array) $r->b('choice', []);
            $groups = [];
            foreach ($pr['lines'] as $l) {
                $m = Db::find('raw_materials', $l['raw_material_id']);
                $c = $choice[$m['id']] ?? ['supplier_id' => $m['supplier_id'], 'rate' => $m['last_cost']];
                if (!$c['supplier_id']) throw HttpError::invalid(['supplier_id' => "Pick a supplier for {$m['name']}"]);
                $groups[$c['supplier_id']][] = ['raw_material_id' => $m['id'], 'qty' => $l['qty'], 'rate' => $c['rate']];
            }
            $pos = array_map(fn ($s, $ls) => self::makePO($s, $ls, $pr['id'], 'approved'), array_keys($groups), $groups);
            Db::update('purchase_requests', $pr['id'], ['status' => 'ordered']);
            return $pos;
        }, 'staff', 'stock.approve');

        /* Purchase orders */
        Api::get('/purchase-orders', fn () => array_map([self::class, 'poDetail'], Db::rows('purchase_orders', null, 'created_at', 'desc')), 'staff', 'stock.view');
        Api::get('/purchase-orders/:id', fn (Req $r) => self::poDetail(Db::find('purchase_orders', $r->p('id'), 'PO')), 'staff', 'stock.view');
        /** Within the user's PO limit it is approved at once; above it waits in the approval queue. */
        Api::post('/purchase-orders', function (Req $r) {
            need($r->body, ['supplier_id', 'lines']);
            $lines = array_values(array_filter($r->b('lines'), fn ($l) => ($l['qty'] ?? 0) > 0));
            if (!$lines) throw HttpError::invalid(['lines' => 'Add at least one line']);
            $total = (int) sum_by($lines, fn ($l) => round($l['qty'] * $l['rate']));
            $ok = ctx()->can('stock.approve') && $total <= Approvals::limits(ctx()->user['role'])['po_value'];
            return self::poDetail(self::makePO($r->b('supplier_id'), $lines, null, $ok ? 'approved' : 'pending'));
        }, 'staff', 'stock.request');
        Api::post('/stock/auto-po', function () {
            $groups = [];
            foreach (Db::rows('raw_materials', fn ($q) => $q->whereNotNull('supplier_id')) as $m) {
                if ($m['stock'] < $m['min_level']) $groups[$m['supplier_id']][] = ['raw_material_id' => $m['id'], 'qty' => ceil($m['min_level'] * 2 - $m['stock']), 'rate' => $m['last_cost']];
            }
            return array_map(fn ($s, $ls) => self::poDetail(self::makePO($s, $ls, null, 'pending')), array_keys($groups), $groups);
        }, 'staff', 'stock.request');
        Api::post('/purchase-orders/:id/send', function (Req $r) {
            $po = Db::find('purchase_orders', $r->p('id'), 'PO');
            if (!in_array($po['status'], ['approved', 'sent'], true)) throw HttpError::invalid(['status' => 'Only approved POs can be sent']);
            $d = self::poDetail($po);
            $body = Db::settings('outlets')['name'] . " purchase order {$po['number']}:\n" . implode("\n", array_map(fn ($l) => "• {$l['name']} {$l['qty']} {$l['unit']} @ " . rupees($l['rate']), $d['lines'])) . "\nTotal " . rupees($po['total']) . ". Deliver by {$po['expected_on']}.";
            Db::update('purchase_orders', $po['id'], ['status' => 'sent']);
            Db::create('message_logs', ['channel' => 'whatsapp_link', 'template' => 'po', 'to' => $d['supplier']['phone'] ?? '', 'body' => $body, 'status' => 'prepared'], 'ml');
            return ['whatsapp_url' => wa_link($d['supplier']['phone'] ?? '', $body)];
        }, 'staff', 'stock.request');

        /** Goods receipt (M5.6): stock, weighted average cost and supplier ledger. */
        Api::post('/grns', function (Req $r) {
            need($r->body, ['purchase_order_id', 'lines']);
            $po = Db::find('purchase_orders', $r->b('purchase_order_id'), 'PO');
            if (!in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) throw HttpError::invalid(['purchase_order_id' => "This PO isn't open for receiving"]);
            $grn = Db::create('grns', ['number' => 'GRN-' . str_pad((string) Counter::next('grn', 31), 4, '0', STR_PAD_LEFT), 'purchase_order_id' => $po['id'], 'supplier_id' => $po['supplier_id'], 'received_on' => ctx()->today(), 'total' => 0, 'invoice_ref' => (string) $r->b('invoice_ref', '')], 'gr');
            $total = 0;
            foreach ($r->b('lines') as $l) {
                $pl = Db::find('po_lines', $l['po_line_id'], 'PO line');
                $qty = (float) $l['received_qty'];
                if ($qty <= 0) continue;
                $rate = (int) round($l['rate'] ?? $pl['rate']);
                $m = Db::find('raw_materials', $pl['raw_material_id']);
                $old = max(0, $m['stock']);
                $avg = $old + $qty > 0 ? (int) round(($old * $m['avg_cost'] + $qty * $rate) / ($old + $qty)) : $rate;
                Db::update('raw_materials', $m['id'], ['avg_cost' => $avg, 'last_cost' => $rate]);
                self::ledger(Db::find('raw_materials', $m['id']), $qty, 'grn', $grn['id']);
                Db::update('po_lines', $pl['id'], ['received_qty' => $pl['received_qty'] + $qty]);
                Db::create('grn_lines', ['grn_id' => $grn['id'], 'po_line_id' => $pl['id'], 'raw_material_id' => $m['id'], 'received_qty' => $qty, 'rate' => $rate, 'batch' => $l['batch'] ?? null ?: null, 'expiry' => $l['expiry'] ?? null ?: null, 'shortfall' => max(0, $pl['qty'] - $pl['received_qty'] - $qty)], 'grl');
                $total += (int) round($qty * $rate);
            }
            Db::update('grns', $grn['id'], ['total' => $total]);
            $open = array_filter(Db::rows('po_lines', fn ($q) => $q->where('purchase_order_id', $po['id'])), fn ($x) => $x['received_qty'] < $x['qty']);
            Db::update('purchase_orders', $po['id'], ['status' => $open ? 'partially_received' : 'received']);
            $s = Db::find('suppliers', $po['supplier_id']);
            Db::update('suppliers', $s['id'], ['outstanding' => $s['outstanding'] + $total]);
            return Db::find('grns', $grn['id']);
        }, 'staff', 'stock.receive');
        Api::get('/grns', function () {
            $sup = array_column(Db::rows('suppliers'), 'name', 'id');
            return array_map(fn ($g) => $g + ['supplier' => $sup[$g['supplier_id']] ?? null, 'lines' => Db::rows('grn_lines', fn ($q) => $q->where('grn_id', $g['id']))], Db::rows('grns', null, 'created_at', 'desc'));
        }, 'staff', 'stock.view');

        /* Supplier payments and ageing (M5.7) */
        Api::get('/supplier-payments', fn () => Db::rows('supplier_payments', null, 'paid_on', 'desc'), 'staff', 'stock.approve');
        Api::post('/supplier-payments', function (Req $r) {
            need($r->body, ['supplier_id', 'amount', 'mode']);
            $s = Db::find('suppliers', $r->b('supplier_id'), 'Supplier');
            $amt = (int) round($r->b('amount'));
            if ($amt <= 0) throw HttpError::invalid(['amount' => 'Enter an amount']);
            Db::update('suppliers', $s['id'], ['outstanding' => $s['outstanding'] - $amt]);
            Audit::log('supplier_payment', 'supplier', $s['id'], ['outstanding' => $s['outstanding']], ['paid' => $amt]);
            return Db::create('supplier_payments', ['supplier_id' => $s['id'], 'amount' => $amt, 'mode' => $r->b('mode'), 'reference' => (string) $r->b('reference', ''), 'paid_on' => $r->b('paid_on', ctx()->today())], 'spy');
        }, 'staff', 'stock.approve');
        Api::get('/payables/ageing', function () {
            return array_map(function ($s) {
                $paid = sum_by(Db::rows('supplier_payments', fn ($q) => $q->where('supplier_id', $s['id'])), 'amount');
                $b = ['0-15' => 0, '16-30' => 0, '31-60' => 0, '60+' => 0];
                foreach (Db::rows('grns', fn ($q) => $q->where('supplier_id', $s['id']), 'received_on') as $g) {
                    $due = max(0, $g['total'] - $paid);
                    $paid = max(0, $paid - $g['total']);
                    if (!$due) continue;
                    $days = (ctx()->now()->getTimestamp() - strtotime($g['received_on'])) / 86400;
                    $b[$days <= 15 ? '0-15' : ($days <= 30 ? '16-30' : ($days <= 60 ? '31-60' : '60+'))] += $due;
                }
                return ['supplier_id' => $s['id'], 'name' => $s['name'], 'outstanding' => $s['outstanding'], 'terms' => $s['payment_terms_days'], 'buckets' => $b];
            }, Db::rows('suppliers'));
        }, 'staff', 'stock.approve');

        /* Approval queue (shared by PR, PO, expense, attendance) */
        Api::get('/approvals', function (Req $r) {
            $users = array_column(Db::rows('users'), 'name', 'id');
            $me = ctx()->user;
            return array_map(fn ($a) => $a + ['requested_by_name' => $users[$a['requested_by']] ?? null, 'decided_by_name' => $users[$a['decided_by']] ?? null, 'can_decide' => Approvals::rank($me['role']) >= Approvals::rank($a['required_role'])],
                Db::rows('approvals', fn ($q) => $q->when($r->q('status'), fn ($x, $v) => $x->where('status', $v)), 'created_at', 'desc'));
        }, 'staff', 'approvals.decide');

        Api::post('/approvals/:id/decide', function (Req $r) {
            $a = Db::find('approvals', $r->p('id'), 'Approval');
            if ($a['status'] !== 'pending') throw HttpError::invalid(['status' => 'Already decided']);
            if (Approvals::rank(ctx()->user['role']) < Approvals::rank($a['required_role'])) throw HttpError::forbidden("Needs {$a['required_role']} approval");
            $ok = $r->b('decision') === 'approve';
            $status = $ok ? 'approved' : 'rejected';
            Db::update('approvals', $a['id'], ['status' => $status, 'decided_by' => ctx()->user['id'], 'decided_at' => ctx()->iso()]);
            $table = ['purchase_request' => 'purchase_requests', 'purchase_order' => 'purchase_orders', 'expense' => 'expenses'][$a['kind']] ?? null;
            if ($table) Db::update($table, $a['ref_id'], ['status' => $status]);
            if ($a['kind'] === 'attendance' && $ok) {
                $att = Db::find('attendance', $a['ref_id']);
                Db::update('attendance', $att['id'], (json_decode((string) $att['correction_note'], true)['patch'] ?? []) + ['correction_note' => null]);
            }
            Audit::log($ok ? 'approve' : 'reject', $a['kind'], $a['ref_id'], null, ['amount' => $a['amount'], 'note' => $r->b('note', '')]);
            return Db::find('approvals', $a['id']);
        }, 'staff', 'approvals.decide');
    }
}
