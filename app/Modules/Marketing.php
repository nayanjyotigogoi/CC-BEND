<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;

/** Marketing (M4): loyalty, coupons, gift cards, memberships, offers, greetings, feedback, monthly marketing budget. */
class Marketing
{
    /**
     * Marketing budget: the owner sets an amount per month. Spend = coupon discounts given + marketing expenses.
     * With "enforce" on, coupons stop applying once the budget is used up.
     */
    public static function budget(?string $month = null): array
    {
        $month ??= substr(ctx()->today(), 0, 7);
        $b = Db::first('marketing_budgets', fn ($q) => $q->where('month', $month));
        $from = Orders::dayStart("$month-01");
        $to = Orders::dayStart(date('Y-m-d', strtotime("$month-01 +1 month")));
        $coupons = sum_by(Db::rows('coupon_redemptions', fn ($q) => $q->where('created_at', '>=', $from)->where('created_at', '<', $to)), 'amount');
        $cat = Db::first('expense_categories', fn ($q) => $q->where('name', 'Marketing'));
        $expenses = $cat ? sum_by(Db::rows('expenses', fn ($q) => $q->where('category_id', $cat['id'])->where('status', 'approved')->where('spent_on', 'like', "$month%")), 'amount') : 0;
        $amount = $b['amount'] ?? 0;
        $spent = $coupons + $expenses;
        return ['id' => $b['id'] ?? null, 'month' => $month, 'amount' => $amount, 'enforce' => (bool) ($b['enforce'] ?? false), 'spent' => ['coupons' => $coupons, 'expenses' => $expenses, 'total' => $spent], 'remaining' => $amount - $spent];
    }

    public static function canSpend(int $amount): bool
    {
        $b = self::budget();
        return !$b['enforce'] || !$b['amount'] || $b['remaining'] >= $amount;
    }

    public static function routes(): void
    {
        Api::crud('/coupons', 'coupons', 'cp', ['perm' => 'marketing.manage', 'required' => ['code', 'kind', 'value'], 'audit' => 'coupon',
            'defaults' => ['max_discount' => null, 'min_order' => 0, 'usage_limit_per_customer' => 1, 'used_count' => 0, 'order_types' => [], 'item_ids' => [], 'active' => true]]);
        Api::crud('/membership-plans', 'membership_plans', 'mp', ['perm' => 'marketing.manage', 'required' => ['name', 'price', 'validity_days'], 'defaults' => ['discount_pct' => 0, 'benefits' => '', 'active' => true]]);
        Api::crud('/offers', 'offers', 'of', ['perm' => 'marketing.manage', 'required' => ['name', 'kind'], 'defaults' => ['description' => '', 'discount_pct' => 0, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start_time' => '00:00', 'end_time' => '23:59', 'item_ids' => [], 'buy_qty' => 0, 'get_qty' => 0, 'active' => true]]);

        Api::get('/marketing/budget', fn (Req $r) => self::budget($r->q('month')), 'staff', 'marketing.manage');
        Api::put('/marketing/budget', function (Req $r) {
            need($r->body, ['month', 'amount']);
            $cur = Db::first('marketing_budgets', fn ($q) => $q->where('month', $r->b('month')));
            $data = ['month' => $r->b('month'), 'amount' => (int) $r->b('amount'), 'enforce' => (bool) $r->b('enforce')];
            $cur ? Db::update('marketing_budgets', $cur['id'], $data) : Db::create('marketing_budgets', $data, 'mb');
            return self::budget($r->b('month'));
        }, 'staff', 'marketing.manage');

        Api::get('/public/offers', fn () => [
            'offers' => Db::rows('offers', fn ($q) => $q->where('active', 1)),
            'coupons' => array_map(fn ($c) => array_intersect_key($c, array_flip(['code', 'kind', 'value', 'min_order', 'max_discount'])), Db::rows('coupons', fn ($q) => $q->where('active', 1)->where('valid_to', '>=', ctx()->today()))),
        ], 'public');

        Api::get('/loyalty-settings', fn () => Db::settings('loyalty_settings'), 'staff', 'marketing.manage');

        /* Gift cards (M4.4) */
        Api::get('/gift-cards', fn () => Db::rows('gift_cards', null, 'created_at', 'desc'), 'staff', 'marketing.manage');
        Api::post('/gift-cards', function (Req $r) {
            need($r->body, ['value']);
            $g = Db::create('gift_cards', ['code' => 'GIFT-' . random_int(1000, 9999) . '-' . random_int(1000, 9999), 'initial_value' => (int) $r->b('value'), 'balance' => (int) $r->b('value'), 'customer_id' => $r->b('customer_id'),
                'expires_on' => $r->b('expires_on') ?: ctx()->now()->modify('+365 days')->format('Y-m-d'), 'active' => true], 'gc');
            Db::create('gift_card_txns', ['gift_card_id' => $g['id'], 'amount' => $g['initial_value']], 'gt');
            return $g;
        }, 'staff', 'marketing.manage');
        Api::put('/gift-cards/:id', fn (Req $r) => Db::update('gift_cards', Db::find('gift_cards', $r->p('id'))['id'], ['active' => (bool) $r->b('active')]), 'staff', 'marketing.manage');
        Api::get('/gift-cards/check/:code', function (Req $r) {
            $g = Db::first('gift_cards', fn ($q) => $q->where('code', strtoupper($r->p('code')))) ?? throw HttpError::invalid(['code' => 'Gift card not found']);
            return array_intersect_key($g, array_flip(['code', 'balance', 'expires_on', 'active']));
        }, 'public');

        /* Memberships (M4.5) */
        Api::get('/memberships', function () {
            $cust = array_column(Db::rows('customers'), 'name', 'id');
            $plans = array_column(Db::rows('membership_plans'), 'name', 'id');
            return array_map(fn ($m) => $m + ['customer' => $cust[$m['customer_id']] ?? null, 'plan' => $plans[$m['plan_id']] ?? null], Db::rows('memberships'));
        }, 'staff', 'marketing.manage');
        Api::post('/memberships', function (Req $r) {
            need($r->body, ['customer_id', 'plan_id']);
            $plan = Db::find('membership_plans', $r->b('plan_id'), 'Plan');
            $c = Db::find('customers', $r->b('customer_id'), 'Customer');
            Db::q('memberships')->where('customer_id', $c['id'])->where('status', 'active')->update(['status' => 'expired']);
            return Db::create('memberships', ['plan_id' => $plan['id'], 'customer_id' => $c['id'], 'starts_on' => ctx()->today(), 'ends_on' => ctx()->now()->modify("+{$plan['validity_days']} days")->format('Y-m-d'), 'status' => 'active'], 'mb');
        }, 'staff', 'marketing.manage');
        Api::get('/memberships/expiring', function () {
            $limit = ctx()->now()->modify('+15 days')->format('Y-m-d');
            $outlet = Db::settings('outlets')['name'];
            return array_map(function ($m) use ($outlet) {
                $c = Db::first('customers', fn ($q) => $q->where('id', $m['customer_id']));
                $p = Db::first('membership_plans', fn ($q) => $q->where('id', $m['plan_id']));
                return $m + ['customer' => $c['name'] ?? null, 'phone' => $c['phone'] ?? null, 'plan' => $p['name'] ?? null,
                    'whatsapp_url' => wa_link($c['phone'] ?? null, "Hi {$c['name']}, your {$p['name']} membership at $outlet ends on {$m['ends_on']}. Renew to keep your {$p['discount_pct']}% off!")];
            }, Db::rows('memberships', fn ($q) => $q->where('status', 'active')->where('ends_on', '<=', $limit)));
        }, 'staff', 'marketing.manage');

        /** Birthday and anniversary greetings (M4.7): free click-to-WhatsApp links or email, only with consent. */
        Api::get('/greetings/today', function (Req $r) {
            $md = substr(ctx()->today(), 5);
            $outlet = Db::settings('outlets')['name'];
            $sent = Db::rows('message_logs', fn ($q) => $q->whereIn('template', ['birthday', 'anniversary'])->where('created_at', '>=', Orders::dayStart(ctx()->today())));
            $out = [];
            foreach (Db::rows('customers', fn ($q) => $q->where('marketing_consent', 1)) as $c) {
                foreach (['birthday', 'anniversary'] as $kind) {
                    if (!$c[$kind] || substr($c[$kind], 5) !== $md) continue;
                    $first = explode(' ', $c['name'])[0];
                    $coupon = $r->q('coupon_code') ? ' Use code ' . $r->q('coupon_code') . ' for a treat on us.' : '';
                    $body = $kind === 'birthday' ? "Happy birthday $first! 🎂 Wishing you a wonderful year from all of us at $outlet.$coupon" : "Happy anniversary $first! 💐 Celebrate with us at $outlet.$coupon";
                    $out[] = ['customer_id' => $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], 'kind' => $kind, 'body' => $body,
                        'sent' => (bool) first_where($sent, fn ($m) => $m['customer_id'] === $c['id'] && $m['template'] === $kind),
                        'whatsapp_url' => wa_link($c['phone'], $body), 'mailto' => $c['email'] ? 'mailto:' . $c['email'] . '?subject=' . rawurlencode("Happy $kind from $outlet") . '&body=' . rawurlencode($body) : null];
                }
            }
            return $out;
        }, 'staff', 'marketing.manage');
        Api::post('/greetings/log', function (Req $r) {
            need($r->body, ['customer_id', 'kind', 'body', 'channel']);
            $c = Db::find('customers', $r->b('customer_id'), 'Customer');
            return Db::create('message_logs', ['customer_id' => $c['id'], 'channel' => $r->b('channel'), 'template' => $r->b('kind'), 'to' => $r->b('channel') === 'email' ? $c['email'] : $c['phone'], 'body' => $r->b('body'), 'status' => 'sent'], 'ml');
        }, 'staff', 'marketing.manage');
        Api::get('/message-logs', fn () => Db::rows('message_logs', fn ($q) => $q->limit(200), 'created_at', 'desc'), 'staff', 'marketing.manage');

        /* Feedback (M4.8) */
        Api::get('/feedback', function (Req $r) {
            $rows = Db::rows('feedback', fn ($q) => $q->limit(300)->when($r->q('low'), fn ($x) => $x->where(fn ($w) => $w->where('food', '<=', 2)->orWhere('service', '<=', 2)->orWhere('ambience', '<=', 2))), 'created_at', 'desc');
            $orders = array_column(Db::rows('orders', fn ($q) => $q->whereIn('id', array_column($rows, 'order_id') ?: ['-'])), null, 'id');
            $cust = array_column(Db::rows('customers', fn ($q) => $q->whereIn('id', array_filter(array_column($rows, 'customer_id')) ?: ['-'])), null, 'id');
            return array_map(fn ($f) => $f + ['order_number' => $orders[$f['order_id']]['number'] ?? null, 'order_type' => $orders[$f['order_id']]['type'] ?? null,
                'customer_name' => $cust[$f['customer_id']]['name'] ?? 'Guest', 'customer_phone' => $cust[$f['customer_id']]['phone'] ?? null], $rows);
        }, 'staff', 'customers.view');
        Api::patch('/feedback/:id', fn (Req $r) => Db::update('feedback', Db::find('feedback', $r->p('id'))['id'], ['replied' => (bool) $r->b('replied')]), 'staff', 'customers.view');
    }
}
