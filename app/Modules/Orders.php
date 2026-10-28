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

/** Orders, cart quote, KOT and kitchen display (M1, M2, M3 order side). */
class Orders
{
    public const OPEN = ['placed', 'accepted', 'preparing', 'ready', 'served', 'out_for_delivery'];

    /* ---------- lines and quote ---------- */

    public static function resolveLines(array $lines, bool $checkAvailability = true): array
    {
        $tax = Db::settings('tax_settings');
        $errors = [];
        $out = [];
        foreach ($lines as $i => $l) {
            $item = Db::first('items', fn ($q) => $q->where('id', $l['item_id'] ?? ''));
            if (!$item) { $errors["lines.$i"] = 'This item is no longer on the menu'; continue; }
            $v = !empty($l['variant_id']) ? Db::first('variants', fn ($q) => $q->where('id', $l['variant_id'])->where('item_id', $item['id'])) : null;
            if (!empty($l['variant_id']) && !$v) $errors["lines.$i"] = "Pick a size for {$item['name']}";
            $addOns = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'price' => $a['price']], Db::rows('add_ons', fn ($q) => $q->whereIn('id', $l['add_on_ids'] ?? [])));
            $available = $item['in_stock'] && $item['available_today'];
            if ($checkAvailability && !$available) $errors["lines.$i"] = "{$item['name']} just went out of stock";
            if (!is_int($l['qty'] ?? null) && !ctype_digit((string) ($l['qty'] ?? '')) || (int) $l['qty'] < 1) $errors["lines.$i"] = 'Quantity must be at least 1';
            $cat = Db::first('categories', fn ($q) => $q->where('id', $item['category_id']));
            $out[] = $l + [
                'qty' => (int) $l['qty'], 'name' => $item['name'], 'variant_name' => $v['name'] ?? null, 'add_ons' => $addOns,
                'unit_price' => ($v ? $v['price'] : $item['price']) + array_sum(array_column($addOns, 'price')),
                'gst_rate' => $item['gst_rate'] ?? $tax['default_gst_rate'], 'station' => $cat['station'] ?? 'kitchen', 'available' => $available,
            ];
        }
        if ($errors) throw new HttpError(422, (string) array_values($errors)[0], 'unavailable', $errors);
        return $out;
    }

    /** Happy hour % and buy-X-get-Y (M4.6). */
    private static function offerDiscount(array $lines): array
    {
        if (!Outlet::feature('offers')) return [0, []];
        $local = ctx()->now()->setTimezone(new \DateTimeZone(config('cafechalao.timezone')));
        $day = (int) $local->format('w');
        $hm = $local->format('H:i');
        $amount = 0;
        $names = [];
        foreach (Db::rows('offers', fn ($q) => $q->where('active', 1)) as $o) {
            if (!in_array($day, $o['days'] ?? [], true) || $hm < $o['start_time'] || $hm > $o['end_time']) continue;
            $eligible = array_filter($lines, fn ($l) => empty($l['complimentary']) && (!$o['item_ids'] || in_array($l['item_id'], $o['item_ids'], true)));
            if ($o['kind'] === 'happy_hour' && $eligible) {
                $amount += (int) round(sum_by($eligible, fn ($l) => $l['unit_price'] * $l['qty']) * $o['discount_pct'] / 100);
                $names[] = $o['name'];
            }
            if ($o['kind'] === 'bxgy' && $o['buy_qty'] > 0) {
                foreach ($eligible as $l) {
                    $free = intdiv($l['qty'], $o['buy_qty'] + $o['get_qty']) * $o['get_qty'];
                    if ($free > 0) { $amount += $free * $l['unit_price']; $names[] = $o['name']; }
                }
            }
        }
        return [$amount, array_values(array_unique($names))];
    }

    /** The one place a cart or bill is priced: customer app, POS, captain and invoice all call this. */
    public static function buildQuote(array $in, bool $checkAvailability = true): array
    {
        if (empty($in['lines'])) throw HttpError::invalid(['lines' => 'Your cart is empty']);
        $type = $in['order_type'] ?? 'takeaway';
        $lines = self::resolveLines($in['lines'], $checkAvailability);
        $tax = Db::settings('tax_settings');
        $loyalty = Db::settings('loyalty_settings');
        $ds = Db::settings('delivery_settings');
        $today = ctx()->today();
        $customer = !empty($in['customer_id']) ? Db::first('customers', fn ($q) => $q->where('id', $in['customer_id'])) : ctx()->customer;

        $coupon = !empty($in['coupon_code']) ? Db::first('coupons', fn ($q) => $q->where('code', strtoupper($in['coupon_code']))) : null;
        $couponErr = !empty($in['coupon_code']) && !$coupon ? "This coupon code doesn't exist" : null;
        if ($coupon && $customer && $coupon['usage_limit_per_customer'] !== null) {
            $used = Db::q('coupon_redemptions')->where('coupon_id', $coupon['id'])->where('customer_id', $customer['id'])->count();
            if ($used >= $coupon['usage_limit_per_customer']) $couponErr = "You've already used this coupon";
        }
        $membership = $customer ? Db::first('memberships', fn ($q) => $q->where('customer_id', $customer['id'])->where('status', 'active')->where('ends_on', '>=', $today)) : null;
        $plan = $membership ? Db::first('membership_plans', fn ($q) => $q->where('id', $membership['plan_id'])) : null;
        [$offerAmt, $offerNames] = self::offerDiscount($lines);
        $food = sum_by($lines, fn ($l) => empty($l['complimentary']) ? $l['unit_price'] * $l['qty'] : 0);

        $pointsValue = 0;
        $pointsErr = null;
        $points = max(0, (int) ($in['redeem_points'] ?? 0));
        if ($points > 0) {
            if (!$loyalty['enabled'] || !Outlet::feature('loyalty')) $pointsErr = 'Loyalty is not on for this café';
            elseif (!$customer) $pointsErr = 'Sign in to use points';
            elseif ($points > $customer['loyalty_points']) $pointsErr = "You have {$customer['loyalty_points']} points";
            elseif ($food < $loyalty['min_bill_to_redeem']) $pointsErr = 'Points can be used on bills above ' . rupees($loyalty['min_bill_to_redeem']);
            else $pointsValue = $points * $loyalty['point_value'];
        }
        $gift = null;
        $giftErr = null;
        if (!empty($in['gift_card_code'])) {
            $gift = Db::first('gift_cards', fn ($q) => $q->where('code', strtoupper($in['gift_card_code'])));
            if (!$gift || !$gift['active']) $giftErr = 'Gift card not found';
            elseif ($gift['expires_on'] < $today) $giftErr = 'This gift card has expired';
            elseif ($gift['balance'] <= 0) $giftErr = 'This gift card has no balance left';
        }
        $delivery = $type === 'delivery' && $ds['enabled'] ? Pricing::deliveryCharge($ds, $food) : 0;

        $q = Pricing::quote([
            'lines' => $lines, 'order_type' => $type, 'tax' => $tax, 'today' => $today,
            'coupon' => $couponErr ? null : $coupon, 'membership_discount_pct' => $plan['discount_pct'] ?? 0, 'offer_discount' => $offerAmt,
            'manual_discount' => $in['manual_discount'] ?? null, 'remove_service_charge' => !empty($in['remove_service_charge']),
            'delivery_charge' => $delivery, 'tip' => Outlet::feature('tips') ? max(0, (int) ($in['tip'] ?? 0)) : 0,
            'points_value' => $pointsValue, 'gift_card_amount' => $gift && !$giftErr ? $gift['balance'] : 0,
        ]);
        if (!$couponErr && $q['coupon_error']) $couponErr = $q['coupon_error'];
        // Marketing budget (owner sets a monthly budget; coupons stop once it is spent if enforced).
        if ($coupon && !$couponErr && $q['coupon_discount'] > 0 && !Marketing::canSpend($q['coupon_discount'])) {
            $couponErr = "This month's offer budget is used up";
            $in['coupon_code'] = null;
            $res = self::buildQuote($in, $checkAvailability);
            $res['coupon_error'] = $couponErr;
            return $res;
        }
        return [
            'lines' => $lines, 'quote' => $q, 'coupon' => $coupon && !$couponErr ? $coupon : null, 'coupon_error' => $couponErr,
            'points_used' => $pointsValue ? $points : 0, 'points_error' => $pointsErr,
            'gift_card' => $gift && !$giftErr ? ['code' => $gift['code'], 'balance' => $gift['balance']] : null, 'gift_card_error' => $giftErr,
            'membership' => $plan ? ['name' => $plan['name'], 'discount_pct' => $plan['discount_pct']] : null, 'offers' => $offerNames,
            'min_order_error' => $type === 'delivery' && $food < $ds['min_order'] ? 'Minimum order for delivery is ' . rupees($ds['min_order']) : null,
            'customer_points' => $customer['loyalty_points'] ?? 0,
        ];
    }

    /** Lines of a saved order in quote form. */
    public static function lineInputs(string $orderId): array
    {
        return array_map(fn ($i) => ['item_id' => $i['item_id'], 'variant_id' => $i['variant_id'], 'add_on_ids' => array_column($i['add_ons'] ?? [], 'id'), 'qty' => $i['qty'], 'complimentary' => $i['complimentary']],
            Db::rows('order_items', fn ($q) => $q->where('order_id', $orderId)->where('status', '!=', 'void')));
    }

    /* ---------- order writes ---------- */

    private static function writeItems(string $orderId, array $lines): void
    {
        foreach ($lines as $l) {
            Db::create('order_items', ['order_id' => $orderId, 'item_id' => $l['item_id'], 'variant_id' => $l['variant_id'] ?? null, 'name' => $l['name'], 'variant_name' => $l['variant_name'], 'add_ons' => $l['add_ons'],
                'unit_price' => $l['unit_price'], 'qty' => $l['qty'], 'gst_rate' => $l['gst_rate'], 'note' => $l['note'] ?? '', 'course' => $l['course'] ?? null, 'kot_id' => null, 'status' => 'new', 'complimentary' => !empty($l['complimentary'])], 'oi');
        }
    }

    public static function log(string $orderId, string $status, string $note = ''): void
    {
        Db::create('order_status_logs', ['order_id' => $orderId, 'status' => $status, 'note' => $note], 'osl');
    }

    /** Re-prices the order from its current items. */
    public static function recalc(array $o, array $extra = []): array
    {
        $lines = self::lineInputs($o['id']);
        if (!$lines) return Db::update('orders', $o['id'], ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'service_charge' => 0, 'total' => 0, 'round_off' => 0]);
        $q = self::buildQuote(array_merge(['lines' => $lines, 'order_type' => $o['type'], 'coupon_code' => $o['coupon_code'], 'customer_id' => $o['customer_id'], 'tip' => $o['tip']], $extra), false)['quote'];
        return Db::update('orders', $o['id'], array_intersect_key($q, array_flip(['subtotal', 'discount', 'service_charge', 'tax', 'delivery_charge', 'round_off', 'total'])));
    }

    /** One KOT per station for every item not yet sent (M2.4, M2.5). */
    public static function sendKot(array $o): array
    {
        $pending = Db::rows('order_items', fn ($q) => $q->where('order_id', $o['id'])->where('status', 'new'));
        if (!$pending) return [];
        $table = $o['table_id'] ? Db::first('dining_tables', fn ($q) => $q->where('id', $o['table_id'])) : null;
        $byStation = [];
        foreach ($pending as $it) {
            $item = Db::first('items', fn ($q) => $q->where('id', $it['item_id']));
            $st = $item ? (Db::first('categories', fn ($q) => $q->where('id', $item['category_id']))['station'] ?? 'kitchen') : 'kitchen';
            $byStation[$st][] = $it;
        }
        $kots = [];
        foreach ($byStation as $station => $items) {
            $printer = Db::first('printers', fn ($q) => $q->where('station', $station));
            $kot = Db::create('kots', ['order_id' => $o['id'], 'number' => Counter::next('kot', 100), 'station' => $station, 'status' => 'new', 'table_name' => $table['name'] ?? null, 'order_type' => $o['type'], 'printed' => (bool) ($printer['online'] ?? false)], 'kt');
            foreach ($items as $it) Db::update('order_items', $it['id'], ['kot_id' => $kot['id'], 'status' => 'sent']);
            // Printer offline: the KOT still reaches the kitchen screen; POS is alerted.
            if ($printer && !$printer['online']) Audit::notify('cashier', 'Printer offline', "KOT #{$kot['number']} for $station is queued until {$printer['name']} is back.", '/admin/settings?tab=devices');
            $kots[] = $kot;
        }
        if ($o['status'] === 'placed') {
            Db::update('orders', $o['id'], ['status' => 'accepted', 'accepted_at' => $o['accepted_at'] ?? ctx()->iso()]);
            self::log($o['id'], 'accepted');
        }
        return $kots;
    }

    public static function acceptAfterPlacement(array $o): void
    {
        $flags = Db::settings('business_type_flags');
        $straight = ($o['channel'] === 'qr' && $flags['qr_order_flow'] === 'direct_to_kitchen') || ($o['channel'] === 'app' && $flags['auto_accept_online']);
        if ($straight) {
            self::sendKot($o);
            if ($o['channel'] === 'qr') Audit::notify('captain', 'Table order sent to kitchen', "{$o['number']} · " . rupees($o['total']), '/captain');
        } else Audit::notify($o['channel'] === 'qr' ? 'captain' : 'cashier', $o['channel'] === 'qr' ? 'New QR order' : 'New online order', "{$o['number']} · " . str_replace('_', '-', $o['type']) . ' · ' . rupees($o['total']), $o['channel'] === 'qr' ? '/captain' : '/pos');
    }

    public static function detail(array $o): array
    {
        $d = Db::first('deliveries', fn ($q) => $q->where('order_id', $o['id']));
        $rider = $d && $d['rider_id'] ? Db::first('riders', fn ($q) => $q->where('id', $d['rider_id'])) : null;
        return $o + [
            'items' => Db::rows('order_items', fn ($q) => $q->where('order_id', $o['id']), 'created_at'),
            'kots' => Db::rows('kots', fn ($q) => $q->where('order_id', $o['id'])),
            'table' => $o['table_id'] ? Db::first('dining_tables', fn ($q) => $q->where('id', $o['table_id'])) : null,
            'invoice' => Db::first('invoices', fn ($q) => $q->where('order_id', $o['id'])),
            'delivery' => $d ? $d + ['rider' => $rider ? ['name' => $rider['name'], 'phone' => $rider['phone'], 'vehicle' => $rider['vehicle']] : null] : null,
            'payments' => Db::rows('payments', fn ($q) => $q->where('order_id', $o['id'])),
            'status_log' => Db::rows('order_status_logs', fn ($q) => $q->where('order_id', $o['id']), 'created_at'),
            'feedback' => Db::first('feedback', fn ($q) => $q->where('order_id', $o['id'])),
            'table_bills' => $o['table_session_id'] ? self::tableBills($o['table_session_id']) : null,
        ];
    }

    /** The table's running bill that can still take items: open and not yet paid. */
    public static function runningTableOrder(string $sessionId): ?array
    {
        // Only a bill nobody has paid (or is paying by UPI) yet; a paid bill never changes.
        return Db::first('orders', fn ($q) => $q->where('table_session_id', $sessionId)->whereNotIn('status', ['completed', 'cancelled'])->where('payment_status', 'unpaid')->orderByDesc('created_at'));
    }

    /** Every bill of one table visit, with what is paid and what is still due (paid bills are never changed). */
    public static function tableBills(string $sessionId): array
    {
        $orders = Db::rows('orders', fn ($q) => $q->where('table_session_id', $sessionId)->where('status', '!=', 'cancelled'), 'created_at');
        $inv = array_column(Db::rows('invoices', fn ($q) => $q->whereIn('order_id', array_column($orders, 'id') ?: ['-'])), null, 'order_id');
        $paid = fn ($o) => $o['payment_status'] === 'paid';
        $confirming = fn ($o) => $o['payment_status'] === 'pending';
        return [
            'paid' => sum_by(array_filter($orders, $paid), 'total'),
            'confirming' => sum_by(array_filter($orders, $confirming), 'total'),
            'due' => sum_by(array_filter($orders, fn ($o) => !$paid($o) && !$confirming($o)), 'total'),
            'orders' => array_map(fn ($o) => ['id' => $o['id'], 'number' => $o['number'], 'total' => $o['total'], 'status' => $o['status'], 'payment_status' => $o['payment_status'],
                'invoice_id' => $inv[$o['id']]['id'] ?? null, 'invoice_number' => $inv[$o['id']]['number'] ?? null, 'created_at' => $o['created_at']], $orders),
        ];
    }

    /** Items added after the table's bill was paid go on a new bill for the same visit; the cashier collects only that. */
    private static function followUpOrder(array $paid): array
    {
        $o = Db::create('orders', array_merge(self::emptyOrder(), array_intersect_key($paid, array_flip(['type', 'channel', 'customer_id', 'customer_name', 'customer_phone', 'table_id', 'table_session_id', 'guests', 'captain_id'])), [
            'number' => 'O-' . Counter::next('order', 1000), 'status' => 'accepted', 'payment_status' => 'unpaid', 'idempotency_key' => null, 'accepted_at' => ctx()->iso(),
        ]), 'or');
        self::log($o['id'], 'accepted', "Extra items after {$paid['number']} was paid");
        if ($paid['table_id']) Db::update('dining_tables', $paid['table_id'], ['status' => 'occupied']);
        return $o;
    }

    public static function freeTableIfDone(array $o): void
    {
        if (!$o['table_id'] || !$o['table_session_id']) return;
        // The visit ends when nothing is left to pay; bills already paid (e.g. by UPI at the table) close with it.
        $open = fn () => Db::q('orders')->where('table_session_id', $o['table_session_id'])->where('id', '!=', $o['id'])->whereNotIn('status', ['completed', 'cancelled']);
        if ($open()->where('payment_status', '!=', 'paid')->exists()) return;
        $open()->update(['status' => 'completed', 'completed_at' => ctx()->iso()]);
        Db::update('table_sessions', $o['table_session_id'], ['closed_at' => ctx()->iso()]);
        Db::update('dining_tables', $o['table_id'], ['status' => 'cleaning', 'current_session_id' => null]);
    }

    private static function emptyOrder(): array
    {
        return ['token' => null, 'payment_method' => null, 'customer_id' => null, 'customer_name' => null, 'customer_phone' => null, 'table_id' => null, 'table_session_id' => null, 'guests' => null, 'captain_id' => null, 'cashier_id' => null,
            'address_text' => null, 'pickup_time' => null, 'note' => '', 'coupon_code' => null, 'points_redeemed' => 0, 'gift_card_code' => null, 'subtotal' => 0, 'discount' => 0, 'service_charge' => 0, 'tax' => 0,
            'delivery_charge' => 0, 'packaging' => 0, 'tip' => 0, 'round_off' => 0, 'total' => 0, 'delivery_otp' => null, 'bill_requested' => false];
    }

    private static function openSession(array $table, int $guests, ?string $captainId, ?string $customerId): array
    {
        $s = Db::create('table_sessions', ['table_id' => $table['id'], 'guests' => $guests, 'captain_id' => $captainId, 'customer_id' => $customerId, 'opened_at' => ctx()->iso()], 'ts');
        Db::update('dining_tables', $table['id'], ['status' => 'occupied', 'current_session_id' => $s['id']]);
        return $s;
    }

    private static function versionCheck(array $o, ?string $version): void
    {
        // Two captains editing one table: the stale device gets 409 and refreshes (SRS 16.5).
        if ($version && $version !== $o['updated_at']) throw new HttpError(409, 'Someone else just changed this order. Refreshing…', 'version_conflict');
    }

    /* ---------- routes ---------- */

    public static function routes(): void
    {
        Api::post('/cart/quote', fn (Req $r) => self::buildQuote($r->body), 'customer_optional');

        /** Customer order from app, web or table QR. Payment: UPI to the café's own QR, cash/counter, COD, UPI on delivery. */
        Api::post('/orders', function (Req $r) {
            $b = $r->body;
            need($b, ['lines', 'order_type', 'payment_method']);
            $type = $b['order_type'];
            $flags = Db::settings('business_type_flags')['features'];
            $pay = Db::settings('payment_settings');
            $ds = Db::settings('delivery_settings');
            if ($type === 'delivery' && (!$flags['delivery'] || !$ds['enabled'])) throw HttpError::invalid(['order_type' => 'This café is not taking delivery orders']);
            if ($type === 'dine_in' && !$flags['dine_in']) throw HttpError::invalid(['order_type' => 'Dine-in is not available here']);
            if ($type === 'takeaway' && !$flags['takeaway']) throw HttpError::invalid(['order_type' => 'Takeaway is not available here']);

            $table = null;
            // A table QR means the guest is at the café: such an order can only be dine-in.
            if (!empty($b['table_code']) && $type !== 'dine_in') throw HttpError::invalid(['order_type' => 'Orders from a table QR are dine-in only']);
            if ($type === 'dine_in') {
                need($b, ['table_code']);
                $table = Db::first('dining_tables', fn ($q) => $q->where('qr_code', $b['table_code'])) ?? throw HttpError::invalid(['table_code' => 'This table QR is not valid']);
            }
            $method = $b['payment_method'] === 'online' ? 'upi_qr' : $b['payment_method'];
            $allowed = [
                'upi_qr' => (bool) ($pay['modes']['online'] ?? true) || (bool) ($pay['modes']['upi'] ?? true),
                'cod' => $type === 'delivery' && $pay['modes']['cod'] && $ds['allow_cod'],
                'upi_on_delivery' => $type === 'delivery' && $pay['modes']['upi_on_delivery'] && $ds['allow_upi_on_delivery'],
                'cash' => $type !== 'delivery', 'upi' => $type !== 'delivery',
            ];
            if (empty($allowed[$method])) throw HttpError::invalid(['payment_method' => "This payment option isn't available for this order"]);
            if ($type === 'delivery' && !ctx()->customer) throw HttpError::unauthenticated('Sign in to order for delivery');
            // The rider has to be able to call: delivery needs a verified mobile on the account.
            if ($type === 'delivery' && (!ctx()->customer['phone_verified'] || !ctx()->customer['phone'])) throw new HttpError(422, 'Add and verify your mobile number so the rider can reach you', 'needs_phone');

            $address = null;
            if ($type === 'delivery') {
                if (!empty($b['address_id'])) {
                    $a = Db::find('addresses', $b['address_id'], 'Address');
                    if ($a['customer_id'] !== ctx()->customer['id']) throw HttpError::forbidden();
                    $address = implode(', ', array_filter([$a['line1'], $a['line2'], $a['landmark'], $a['city'], $a['pincode']]));
                } else {
                    need($b, ['address']);
                    $address = $b['address'];
                }
            }
            $c = ctx()->customer;
            $built = self::buildQuote($b + ['customer_id' => $c['id'] ?? null]);
            if ($built['min_order_error']) throw HttpError::invalid(['lines' => $built['min_order_error']]);
            foreach (['coupon_code' => 'coupon_error', 'redeem_points' => 'points_error', 'gift_card_code' => 'gift_card_error'] as $k => $err) {
                if (!empty($b[$k]) && $built[$err]) throw HttpError::invalid([$k => $built[$err]]);
            }
            $q = $built['quote'];

            $session = null;
            if ($table) {
                $session = $table['current_session_id'] ? Db::first('table_sessions', fn ($x) => $x->where('id', $table['current_session_id'])->whereNull('closed_at')) : null;
                $session ??= self::openSession($table, (int) ($b['guests'] ?? 2), null, $c['id'] ?? null);
            }
            // Guest at a table orders more and will pay at the counter: add it to the table's open bill, one bill per visit.
            if ($session && $method !== 'upi_qr' && ($running = self::runningTableOrder($session['id']))) {
                self::writeItems($running['id'], $built['lines']);
                $running = self::recalc($running);
                if (in_array($running['status'], ['ready', 'served'], true)) $running = Db::update('orders', $running['id'], ['status' => 'accepted']);
                self::log($running['id'], $running['status'], 'More items from the table QR');
                self::acceptAfterPlacement($running);
                return ['order' => Db::find('orders', $running['id']), 'payment' => null];
            }
            $o = Db::create('orders', array_merge(self::emptyOrder(), [
                'number' => 'O-' . Counter::next('order', 1000), 'type' => $type, 'channel' => $type === 'dine_in' ? 'qr' : 'app', 'status' => 'placed',
                'payment_status' => $method === 'upi_qr' ? 'pending' : 'unpaid', 'payment_method' => $method,
                'customer_id' => $c['id'] ?? null, 'customer_name' => $c['name'] ?? ($b['customer_name'] ?? null), 'customer_phone' => $c['phone'] ?? ($b['customer_phone'] ?? null),
                'table_id' => $table['id'] ?? null, 'table_session_id' => $session['id'] ?? null, 'guests' => $table ? (int) ($b['guests'] ?? $session['guests'] ?? 2) : null,
                'address_text' => $address, 'pickup_time' => $type === 'takeaway' ? ($b['pickup_time'] ?? null) : null, 'note' => $b['note'] ?? '',
                'coupon_code' => $built['coupon']['code'] ?? null, 'points_redeemed' => $built['points_used'], 'gift_card_code' => $built['gift_card']['code'] ?? null,
                'delivery_otp' => $type === 'delivery' ? (string) random_int(1000, 9999) : null, 'idempotency_key' => ctx()->idem,
            ], array_intersect_key($q, array_flip(['subtotal', 'discount', 'service_charge', 'tax', 'delivery_charge', 'packaging', 'tip', 'round_off', 'total']))), 'or');
            self::writeItems($o['id'], $built['lines']);
            self::log($o['id'], 'placed');
            if ($type === 'delivery') {
                Db::create('deliveries', ['order_id' => $o['id'], 'status' => 'unassigned', 'charge' => $q['delivery_charge'], 'distance_km' => round(1 + mt_rand() / mt_getrandmax() * 5, 1), 'to_collect' => $method === 'upi_qr' ? 0 : $q['payable'], 'collected' => 0], 'dl');
            }
            // A guest at the table is here to eat: the order goes to the kitchen now and is paid later at the table.
            // Takeaway/delivery paid by UPI QR wait until the café confirms the money arrived.
            if ($type === 'dine_in' || $method !== 'upi_qr') self::acceptAfterPlacement($o);
            $qr = $pay['upi_qr_url'] ?: null;
            return ['order' => Db::find('orders', $o['id']), 'payment' => $method === 'upi_qr' ? ['gateway' => 'upi_qr', 'upi_id' => $pay['upi_id'], 'qr_url' => $qr, 'amount' => $q['payable'], 'order_id' => $o['id'], 'key_id' => $pay['gateway_key_id']] : null];
        }, 'customer_optional');

        /**
         * Customer says they paid by scanning the café's UPI QR (optional UTR/reference).
         * Demo mode also accepts { result: success|failure } from the test gateway screen.
         */
        Api::post('/orders/:id/pay', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            if ($o['payment_status'] === 'paid') return ['order' => $o, 'already_paid' => true];
            if ($r->b('result') === 'failure') {
                Db::create('payments', ['order_id' => $o['id'], 'mode' => 'upi', 'amount' => $o['total'], 'status' => 'failed', 'gateway' => 'upi_qr'], 'py');
                return ['order' => Db::update('orders', $o['id'], ['payment_status' => 'unpaid']), 'failed' => true];
            }
            if ($r->b('result') === 'success' && config('cafechalao.demo_mode')) return ['order' => Billing::confirmPayment($o, 'demo')];
            $o = Db::update('orders', $o['id'], ['payment_status' => 'pending', 'payment_reference' => substr((string) $r->b('reference', ''), 0, 60)]);
            Audit::notify('cashier', 'Check UPI payment', "{$o['number']} · " . rupees($o['total']) . ($o['payment_reference'] ? " · ref {$o['payment_reference']}" : ''), '/pos');
            return ['order' => $o, 'awaiting_confirmation' => true];
        }, 'customer_optional');

        Api::get('/me/orders', fn () => Db::rows('orders', fn ($q) => $q->where('customer_id', ctx()->customer['id'])->limit(50), 'created_at', 'desc'), 'customer');

        Api::get('/orders/:id', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $c = ctx();
            $owns = $c->customer && $o['customer_id'] === $c->customer['id'];
            $atTable = $r->q('table_code') && $o['table_id'] && Db::q('dining_tables')->where('id', $o['table_id'])->where('qr_code', $r->q('table_code'))->exists();
            if (!$c->user && !$owns && !$atTable && !$c->rider) throw HttpError::forbidden();
            return self::detail($o);
        }, 'public');

        Api::post('/orders/:id/cancel', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $c = ctx();
            if (!$c->user && !($c->customer && $o['customer_id'] === $c->customer['id'])) throw HttpError::forbidden();
            if (!$c->user && $o['status'] !== 'placed') throw HttpError::invalid(['status' => 'The café has already accepted this order. Please call them.']);
            if (in_array($o['status'], ['completed', 'delivered', 'cancelled'], true)) throw HttpError::invalid(['status' => "This order can't be cancelled now"]);
            Db::update('orders', $o['id'], ['status' => 'cancelled']);
            self::log($o['id'], 'cancelled', (string) $r->b('reason', ''));
            Db::q('kots')->where('order_id', $o['id'])->update(['status' => 'served']);
            if ($c->user) Audit::log('cancel', 'order', $o['id'], ['status' => $o['status']], ['status' => 'cancelled', 'reason' => $r->b('reason')]);
            if ($o['payment_status'] === 'paid') Audit::notify('manager', 'Refund needed', "{$o['number']} was cancelled after payment", '/admin/orders');
            self::freeTableIfDone($o);
            return Db::find('orders', $o['id']);
        }, 'staff_or_customer');

        Api::post('/orders/:id/feedback', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            if ($o['customer_id'] !== ctx()->customer['id']) throw HttpError::forbidden();
            if (Db::q('feedback')->where('order_id', $o['id'])->exists()) throw HttpError::invalid(['order' => "You've already rated this order"]);
            foreach (['food', 'service', 'ambience'] as $k) if (!in_array((int) $r->b($k), [1, 2, 3, 4, 5], true)) throw HttpError::invalid([$k => 'Pick 1 to 5 stars']);
            $f = Db::create('feedback', ['order_id' => $o['id'], 'customer_id' => $o['customer_id'], 'food' => (int) $r->b('food'), 'service' => (int) $r->b('service'), 'ambience' => (int) $r->b('ambience'), 'comment' => (string) $r->b('comment', ''), 'replied' => false], 'fb');
            if (min($f['food'], $f['service'], $f['ambience']) <= 2) Audit::notify('manager', 'Low rating', "{$o['number']}: {$f['food']}/{$f['service']}/{$f['ambience']} stars. \"{$f['comment']}\"", '/admin/marketing/feedback');
            return $f;
        }, 'customer');

        /* Staff side */
        Api::get('/orders', function (Req $r) {
            $rows = Db::rows('orders', function ($q) use ($r) {
                if ($r->q('active')) $q->where(fn ($w) => $w->whereIn('status', self::OPEN)->orWhere(fn ($x) => $x->where('payment_status', '!=', 'paid')->where('status', '!=', 'cancelled')));
                if ($s = $r->q('status')) $q->whereIn('status', explode(',', $s));
                foreach (['type', 'channel'] as $k) if ($v = $r->q($k)) $q->where($k, $v);
                if ($d = $r->q('date')) $q->where('created_at', '>=', self::dayStart($d))->where('created_at', '<', self::dayStart($d, 1));
                if ($f = $r->q('from')) $q->where('created_at', '>=', self::dayStart($f));
                if ($t = $r->q('to')) $q->where('created_at', '<', self::dayStart($t, 1));
                if ($s = $r->q('q')) $q->where(fn ($w) => $w->where('number', 'like', "%$s%")->orWhere('customer_name', 'like', "%$s%")->orWhere('customer_phone', 'like', "%$s%"));
                $q->limit((int) $r->q('limit', 200));
            }, 'created_at', 'desc');
            $ids = array_column($rows, 'id');
            $items = Db::rows('order_items', fn ($q) => $q->whereIn('order_id', $ids ?: ['-']));
            $tables = array_column(Db::rows('dining_tables'), 'name', 'id');
            return array_map(fn ($o) => $o + ['table_name' => $tables[$o['table_id']] ?? null, 'items' => where_eq($items, 'order_id', $o['id'])], $rows);
        });

        /** POS or captain opens an order. A table has one running order: opening it again returns that order. */
        Api::post('/pos/orders', function (Req $r) {
            $b = $r->body;
            need($b, ['type']);
            $me = ctx()->user;
            $table = null;
            $session = null;
            if ($b['type'] === 'dine_in') {
                need($b, ['table_id']);
                $table = Db::find('dining_tables', $b['table_id'], 'Table');
                $session = $table['current_session_id'] ? Db::first('table_sessions', fn ($q) => $q->where('id', $table['current_session_id'])->whereNull('closed_at')) : null;
                $open = $session ? self::runningTableOrder($session['id']) : null;
                if ($open) return self::detail($open);
                $session ??= self::openSession($table, (int) ($b['guests'] ?? 2), $me['role'] === 'captain' ? $me['id'] : null, $b['customer_id'] ?? null);
            }
            $cust = !empty($b['customer_id']) ? Db::find('customers', $b['customer_id'], 'Customer') : null;
            $o = Db::create('orders', array_merge(self::emptyOrder(), [
                'number' => 'O-' . Counter::next('order', 1000), 'token' => $b['type'] === 'counter' ? (Counter::next('token') % 999 ?: 1) : null, 'type' => $b['type'],
                'channel' => $me['role'] === 'captain' ? 'captain' : 'pos', 'status' => 'accepted', 'payment_status' => 'unpaid',
                'customer_id' => $cust['id'] ?? null, 'customer_name' => $cust['name'] ?? ($b['customer_name'] ?? null), 'customer_phone' => $cust['phone'] ?? ($b['customer_phone'] ?? null),
                'table_id' => $table['id'] ?? null, 'table_session_id' => $session['id'] ?? null, 'guests' => $table ? (int) ($b['guests'] ?? $session['guests'] ?? 2) : null,
                'captain_id' => $me['role'] === 'captain' ? $me['id'] : ($session['captain_id'] ?? null), 'cashier_id' => $me['role'] === 'cashier' ? $me['id'] : null,
                'address_text' => $b['address'] ?? null, 'pickup_time' => $b['pickup_time'] ?? null, 'note' => $b['note'] ?? '',
                'delivery_otp' => $b['type'] === 'delivery' ? (string) random_int(1000, 9999) : null, 'idempotency_key' => ctx()->idem, 'accepted_at' => ctx()->iso(),
            ]), 'or');
            self::log($o['id'], 'accepted', 'Opened at POS');
            if (!empty($b['lines'])) {
                self::writeItems($o['id'], self::resolveLines($b['lines']));
                self::recalc($o);
            }
            if ($b['type'] === 'delivery') Db::create('deliveries', ['order_id' => $o['id'], 'status' => 'unassigned', 'charge' => 0, 'distance_km' => 2, 'to_collect' => 0, 'collected' => 0], 'dl');
            return self::detail(Db::find('orders', $o['id']));
        }, 'staff', 'orders.take');

        Api::post('/orders/:id/items', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $visit = $o['table_session_id'] ? Db::first('table_sessions', fn ($q) => $q->where('id', $o['table_session_id'])->whereNull('closed_at')) : null;
            if ($visit && ($o['payment_status'] !== 'unpaid' || $o['status'] === 'completed')) {
                // Table bill already paid: add to the visit's unpaid bill, or start one.
                self::versionCheck($o, $r->b('version'));
                $o = self::runningTableOrder($visit['id']) ?? self::followUpOrder($o);
            } else {
                if (in_array($o['status'], ['completed', 'cancelled', 'delivered'], true) || $o['payment_status'] !== 'unpaid') throw HttpError::invalid(['order' => 'This bill is already closed']);
                self::versionCheck($o, $r->b('version'));
            }
            need($r->body, ['lines']);
            self::writeItems($o['id'], self::resolveLines($r->b('lines')));
            $next = self::recalc($o);
            if (in_array($o['status'], ['ready', 'served'], true)) $next = Db::update('orders', $o['id'], ['status' => 'accepted']);
            if ($r->b('send_kot')) self::sendKot($next);
            return self::detail(Db::find('orders', $o['id']));
        }, 'staff', 'orders.take');

        Api::patch('/order-items/:id', function (Req $r) {
            $it = Db::find('order_items', $r->p('id'), 'Item');
            if ($it['status'] !== 'new') throw HttpError::invalid(['status' => 'Already sent to kitchen. Use void instead.']);
            $o = Db::find('orders', $it['order_id']);
            if ((int) $r->b('qty', -1) === 0) Db::remove('order_items', $it['id']);
            else Db::update('order_items', $it['id'], array_intersect_key($r->body, array_flip(['qty', 'note', 'course'])));
            self::recalc($o);
            return self::detail(Db::find('orders', $o['id']));
        }, 'staff', 'orders.take');

        Api::post('/orders/:id/kot', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            self::versionCheck($o, $r->b('version'));
            $kots = self::sendKot($o);
            if (!$kots) throw HttpError::invalid(['items' => 'No new items to send']);
            return ['kots' => $kots, 'order' => self::detail(Db::find('orders', $o['id']))];
        }, 'staff', 'orders.take');

        Api::patch('/orders/:id/status', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $to = $r->b('status');
            if ($to === 'accepted') {
                if (!ctx()->can('orders.accept_online') && !ctx()->can('orders.take')) throw HttpError::forbidden();
                if ($o['status'] !== 'placed') throw HttpError::invalid(['status' => 'Already accepted']);
                self::sendKot($o);
                if ($o['channel'] === 'qr') Db::update('orders', $o['id'], ['captain_id' => ctx()->user['id']]);
                return self::detail(Db::find('orders', $o['id']));
            }
            $allowed = ['served' => ['ready', 'preparing', 'accepted'], 'completed' => ['ready', 'served', 'delivered'], 'out_for_delivery' => ['ready'], 'ready' => ['preparing', 'accepted']];
            if (!in_array($o['status'], $allowed[$to] ?? [], true)) throw HttpError::invalid(['status' => "Can't move from {$o['status']} to $to"]);
            if ($to === 'completed' && $o['payment_status'] !== 'paid') throw HttpError::invalid(['status' => 'Take payment before closing this order']);
            Db::update('orders', $o['id'], ['status' => $to] + ($to === 'completed' ? ['completed_at' => ctx()->iso()] : []) + ($to === 'ready' ? ['ready_at' => ctx()->iso()] : []));
            if ($to === 'served') Db::q('order_items')->where('order_id', $o['id'])->where('status', 'ready')->update(['status' => 'served']);
            self::log($o['id'], $to);
            if ($to === 'completed') self::freeTableIfDone(Db::find('orders', $o['id']));
            return self::detail(Db::find('orders', $o['id']));
        }, 'staff', 'orders.take');

        Api::post('/orders/:id/request-bill', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            $guest = $r->q('table_code') && $o['table_id'] && Db::q('dining_tables')->where('id', $o['table_id'])->where('qr_code', $r->q('table_code'))->exists();
            if (!ctx()->user && !$guest) throw HttpError::forbidden();
            if ($o['payment_status'] !== 'unpaid' && $o['table_session_id']) $o = self::runningTableOrder($o['table_session_id']) ?? $o;
            Db::update('orders', $o['id'], ['bill_requested' => true]);
            if ($o['table_id']) Db::update('dining_tables', $o['table_id'], ['status' => 'bill_requested']);
            Audit::notify('cashier', 'Bill requested', $o['number'], '/pos');
            return self::detail(Db::find('orders', $o['id']));
        }, 'public');

        /** Void a sent item; above the void limit a manager PIN (approver_id) is needed. */
        Api::post('/orders/:id/void', function (Req $r) {
            $o = Db::find('orders', $r->p('id'), 'Order');
            need($r->body, ['order_item_id', 'reason']);
            $it = Db::find('order_items', $r->b('order_item_id'), 'Item');
            if ($it['order_id'] !== $o['id'] || $it['status'] === 'void') throw HttpError::invalid(['order_item_id' => 'Item not on this order']);
            $qty = min((int) $r->b('qty', $it['qty']), $it['qty']);
            $amount = $it['unit_price'] * $qty;
            $approver = Approvals::check('void_value', $amount, $r->b('approver_id'));
            if ($qty === $it['qty']) Db::update('order_items', $it['id'], ['status' => 'void']);
            else {
                Db::update('order_items', $it['id'], ['qty' => $it['qty'] - $qty]);
                Db::create('order_items', array_diff_key($it, array_flip(['id', 'tenant_id', 'created_at', 'updated_at', 'created_by', 'deleted_at'])) + ['qty' => $qty, 'status' => 'void'], 'oi');
            }
            Db::create('voids', ['order_id' => $o['id'], 'order_item_id' => $it['id'], 'amount' => $amount, 'reason' => $r->b('reason'), 'approved_by' => $approver['id'] ?? null], 'vd');
            Audit::log('void', 'order_item', $it['id'], ['qty' => $it['qty'], 'name' => $it['name']], ['voided' => $qty, 'reason' => $r->b('reason'), 'approved_by' => $approver['name'] ?? null]);
            self::recalc($o);
            return self::detail(Db::find('orders', $o['id']));
        }, 'staff', 'orders.take');

        Api::post('/order-items/:id/complimentary', function (Req $r) {
            $it = Db::find('order_items', $r->p('id'), 'Item');
            Approvals::check('discount_pct', 100, $r->b('approver_id'));
            Db::update('order_items', $it['id'], ['complimentary' => !$it['complimentary']]);
            Audit::log('discount', 'order_item', $it['id'], ['complimentary' => $it['complimentary']], ['complimentary' => !$it['complimentary'], 'reason' => $r->b('reason', '')]);
            $o = self::recalc(Db::find('orders', $it['order_id']));
            return self::detail($o);
        }, 'staff', 'pos.discount_preset');

        /* Kitchen display */
        Api::get('/kots', function (Req $r) {
            $since = ctx()->iso(ctx()->now()->modify('-12 hours'));
            $kots = Db::rows('kots', function ($q) use ($r, $since) {
                if ($s = $r->q('station')) $q->where('station', $s);
                if (!$r->q('all')) $q->where('status', '!=', 'served');
                $q->where('created_at', '>=', $since);
            }, 'created_at');
            $orders = array_column(Db::rows('orders', fn ($q) => $q->whereIn('id', array_column($kots, 'order_id') ?: ['-'])), null, 'id');
            $items = Db::rows('order_items', fn ($q) => $q->whereIn('kot_id', array_column($kots, 'id') ?: ['-']));
            $out = [];
            foreach ($kots as $k) {
                $o = $orders[$k['order_id']] ?? null;
                if (!$o || $o['status'] === 'cancelled') continue;
                $out[] = $k + ['order_number' => $o['number'], 'order_status' => $o['status'], 'token' => $o['token'], 'customer_name' => $o['customer_name'], 'note' => $o['note'] ?? '', 'items' => where_eq($items, 'kot_id', $k['id'])];
            }
            return $out;
        }, 'staff', 'kds.use');

        Api::patch('/kots/:id', function (Req $r) {
            $k = Db::find('kots', $r->p('id'), 'KOT');
            $to = $r->b('status');
            if (!in_array($to, ['preparing', 'ready', 'served'], true)) throw HttpError::invalid(['status' => 'Unknown status']);
            Db::update('kots', $k['id'], ['status' => $to] + ($to === 'preparing' && !$k['started_at'] ? ['started_at' => ctx()->iso()] : []) + ($to === 'ready' ? ['ready_at' => ctx()->iso()] : []));
            Db::q('order_items')->where('kot_id', $k['id'])->where('status', '!=', 'void')->update(['status' => $to, 'updated_at' => ctx()->iso()]);
            self::syncFromKots($k['order_id']);
            return Db::find('kots', $k['id']);
        }, 'staff', 'kds.use');

        Api::patch('/kot-items/:id', function (Req $r) {
            $it = Db::find('order_items', $r->p('id'), 'Item');
            Db::update('order_items', $it['id'], ['status' => $r->b('status')]);
            if ($it['kot_id']) {
                $sib = Db::rows('order_items', fn ($q) => $q->where('kot_id', $it['kot_id'])->where('status', '!=', 'void'));
                $k = Db::find('kots', $it['kot_id']);
                if (!array_filter($sib, fn ($x) => !in_array($x['status'], ['ready', 'served'], true))) Db::update('kots', $k['id'], ['status' => 'ready', 'ready_at' => ctx()->iso()]);
                elseif ($k['status'] === 'new') Db::update('kots', $k['id'], ['status' => 'preparing', 'started_at' => ctx()->iso()]);
                self::syncFromKots($it['order_id']);
            }
            return Db::find('order_items', $it['id']);
        }, 'staff', 'kds.use');

        Api::post('/kots/:id/reprint', function (Req $r) {
            $k = Db::find('kots', $r->p('id'), 'KOT');
            Audit::log('reprint', 'kot', $k['id'], null, ['number' => $k['number']]);
            return Db::update('kots', $k['id'], ['printed' => true]);
        }, 'staff', 'pos.reprint');
    }

    /** UTC start of a local business date (+$days). */
    public static function dayStart(string $date, int $days = 0): string
    {
        $d = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone(config('cafechalao.timezone')));
        return ctx()->iso($d->modify("+$days day"));
    }

    private static function syncFromKots(string $orderId): void
    {
        $o = Db::find('orders', $orderId);
        $kots = Db::rows('kots', fn ($q) => $q->where('order_id', $orderId));
        $pendingNew = Db::q('order_items')->where('order_id', $orderId)->where('status', 'new')->exists();
        $allReady = $kots && !array_filter($kots, fn ($k) => !in_array($k['status'], ['ready', 'served'], true));
        if ($allReady && !$pendingNew) {
            if (!in_array($o['status'], ['ready', 'served', 'out_for_delivery', 'delivered', 'completed'], true)) {
                Db::update('orders', $o['id'], ['status' => 'ready', 'ready_at' => ctx()->iso()]);
                self::log($o['id'], 'ready');
                $who = $o['type'] === 'delivery' ? 'rider' : ($o['type'] === 'dine_in' ? 'captain' : 'cashier');
                Audit::notify($who, 'Order ready', "{$o['number']} is ready", $who === 'captain' ? '/captain' : '/pos', $o['captain_id']);
            }
        } elseif (array_filter($kots, fn ($k) => $k['status'] === 'preparing') && in_array($o['status'], ['accepted', 'placed'], true)) {
            Db::update('orders', $o['id'], ['status' => 'preparing']);
            self::log($o['id'], 'preparing');
        }
    }
}
