<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Media;

/** Delivery and rider tab (M8): own and freelance riders, requests, first accept wins, OTP/photo proof, settlement. */
class Delivery
{
    private static function row(array $d, bool $withPickup = false): array
    {
        $o = Db::first('orders', fn ($q) => $q->where('id', $d['order_id']));
        $r = $d['rider_id'] ? Db::first('riders', fn ($q) => $q->where('id', $d['rider_id'])) : null;
        $out = $d + [
            'rider_name' => $r['name'] ?? null,
            'order' => $o ? array_intersect_key($o, array_flip(['id', 'number', 'status', 'total', 'payment_method', 'payment_status', 'customer_name', 'customer_phone', 'address_text', 'created_at', 'ready_at'])) : null,
            'requested_riders' => array_column(Db::rows('delivery_requests', fn ($q) => $q->where('delivery_id', $d['id'])->where('status', 'sent')), 'rider_id'),
        ];
        if ($withPickup) {
            $outlet = Db::settings('outlets');
            $out['pickup'] = ['name' => $outlet['name'], 'address' => $outlet['address'], 'phone' => $outlet['phone']];
        }
        return $out;
    }

    private static function settlement(array $r, string $date): array
    {
        $from = Orders::dayStart($date);
        $to = Orders::dayStart($date, 1);
        $dels = Db::rows('deliveries', fn ($q) => $q->where('rider_id', $r['id'])->where('status', 'delivered')->where('delivered_at', '>=', $from)->where('delivered_at', '<', $to));
        $cash = sum_by(where_eq($dels, 'collected_mode', 'cash'), 'collected');
        $charges = $r['type'] === 'freelance' ? sum_by($dels, 'charge') : 0; // in-house riders are paid on salary day
        $s = Db::first('rider_settlements', fn ($q) => $q->where('rider_id', $r['id'])->where('business_date', $date));
        return ['rider_id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'deliveries' => count($dels), 'cash_collected' => $cash, 'upi_collected' => sum_by(where_eq($dels, 'collected_mode', 'upi'), 'collected'),
            'charges_earned' => $charges, 'net' => $cash - $charges, 'settled' => (bool) ($s['settled'] ?? false), 'settlement_id' => $s['id'] ?? null];
    }

    public static function routes(): void
    {
        Api::crud('/riders', 'riders', 'rd', ['perm' => 'delivery.assign', 'required' => ['name', 'phone', 'type'], 'defaults' => ['vehicle' => '', 'id_document' => '', 'online' => false, 'active' => true], 'audit' => 'rider']);

        Api::get('/deliveries', function (Req $r) {
            $rows = Db::rows('deliveries', function ($q) use ($r) {
                if ($r->q('active')) $q->whereNotIn('status', ['delivered', 'failed']);
                if ($d = $r->q('date')) $q->where('created_at', '>=', Orders::dayStart($d))->where('created_at', '<', Orders::dayStart($d, 1));
                $q->limit(200);
            }, 'created_at', 'desc');
            return array_values(array_filter(array_map(fn ($d) => self::row($d), $rows), fn ($d) => ($d['order']['status'] ?? '') !== 'cancelled'));
        }, 'staff', 'delivery.assign');

        Api::post('/deliveries/:id/assign', function (Req $r) {
            $d = Db::find('deliveries', $r->p('id'), 'Delivery');
            need($r->body, ['rider_ids']);
            if (in_array($d['status'], ['picked_up', 'on_the_way', 'delivered'], true)) throw HttpError::invalid(['rider_ids' => 'Already on the way']);
            Db::q('delivery_requests')->where('delivery_id', $d['id'])->where('status', 'sent')->update(['status' => 'declined']);
            foreach ($r->b('rider_ids') as $rid) Db::create('delivery_requests', ['delivery_id' => $d['id'], 'rider_id' => Db::find('riders', $rid, 'Rider')['id'], 'status' => 'sent'], 'dq');
            return self::row(Db::update('deliveries', $d['id'], ['status' => 'requested', 'rider_id' => null]));
        }, 'staff', 'delivery.assign');

        /* Rider app */
        Api::get('/rider/me', fn () => ctx()->rider, 'rider');
        Api::patch('/rider/me', fn (Req $r) => Db::update('riders', ctx()->rider['id'], ['online' => (bool) $r->b('online')]), 'rider');
        Api::get('/rider/deliveries', function () {
            $me = ctx()->rider;
            $reqIds = array_column(Db::rows('delivery_requests', fn ($q) => $q->where('rider_id', $me['id'])->where('status', 'sent')), 'delivery_id');
            return [
                'active' => array_map(fn ($d) => self::row($d, true), Db::rows('deliveries', fn ($q) => $q->where('rider_id', $me['id'])->whereIn('status', ['accepted', 'picked_up', 'on_the_way']))),
                'requests' => array_map(fn ($d) => self::row($d, true), Db::rows('deliveries', fn ($q) => $q->whereIn('id', $reqIds ?: ['-'])->where('status', 'requested'))),
                'history' => array_map(fn ($d) => self::row($d, true), Db::rows('deliveries', fn ($q) => $q->where('rider_id', $me['id'])->whereIn('status', ['delivered', 'failed'])->limit(50), 'delivered_at', 'desc')),
            ];
        }, 'rider');

        /** First accept wins: the row is locked, so a second rider gets "already taken". */
        Api::post('/deliveries/:id/accept', function (Req $r) {
            $me = ctx()->rider;
            $d = Db::first('deliveries', fn ($q) => $q->where('id', $r->p('id'))->lockForUpdate()) ?? throw HttpError::notFound('Delivery');
            if (!Db::q('delivery_requests')->where('delivery_id', $d['id'])->where('rider_id', $me['id'])->exists()) throw HttpError::forbidden("This request wasn't sent to you");
            if ($d['rider_id'] && $d['rider_id'] !== $me['id']) throw new HttpError(409, 'Already taken by another rider', 'already_taken');
            Db::update('deliveries', $d['id'], ['rider_id' => $me['id'], 'status' => 'accepted', 'accepted_at' => ctx()->iso()]);
            foreach (Db::rows('delivery_requests', fn ($q) => $q->where('delivery_id', $d['id'])) as $q) Db::update('delivery_requests', $q['id'], ['status' => $q['rider_id'] === $me['id'] ? 'accepted' : 'taken']);
            Audit::notify('cashier', 'Rider assigned', "{$me['name']} accepted " . (Db::first('orders', fn ($q) => $q->where('id', $d['order_id']))['number'] ?? ''), '/pos');
            return self::row(Db::find('deliveries', $d['id']));
        }, 'rider');

        Api::post('/deliveries/:id/decline', function (Req $r) {
            Db::q('delivery_requests')->where('delivery_id', $r->p('id'))->where('rider_id', ctx()->rider['id'])->where('status', 'sent')->update(['status' => 'declined']);
            return ['ok' => true];
        }, 'rider');

        Api::patch('/deliveries/:id/status', function (Req $r) {
            $c = ctx();
            $d = Db::find('deliveries', $r->p('id'), 'Delivery');
            if (!$c->rider && !$c->can('delivery.assign')) throw HttpError::unauthenticated('Please log in');
            if ($c->rider && $d['rider_id'] !== $c->rider['id']) throw HttpError::forbidden('Not your delivery');
            $o = Db::find('orders', $d['order_id']);
            switch ($r->b('status')) {
                case 'picked_up':
                    if (!in_array($o['status'], ['ready', 'out_for_delivery'], true) && !$r->b('force')) throw HttpError::invalid(['status' => "The kitchen hasn't marked this ready yet"]);
                    Db::update('deliveries', $d['id'], ['status' => 'picked_up', 'picked_at' => $c->iso()]);
                    Db::update('orders', $o['id'], ['status' => 'out_for_delivery']);
                    Orders::log($o['id'], 'out_for_delivery');
                    break;
                case 'on_the_way':
                    Db::update('deliveries', $d['id'], ['status' => 'on_the_way']);
                    break;
                case 'delivered':
                    $proof = $r->b('proof');
                    if ($proof === 'otp' && (string) $r->b('otp') !== $o['delivery_otp']) throw HttpError::invalid(['otp' => "OTP doesn't match. Ask the customer again."]);
                    if ($proof === 'photo' && !$r->b('photo')) throw HttpError::invalid(['photo' => 'Take a photo of the handover']);
                    if (!in_array($proof, ['otp', 'photo'], true)) throw HttpError::invalid(['proof' => 'Deliver with OTP or a photo']);
                    if ($proof === 'photo') Media::storeDataUrl($r->b('photo'), 'delivery-proof');
                    $collect = $d['to_collect'];
                    if ($collect > 0 && !in_array($r->b('collected_mode'), ['cash', 'upi'], true)) throw HttpError::invalid(['collected_mode' => 'Collect ' . rupees($collect) . ' by cash or UPI']);
                    Db::update('deliveries', $d['id'], ['status' => 'delivered', 'delivered_at' => $c->iso(), 'proof' => $proof, 'collected' => $collect, 'collected_mode' => $collect ? $r->b('collected_mode') : null]);
                    if ($o['payment_status'] !== 'paid') {
                        Db::create('payments', ['order_id' => $o['id'], 'mode' => $r->b('collected_mode') === 'upi' ? 'upi_on_delivery' : 'cod', 'amount' => $collect, 'status' => 'success'], 'py');
                        $o = Db::update('orders', $o['id'], ['payment_status' => 'paid']);
                    }
                    Billing::createInvoice($o);
                    Db::update('orders', $o['id'], ['status' => 'completed', 'completed_at' => $c->iso()]);
                    Orders::log($o['id'], 'delivered');
                    Orders::log($o['id'], 'completed');
                    break;
                case 'failed':
                    need($r->body, ['reason']);
                    Db::update('deliveries', $d['id'], ['status' => 'failed']);
                    Audit::notify('manager', 'Delivery failed', "{$o['number']}: {$r->b('reason')}", '/admin/delivery');
                    break;
                default:
                    throw HttpError::invalid(['status' => 'Unknown status']);
            }
            return self::row(Db::find('deliveries', $d['id']));
        }, 'public');

        Api::get('/rider/earnings', function () {
            $me = ctx()->rider;
            $t = ctx()->today();
            $from = Orders::dayStart($t);
            $mine = Db::rows('deliveries', fn ($q) => $q->where('rider_id', $me['id'])->where('status', 'delivered'));
            $today = array_filter($mine, fn ($d) => $d['delivered_at'] >= $from);
            $settled = Db::q('rider_settlements')->where('rider_id', $me['id'])->where('business_date', $t)->where('settled', 1)->exists();
            return ['type' => $me['type'], 'today_count' => count($today), 'today_charges' => sum_by($today, 'charge'),
                'cash_in_hand' => $settled ? 0 : sum_by(where_eq(array_values($today), 'collected_mode', 'cash'), 'collected'), 'upi_collected' => sum_by(where_eq(array_values($today), 'collected_mode', 'upi'), 'collected'),
                'month_charges' => sum_by(array_filter($mine, fn ($d) => str_starts_with((string) $d['delivered_at'], substr($t, 0, 7))), 'charge'),
                'note' => $me['type'] === 'own' ? 'Delivery charges are added to your salary on salary day.' : 'Delivery charges are paid to you at day close.', 'settled_today' => $settled];
        }, 'rider');

        /* Settlement at day close (M8.7) */
        Api::get('/rider-settlements', function (Req $r) {
            $date = $r->q('date', ctx()->today());
            return array_values(array_filter(array_map(fn ($rd) => self::settlement($rd, $date), Db::rows('riders')), fn ($s) => $s['deliveries'] > 0 || $s['settled']));
        }, 'staff', 'delivery.assign');
        Api::post('/rider-settlements', function (Req $r) {
            need($r->body, ['rider_id']);
            $date = $r->b('date', ctx()->today());
            $rd = Db::find('riders', $r->b('rider_id'), 'Rider');
            $s = self::settlement($rd, $date);
            if ($s['settled']) throw HttpError::invalid(['rider_id' => 'Already settled for today']);
            $row = Db::create('rider_settlements', ['rider_id' => $rd['id'], 'business_date' => $date, 'cash_collected' => $s['cash_collected'], 'charges_earned' => $s['charges_earned'], 'net' => $s['net'], 'settled' => true, 'settled_by' => ctx()->user['id']], 'rs');
            Audit::log('rider_settlement', 'rider', $rd['id'], null, $s);
            return $row;
        }, 'staff', 'delivery.assign');
    }
}
