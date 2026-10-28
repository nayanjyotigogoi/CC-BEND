<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;

/**
 * Customers belong to the café (L3 data owned by L2): the same person at two cafés is two separate rows,
 * and neither café can see the other's.
 */
class Customers
{
    private static function norm(?string $p): string { return substr(preg_replace('/\D/', '', (string) $p), -10); }

    public static function routes(): void
    {
        Api::get('/customers', fn (Req $r) => Db::rows('customers', function ($q) use ($r) {
            if ($s = $r->q('q')) $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
            if ($t = $r->q('tag')) $q->where('tags', 'like', "%\"$t\"%");
            $q->orderByDesc('last_visit_at')->limit((int) $r->q('limit', 500));
        }));

        Api::get('/customers/:id', function (Req $r) {
            if (!ctx()->can('customers.view') && !ctx()->can('customers.create')) throw HttpError::forbidden();
            $c = Db::find('customers', $r->p('id'), 'Customer');
            $orders = Db::rows('orders', fn ($q) => $q->where('customer_id', $c['id'])->limit(30), 'created_at', 'desc');
            $paidIds = array_column(array_filter($orders, fn ($o) => $o['payment_status'] === 'paid'), 'id');
            $fav = [];
            foreach (Db::rows('order_items', fn ($q) => $q->whereIn('order_id', $paidIds ?: ['-'])) as $it) $fav[$it['name']] = ($fav[$it['name']] ?? 0) + $it['qty'];
            arsort($fav);
            return $c + [
                'average_bill' => $c['visits'] ? (int) round($c['total_spend'] / $c['visits']) : 0,
                'favourites' => array_map(fn ($n, $q) => ['name' => $n, 'qty' => $q], array_keys(array_slice($fav, 0, 3, true)), array_slice($fav, 0, 3)),
                'addresses' => Db::rows('addresses', fn ($q) => $q->where('customer_id', $c['id'])), 'orders' => $orders,
                'loyalty' => Db::rows('loyalty_ledger', fn ($q) => $q->where('customer_id', $c['id']), 'created_at', 'desc'),
                'memberships' => Db::rows('memberships', fn ($q) => $q->where('customer_id', $c['id'])),
                'gift_cards' => Db::rows('gift_cards', fn ($q) => $q->where('customer_id', $c['id'])),
                'feedback' => Db::rows('feedback', fn ($q) => $q->where('customer_id', $c['id'])),
            ];
        });

        /** Walk-in profile (M1.2); the same mobile returns the existing profile. */
        Api::post('/customers', function (Req $r) {
            need($r->body, ['name', 'phone']);
            if (strlen(self::norm($r->b('phone'))) !== 10) throw HttpError::invalid(['phone' => 'Enter a 10-digit mobile number']);
            $dup = first_where(Db::rows('customers', fn ($q) => $q->whereNotNull('phone')), fn ($c) => self::norm($c['phone']) === self::norm($r->b('phone')));
            if ($dup) return $dup;
            return Db::create('customers', ['name' => $r->b('name'), 'phone' => $r->b('phone'), 'phone_verified' => false, 'birthday' => $r->b('birthday') ?: null, 'anniversary' => $r->b('anniversary') ?: null,
                'source' => 'walk_in', 'marketing_consent' => (bool) $r->b('marketing_consent'), 'tags' => ['new'], 'loyalty_points' => 0, 'total_spend' => 0, 'visits' => 0], 'cu');
        }, 'staff', 'customers.create');

        Api::put('/customers/:id', function (Req $r) {
            $c = Db::find('customers', $r->p('id'), 'Customer');
            $patch = array_intersect_key($r->body, array_flip(['name', 'birthday', 'anniversary', 'tags', 'marketing_consent', 'email']));
            Audit::log('update', 'customer', $c['id'], null, $patch);
            return Db::update('customers', $c['id'], $patch);
        }, 'staff', 'customers.view');

        Api::post('/customers/:id/points', function (Req $r) {
            $c = Db::find('customers', $r->p('id'), 'Customer');
            need($r->body, ['reason']);
            $pts = (int) $r->b('points');
            Db::create('loyalty_ledger', ['customer_id' => $c['id'], 'points' => $pts, 'reason' => 'adjust'], 'll');
            Audit::log('points_adjust', 'customer', $c['id'], ['points' => $c['loyalty_points']], ['points' => $c['loyalty_points'] + $pts, 'reason' => $r->b('reason')]);
            return Db::update('customers', $c['id'], ['loyalty_points' => max(0, $c['loyalty_points'] + $pts)]);
        }, 'staff', 'marketing.manage');

        /* The customer's own profile */
        Api::get('/me', fn () => ['customer' => ctx()->customer, 'needs_profile' => Auth::needsProfile(ctx()->customer)], 'customer');
        Api::put('/me', function (Req $r) {
            $me = ctx()->customer;
            $patch = array_intersect_key($r->body, array_flip(['name', 'birthday', 'anniversary', 'gender', 'marketing_consent', 'email']));
            if (array_key_exists('marketing_consent', $patch) && (bool) $patch['marketing_consent'] !== $me['marketing_consent']) {
                Db::create('consent_logs', ['customer_id' => $me['id'], 'purpose' => 'marketing_whatsapp', 'granted' => (bool) $patch['marketing_consent']], 'cl');
            }
            $c = Db::update('customers', $me['id'], $patch);
            return ['customer' => $c, 'needs_profile' => Auth::needsProfile($c)];
        }, 'customer');

        Api::get('/me/addresses', fn () => Db::rows('addresses', fn ($q) => $q->where('customer_id', ctx()->customer['id'])), 'customer');
        Api::post('/me/addresses', function (Req $r) {
            need($r->body, ['line1', 'city', 'pincode']);
            if (!preg_match('/^\d{6}$/', (string) $r->b('pincode'))) throw HttpError::invalid(['pincode' => 'Pincode must be 6 digits']);
            return Db::create('addresses', ['customer_id' => ctx()->customer['id'], 'label' => $r->b('label', 'Home'), 'line1' => $r->b('line1'), 'line2' => (string) $r->b('line2', ''), 'landmark' => (string) $r->b('landmark', ''), 'city' => $r->b('city'), 'pincode' => (string) $r->b('pincode')], 'ad');
        }, 'customer');
        Api::delete('/me/addresses/:id', function (Req $r) {
            $a = Db::find('addresses', $r->p('id'), 'Address');
            if ($a['customer_id'] !== ctx()->customer['id']) throw HttpError::forbidden();
            Db::remove('addresses', $a['id']);
            return ['deleted' => true];
        }, 'customer');

        Api::get('/me/wallet', function () {
            $c = ctx()->customer;
            $plans = array_column(Db::rows('membership_plans'), null, 'id');
            return [
                'points' => $c['loyalty_points'], 'loyalty' => Db::settings('loyalty_settings'),
                'ledger' => array_slice(Db::rows('loyalty_ledger', fn ($q) => $q->where('customer_id', $c['id']), 'created_at', 'desc'), 0, 20),
                'gift_cards' => Db::rows('gift_cards', fn ($q) => $q->where('customer_id', $c['id'])),
                'memberships' => array_map(fn ($m) => $m + ['plan' => $plans[$m['plan_id']] ?? null], Db::rows('memberships', fn ($q) => $q->where('customer_id', $c['id']))),
                'plans' => array_values(array_filter($plans, fn ($p) => $p['active'])),
            ];
        }, 'customer');

        /** DPDP Act: export and delete (orders stay for tax records, without the name). */
        Api::get('/me/export', function () {
            $c = ctx()->customer;
            return ['profile' => $c, 'addresses' => Db::rows('addresses', fn ($q) => $q->where('customer_id', $c['id'])), 'orders' => Db::rows('orders', fn ($q) => $q->where('customer_id', $c['id'])), 'consents' => Db::rows('consent_logs', fn ($q) => $q->where('customer_id', $c['id']))];
        }, 'customer');
        Api::delete('/me', function () {
            $c = ctx()->customer;
            Db::update('customers', $c['id'], ['name' => 'Deleted customer', 'phone' => null, 'email' => null, 'google_id' => null, 'birthday' => null, 'anniversary' => null, 'marketing_consent' => false, 'deleted_at' => ctx()->iso()]);
            Db::q('addresses')->where('customer_id', $c['id'])->update(['deleted_at' => ctx()->iso()]);
            Db::q('orders')->where('customer_id', $c['id'])->update(['customer_name' => null, 'customer_phone' => null, 'address_text' => null]);
            Audit::log('delete', 'customer', $c['id'], ['name' => $c['name']], ['anonymised' => true]);
            return ['deleted' => true];
        }, 'customer');
    }
}
