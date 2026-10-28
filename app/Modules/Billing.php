<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Approvals;
use App\Core\Audit;
use App\Core\Counter;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Pricing;
use App\Shared\Units;

/** Billing (M3): GST invoice, split payments, discounts, refunds, UPI-QR confirmation, shifts and day close. */
class Billing
{
    /** Recipe-based stock deduction on bill (M5.2). Stock may go negative; billing is never blocked. */
    private static function deductStock(array $o): void
    {
        foreach (Db::rows('order_items', fn ($q) => $q->where('order_id', $o['id'])->where('status', '!=', 'void')) as $it) {
            $rec = Db::first('recipes', fn ($q) => $q->where('item_id', $it['item_id'])->where('variant_id', $it['variant_id'])) ?? Db::first('recipes', fn ($q) => $q->where('item_id', $it['item_id'])->whereNull('variant_id'));
            if (!$rec) continue;
            foreach (Db::rows('recipe_lines', fn ($q) => $q->where('recipe_id', $rec['id'])) as $l) {
                $m = Db::first('raw_materials', fn ($q) => $q->where('id', $l['raw_material_id'])->lockForUpdate());
                if (!$m) continue;
                $qty = Units::convert($l['qty'], $l['unit'], $m['unit']) * $it['qty'];
                $bal = round($m['stock'] - $qty, 3);
                Db::update('raw_materials', $m['id'], ['stock' => $bal]);
                Db::create('stock_ledger', ['raw_material_id' => $m['id'], 'qty' => -$qty, 'reason' => 'sale', 'ref_id' => $o['id'], 'balance_after' => $bal], 'sl');
                if ($bal < $m['min_level'] && $m['stock'] >= $m['min_level']) Audit::notify('manager', 'Low stock', "{$m['name']} is below {$m['min_level']} {$m['unit']}", '/admin/stock');
            }
        }
    }

    /**
     * Creates the GST invoice once for a paid order: sequential number per financial year (row lock), tax lines,
     * stock deduction, loyalty, coupon / gift card redemption and customer stats.
     */
    public static function createInvoice(array $o, ?string $shiftId = null): array
    {
        if ($inv = Db::first('invoices', fn ($q) => $q->where('order_id', $o['id']))) return $inv;
        $tax = Db::settings('tax_settings');
        $fy = Pricing::financialYear(ctx()->now()->setTimezone(new \DateTimeZone(config('cafechalao.timezone'))));
        $built = Orders::buildQuote(['lines' => Orders::lineInputs($o['id']), 'order_type' => $o['type'], 'coupon_code' => $o['coupon_code'], 'customer_id' => $o['customer_id'], 'tip' => $o['tip']], false);
        $q = $built['quote'];
        $seq = Counter::next("invoice:$fy");
        $taxable = $o['subtotal'] - $o['discount'] + $o['service_charge'];
        $cgst = (int) round($o['tax'] / 2);
        $inv = Db::create('invoices', [
            'order_id' => $o['id'], 'number' => $tax['invoice_prefix'] . substr($fy, 2) . '/' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT), 'financial_year' => $fy,
            'bill_of_supply' => $tax['gst_scheme'] === 'composition', 'subtotal' => $o['subtotal'], 'discount' => $o['discount'], 'service_charge' => $o['service_charge'],
            'taxable' => $taxable, 'cgst' => $cgst, 'sgst' => $o['tax'] - $cgst, 'round_off' => $o['round_off'], 'total' => $o['total'],
            'cashier_id' => ctx()->user['id'] ?? null, 'shift_id' => $shiftId, 'reprints' => 0,
        ], 'iv');
        $ratio = $q['taxable'] ? $taxable / $q['taxable'] : 1;
        foreach ($q['tax_lines'] as $tl) {
            Db::create('invoice_taxes', ['invoice_id' => $inv['id'], 'rate' => $tl['rate'], 'taxable' => (int) round($tl['taxable'] * $ratio), 'cgst' => (int) round($tl['cgst'] * $ratio), 'sgst' => (int) round($tl['sgst'] * $ratio)], 'ivt');
        }
        Db::q('payments')->where('order_id', $o['id'])->whereNull('invoice_id')->update(['invoice_id' => $inv['id']]);

        self::deductStock($o);

        if ($o['coupon_code'] && ($c = Db::first('coupons', fn ($x) => $x->where('code', $o['coupon_code'])->lockForUpdate()))) {
            // Atomic counter: only the allowed number of redemptions succeed.
            if ($c['usage_limit_total'] !== null && $c['used_count'] >= $c['usage_limit_total']) throw new HttpError(409, 'This coupon just ran out', 'coupon_exhausted');
            Db::update('coupons', $c['id'], ['used_count' => $c['used_count'] + 1]);
            Db::create('coupon_redemptions', ['coupon_id' => $c['id'], 'order_id' => $o['id'], 'customer_id' => $o['customer_id'], 'amount' => $q['coupon_discount']], 'cr');
        }
        if ($o['customer_id']) {
            $cust = Db::find('customers', $o['customer_id']);
            $loy = Db::settings('loyalty_settings');
            $points = $cust['loyalty_points'];
            if ($o['points_redeemed']) {
                $points -= $o['points_redeemed'];
                Db::create('loyalty_ledger', ['customer_id' => $cust['id'], 'order_id' => $o['id'], 'points' => -$o['points_redeemed'], 'reason' => 'redeem'], 'll');
            }
            $earned = $loy['enabled'] ? Pricing::pointsEarned($taxable, $loy['earn_per_rupees']) : 0;
            if ($earned) {
                $points += $earned;
                Db::create('loyalty_ledger', ['customer_id' => $cust['id'], 'order_id' => $o['id'], 'points' => $earned, 'reason' => 'earn', 'expires_at' => ctx()->iso(ctx()->now()->modify("+{$loy['expiry_days']} days"))], 'll');
            }
            $visits = $cust['visits'] + 1;
            $spend = $cust['total_spend'] + $inv['total'];
            $tags = array_values(array_unique(array_merge(array_diff($cust['tags'] ?? [], ['lapsed', 'new']), $visits >= 3 ? ['regular'] : [], $spend > 400000 ? ['vip'] : [])));
            Db::update('customers', $cust['id'], ['loyalty_points' => $points, 'visits' => $visits, 'total_spend' => $spend, 'last_visit_at' => ctx()->iso(), 'tags' => $tags]);
        }
        return $inv;
    }

    /** Staff confirm that a UPI-QR payment reached the café's account; then the order is billed and sent on. */
    public static function confirmPayment(array $o, ?string $reference = null): array
    {
        if ($o['payment_status'] === 'paid') return $o;
        $loy = Db::settings('loyalty_settings');
        $payable = max(0, $o['total'] - $o['points_redeemed'] * $loy['point_value']);
        Db::create('payments', ['order_id' => $o['id'], 'mode' => 'upi', 'amount' => $payable, 'reference' => $reference ?: ($o['payment_reference'] ?? null), 'status' => 'success', 'gateway' => 'upi_qr', 'shift_id' => self::currentShift()['id'] ?? null], 'py');
        $o = Db::update('orders', $o['id'], ['payment_status' => 'paid']);
        self::createInvoice($o);
        if ($o['status'] === 'placed') Orders::acceptAfterPlacement($o);
        return Db::find('orders', $o['id']);
    }

    public static function currentShift(): ?array
    {
        $u = ctx()->user;
        if (!$u) return null;
        return Db::first('shifts', fn ($q) => $q->whereNull('closed_at')->when(!in_array($u['role'], ['owner', 'manager'], true), fn ($x) => $x->where('user_id', $u['id'])));
    }

    public static function invoiceDetail(array $inv): array
    {
        $o = Db::find('orders', $inv['order_id']);
        return $inv + [
            'order' => Orders::detail($o),
            'taxes' => Db::rows('invoice_taxes', fn ($q) => $q->where('invoice_id', $inv['id'])),
            'payments' => Db::rows('payments', fn ($q) => $q->where('order_id', $o['id'])->where('status', 'success')),
            'refunds' => Db::rows('refunds', fn ($q) => $q->where('invoice_id', $inv['id'])),
            'outlet' => Db::settings('outlets'), 'tax_settings' => Db::settings('tax_settings'), 'brand' => Db::settings('brand_settings'),
            'cashier' => $inv['cashier_id'] ? (Db::first('users', fn ($q) => $q->where('id', $inv['cashier_id']))['name'] ?? null) : null,
        ];
    }

    /** Expected cash for a business date (used by day close and the owner dashboard). */
    public static function dayFigures(string $date): array
    {
        $from = Orders::dayStart($date);
        $to = Orders::dayStart($date, 1);
        $inDay = fn ($q) => $q->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $shifts = Db::rows('shifts', fn ($q) => $q->where(fn ($w) => $w->where('opened_at', '>=', $from)->where('opened_at', '<', $to))->orWhereNull('closed_at'));
        $pays = Db::rows('payments', fn ($q) => $inDay($q)->whereIn('status', ['success', 'refunded']));
        $byMode = [];
        foreach ($pays as $p) $byMode[$p['mode']] = ($byMode[$p['mode']] ?? 0) + $p['amount'];
        $opening = max([0, ...array_column(array_filter($shifts, fn ($s) => !$s['handed_to']), 'opening_cash')]);
        $cash = sum_by(where_eq($pays, 'mode', 'cash'), 'amount');
        $petty = sum_by(Db::rows('expenses', fn ($q) => $q->where('spent_on', $date)->where('mode', 'cash')->where('status', 'approved')), 'amount');
        $settled = Db::rows('rider_settlements', fn ($q) => $q->where('business_date', $date)->where('settled', 1));
        $riderIn = sum_by($settled, 'cash_collected');
        $riderOut = sum_by($settled, 'charges_earned');
        $invoices = Db::rows('invoices', $inDay);
        $pending = [];
        foreach (Db::rows('riders') as $rd) {
            $n = Db::q('deliveries')->where('rider_id', $rd['id'])->where('delivered_at', '>=', $from)->where('delivered_at', '<', $to)->count();
            if ($n && !where_eq($settled, 'rider_id', $rd['id'])) $pending[] = $rd['name'];
        }
        return [
            'date' => $date, 'opening_cash' => $opening, 'cash_sales' => $cash, 'petty_cash_out' => $petty, 'rider_cash_in' => $riderIn, 'rider_payouts' => $riderOut,
            'expected_cash' => $opening + $cash + $riderIn - $petty - $riderOut, 'by_mode' => $byMode ?: new \stdClass(), 'bills' => count($invoices), 'sales_total' => sum_by($invoices, 'total'),
            'pending_rider_settlements' => $pending,
            'open_orders' => Db::q('orders')->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('payment_status', '!=', 'paid')->where('status', '!=', 'cancelled')->count(),
        ];
    }

    public static function routes(): void
    {
        /** Settle at the POS: discount (limit-checked), coupon, points, gift card, service charge removal, split payments. */
        Api::post('/orders/:id/settle', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            if ($o['payment_status'] === 'paid' && Db::q('invoices')->where('order_id', $o['id'])->exists()) throw HttpError::invalid(['order' => 'This bill is already paid']);
            $shift = self::currentShift() ?? throw new HttpError(409, 'Open your shift with the opening cash first', 'no_shift');
            $b = $r->body;
            need($b, ['payments']);
            if (!empty($b['discount'])) {
                $d = $b['discount'];
                $pct = $d['kind'] === 'pct' ? (float) $d['value'] : ($o['subtotal'] ? $d['value'] / $o['subtotal'] * 100 : 0);
                if (empty($d['reason'])) throw HttpError::invalid(['reason' => 'Add a reason for the discount']);
                $approver = Approvals::check('discount_pct', $pct, $b['approver_id'] ?? null);
                $amt = $d['kind'] === 'pct' ? (int) round($o['subtotal'] * $pct / 100) : (int) round($d['value']);
                Db::q('discounts')->where('order_id', $o['id'])->update(['deleted_at' => ctx()->iso()]);
                Db::create('discounts', ['order_id' => $o['id'], 'kind' => $d['kind'], 'value' => $d['value'], 'amount' => $amt, 'reason' => $d['reason'], 'approved_by' => $approver['id'] ?? null], 'dc');
                Audit::log('discount', 'order', $o['id'], null, $d + ['amount' => $amt, 'approved_by' => $approver['name'] ?? null]);
            }
            if (!empty($b['remove_service_charge']) && $o['service_charge']) Audit::log('service_charge_removed', 'order', $o['id'], ['service_charge' => $o['service_charge']], ['service_charge' => 0]);
            $disc = Db::first('discounts', fn ($q) => $q->where('order_id', $o['id']));
            $built = Orders::buildQuote([
                'lines' => Orders::lineInputs($o['id']), 'order_type' => $o['type'], 'coupon_code' => $b['coupon_code'] ?? $o['coupon_code'], 'customer_id' => $b['customer_id'] ?? $o['customer_id'],
                'redeem_points' => $b['redeem_points'] ?? 0, 'gift_card_code' => $b['gift_card_code'] ?? null, 'tip' => $b['tip'] ?? $o['tip'], 'remove_service_charge' => !empty($b['remove_service_charge']),
                'manual_discount' => $disc ? ['kind' => $disc['kind'], 'value' => $disc['value']] : null,
            ], false);
            foreach (['coupon_code' => 'coupon_error', 'redeem_points' => 'points_error', 'gift_card_code' => 'gift_card_error'] as $k => $e) if (!empty($b[$k]) && $built[$e]) throw HttpError::invalid([$k => $built[$e]]);
            $q = $built['quote'];
            $payments = array_values(array_filter($b['payments'], fn ($p) => ($p['amount'] ?? 0) > 0));
            $nonCash = $q['points_value'] + $q['gift_card_amount'];
            $paid = sum_by($payments, 'amount');
            if ($paid + $nonCash < $q['total']) throw HttpError::invalid(['payments' => rupees($q['total'] - $nonCash - $paid) . ' still to collect']);

            $order = Db::update('orders', $o['id'], array_intersect_key($q, array_flip(['subtotal', 'discount', 'service_charge', 'tax', 'delivery_charge', 'tip', 'round_off', 'total'])) + [
                'coupon_code' => $built['coupon']['code'] ?? null, 'points_redeemed' => $built['points_used'], 'gift_card_code' => $built['gift_card']['code'] ?? null,
                'customer_id' => $b['customer_id'] ?? $o['customer_id'], 'cashier_id' => ctx()->user['id'], 'payment_status' => 'paid',
                'payment_method' => $payments[0]['mode'] ?? ($q['gift_card_amount'] ? 'gift_card' : 'points'),
            ]);
            $due = $q['total'] - $nonCash;
            foreach ($payments as $p) {
                $amt = min($p['amount'], $due);
                if ($amt <= 0) continue;
                $due -= $amt; // cash change is returned, so only what was due is recorded
                Db::create('payments', ['order_id' => $o['id'], 'mode' => $p['mode'], 'amount' => $amt, 'reference' => $p['reference'] ?? null, 'status' => 'success', 'shift_id' => $shift['id']], 'py');
            }
            if ($q['points_value']) Db::create('payments', ['order_id' => $o['id'], 'mode' => 'points', 'amount' => $q['points_value'], 'reference' => $built['points_used'] . ' pts', 'status' => 'success', 'shift_id' => $shift['id']], 'py');
            if ($q['gift_card_amount'] && $built['gift_card']) {
                $g = Db::first('gift_cards', fn ($x) => $x->where('code', $built['gift_card']['code'])->lockForUpdate());
                Db::update('gift_cards', $g['id'], ['balance' => $g['balance'] - $q['gift_card_amount']]);
                Db::create('gift_card_txns', ['gift_card_id' => $g['id'], 'order_id' => $o['id'], 'amount' => -$q['gift_card_amount']], 'gt');
                Db::create('payments', ['order_id' => $o['id'], 'mode' => 'gift_card', 'amount' => $q['gift_card_amount'], 'reference' => $g['code'], 'status' => 'success', 'shift_id' => $shift['id']], 'py');
            }
            $inv = self::createInvoice($order, $shift['id']);
            if (in_array($order['type'], ['dine_in', 'takeaway', 'counter'], true)) {
                Db::update('orders', $o['id'], ['status' => 'completed', 'completed_at' => ctx()->iso()]);
                Orders::log($o['id'], 'completed', 'Billed');
                Db::q('kots')->where('order_id', $o['id'])->where('status', '!=', 'served')->update(['status' => 'served']);
                Orders::freeTableIfDone(Db::find('orders', $o['id']));
            }
            return ['invoice' => $inv, 'order' => Orders::detail(Db::find('orders', $o['id'])), 'change' => max(0, $paid + $nonCash - $q['total'])];
        }, 'staff', 'pos.bill');

        Api::post('/orders/:id/preview', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $lines = Orders::lineInputs($o['id']);
            if (!$lines) return null;
            return Orders::buildQuote(['lines' => $lines, 'order_type' => $o['type'], 'coupon_code' => $r->b('coupon_code', $o['coupon_code']), 'customer_id' => $r->b('customer_id', $o['customer_id']),
                'redeem_points' => $r->b('redeem_points', 0), 'gift_card_code' => $r->b('gift_card_code'), 'tip' => $r->b('tip', 0), 'remove_service_charge' => (bool) $r->b('remove_service_charge'), 'manual_discount' => $r->b('discount')], false);
        }, 'staff', 'pos.bill');

        /** UPI QR: cashier checks the café's UPI app and confirms the money arrived. */
        Api::post('/orders/:id/confirm-payment', fn (Req $r) => Orders::detail(self::confirmPayment(Db::find('orders', $r->p('id'), 'Order'), $r->b('reference'))), 'staff', 'pos.bill');

        Api::get('/invoices', fn (Req $r) => Db::rows('invoices', function ($q) use ($r) {
            if ($d = $r->q('date')) $q->where('created_at', '>=', Orders::dayStart($d))->where('created_at', '<', Orders::dayStart($d, 1));
            if ($s = $r->q('q')) $q->where('number', 'like', "%$s%");
            $q->limit(200);
        }, 'created_at', 'desc'), 'staff', 'pos.bill');

        /** Invoice view: staff, the customer who owns it, or the e-bill link key. */
        Api::get('/invoices/:id', function (Req $r) {
            $inv = Db::find('invoices', $r->p('id'), 'Invoice');
            $o = Db::find('orders', $inv['order_id']);
            $c = ctx();
            if (!$c->user && !($c->customer && $o['customer_id'] === $c->customer['id']) && $r->q('key') !== $o['id']) throw HttpError::forbidden('Not your bill');
            return self::invoiceDetail($inv);
        }, 'public');

        Api::post('/invoices/:id/reprint', function (Req $r) {
            $inv = Db::find('invoices', $r->p('id'), 'Invoice');
            Audit::log('reprint', 'invoice', $inv['id'], ['reprints' => $inv['reprints']], ['reprints' => $inv['reprints'] + 1]);
            return Db::update('invoices', $inv['id'], ['reprints' => $inv['reprints'] + 1]);
        }, 'staff', 'pos.reprint');

        Api::post('/invoices/:id/share', function (Req $r) {
            $inv = Db::find('invoices', $r->p('id'), 'Invoice');
            $o = Db::find('orders', $inv['order_id']);
            $phone = $r->b('phone') ?: $o['customer_phone'];
            $outlet = Db::settings('outlets');
            $body = "Thank you for visiting {$outlet['name']}! Your bill {$inv['number']} for " . rupees($inv['total']) . ': ' . $r->b('origin', '') . "/bill/{$inv['id']}?key={$o['id']}";
            $url = wa_link($phone, $body) ?? throw HttpError::invalid(['phone' => "Add the customer's mobile number"]);
            Db::create('message_logs', ['customer_id' => $o['customer_id'], 'channel' => 'whatsapp_link', 'template' => 'bill', 'to' => $phone, 'body' => $body, 'status' => 'prepared'], 'ml');
            return ['whatsapp_url' => $url, 'sms_body' => $body];
        }, 'staff', 'pos.bill');

        Api::post('/invoices/:id/refund', function (Req $r) {
            $inv = Db::find('invoices', $r->p('id'), 'Invoice');
            need($r->body, ['amount', 'reason']);
            $already = sum_by(Db::rows('refunds', fn ($q) => $q->where('invoice_id', $inv['id'])), 'amount');
            $amount = (int) round($r->b('amount'));
            if ($amount <= 0 || $amount + $already > $inv['total']) throw HttpError::invalid(['amount' => 'You can refund up to ' . rupees($inv['total'] - $already)]);
            $approver = Approvals::check('refund_value', $amount, $r->b('approver_id'));
            $ref = Db::create('refunds', ['invoice_id' => $inv['id'], 'amount' => $amount, 'reason' => $r->b('reason'), 'approved_by' => $approver['id'] ?? null], 'rf');
            Db::create('payments', ['invoice_id' => $inv['id'], 'order_id' => $inv['order_id'], 'mode' => $r->b('mode', 'cash'), 'amount' => -$amount, 'status' => 'refunded', 'shift_id' => self::currentShift()['id'] ?? null], 'py');
            Db::update('orders', $inv['order_id'], ['payment_status' => $amount + $already >= $inv['total'] ? 'refunded' : 'partially_refunded']);
            Audit::log('refund', 'invoice', $inv['id'], null, ['amount' => $amount, 'reason' => $r->b('reason'), 'approved_by' => $approver['name'] ?? null]);
            return $ref;
        }, 'staff', 'pos.bill');

        /* Shifts and day close (M3.8) */
        Api::get('/shifts/current', function () {
            $s = self::currentShift();
            return $s ? $s + ['user_name' => Db::first('users', fn ($q) => $q->where('id', $s['user_id']))['name'] ?? null] : null;
        });
        Api::post('/shifts/open', function (Req $r) {
            if (self::currentShift()) throw HttpError::invalid(['shift' => 'A shift is already open']);
            need($r->body, ['opening_cash']);
            return Db::create('shifts', ['user_id' => ctx()->user['id'], 'opened_at' => ctx()->iso(), 'opening_cash' => (int) $r->b('opening_cash')], 'sh');
        }, 'staff', 'pos.bill');
        Api::post('/shifts/:id/handover', function (Req $r) {
            $s = Db::find('shifts', $r->p('id'), 'Shift');
            need($r->body, ['to_user_id', 'cash']);
            Db::update('shifts', $s['id'], ['closed_at' => ctx()->iso(), 'handed_to' => $r->b('to_user_id')]);
            return Db::create('shifts', ['user_id' => $r->b('to_user_id'), 'opened_at' => ctx()->iso(), 'opening_cash' => (int) $r->b('cash')], 'sh');
        }, 'staff', 'pos.bill');

        Api::get('/day-close/preview', function (Req $r) {
            $date = $r->q('date', ctx()->today());
            return self::dayFigures($date) + ['closed' => Db::first('day_closes', fn ($q) => $q->where('business_date', $date))];
        }, 'staff', 'pos.day_close');

        /** One lock per café day: a second device closing the same day is refused. */
        Api::post('/day-close', function (Req $r) {
            $date = $r->b('date', ctx()->today());
            Db::raw('tenants')->where('id', ctx()->tenantId())->lockForUpdate()->first();
            if (Db::q('day_closes')->where('business_date', $date)->exists()) throw new HttpError(409, 'This day is already closed', 'day_closed');
            need($r->body, ['counts']);
            $counted = sum_by($r->b('counts'), fn ($c) => $c['denomination'] * 100 * $c['count']);
            $f = self::dayFigures($date);
            $variance = $counted - $f['expected_cash'];
            if ($variance !== 0 && !$r->b('variance_reason')) throw HttpError::invalid(['variance_reason' => "Cash doesn't match. Add a reason for the difference."]);
            $dc = Db::create('day_closes', ['business_date' => $date, 'expected_cash' => $f['expected_cash'], 'counted_cash' => $counted, 'variance' => $variance, 'variance_reason' => (string) $r->b('variance_reason', ''), 'closed_by' => ctx()->user['id'], 'sales_total' => $f['sales_total']], 'dc');
            foreach ($r->b('counts') as $c) Db::create('cash_counts', ['day_close_id' => $dc['id'], 'denomination' => $c['denomination'], 'count' => $c['count']], 'cc');
            Db::q('shifts')->whereNull('closed_at')->update(['closed_at' => ctx()->iso()]);
            if ($variance !== 0) {
                Audit::log('cash_variance', 'day_close', $dc['id'], ['expected' => $f['expected_cash']], ['counted' => $counted, 'variance' => $variance, 'reason' => $r->b('variance_reason')]);
                Audit::notify('owner', 'Cash variance at day close', ($variance > 0 ? 'Excess ' : 'Short ') . rupees(abs($variance)) . ' · ' . $r->b('variance_reason'), '/admin/reports');
            }
            return $dc;
        }, 'staff', 'pos.day_close');

        Api::get('/day-closes', fn () => Db::rows('day_closes', null, 'business_date', 'desc'), 'staff', 'reports.view');
    }
}
