<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;

/** Dine-in (M2): floor, tables, QR, merge/split/move, call waiter, reservations, waitlist. */
class DineIn
{
    private static function message(string $phone, string $template, string $body, ?string $customerId = null): ?string
    {
        Db::create('message_logs', ['customer_id' => $customerId, 'channel' => 'whatsapp_link', 'template' => $template, 'to' => $phone, 'body' => $body, 'status' => 'prepared'], 'ml');
        return wa_link($phone, $body);
    }

    private static function openSession(array $t, int $guests, ?string $customerId = null): array
    {
        $s = Db::create('table_sessions', ['table_id' => $t['id'], 'guests' => $guests, 'customer_id' => $customerId, 'opened_at' => ctx()->iso()], 'ts');
        Db::update('dining_tables', $t['id'], ['status' => 'occupied', 'current_session_id' => $s['id']]);
        return $s;
    }

    private static function reserve(Req $r, bool $byStaff): array
    {
        need($r->body, ['name', 'phone', 'date', 'time', 'party_size']);
        if ($r->b('date') < ctx()->today()) throw HttpError::invalid(['date' => 'Pick today or a later date']);
        $res = Db::create('reservations', ['customer_id' => ctx()->customer['id'] ?? $r->b('customer_id'), 'name' => $r->b('name'), 'phone' => $r->b('phone'), 'date' => $r->b('date'), 'time' => $r->b('time'),
            'party_size' => (int) $r->b('party_size'), 'table_preference' => (string) $r->b('table_preference', ''), 'table_id' => $r->b('table_id'), 'notes' => (string) $r->b('notes', ''), 'status' => $byStaff ? 'confirmed' : 'booked'], 'rs');
        $name = Db::settings('outlets')['name'];
        $link = self::message($res['phone'], 'reservation', "Hi {$res['name']}, your table for {$res['party_size']} at $name on {$res['date']} at {$res['time']} is " . ($byStaff ? 'confirmed' : 'requested') . '. See you soon!', $res['customer_id']);
        if (!$byStaff) Audit::notify('receptionist', 'New reservation request', "{$res['name']} · {$res['party_size']} pax · {$res['date']} {$res['time']}", '/admin/reservations');
        return ['reservation' => $res, 'whatsapp_url' => $link];
    }

    public static function routes(): void
    {
        Api::crud('/areas', 'areas', 'ar', ['perm' => 'settings.manage', 'readPerm' => 'tables.manage', 'required' => ['name'], 'defaults' => ['sort' => 99], 'sort' => 'sort']);
        Api::crud('/tables', 'dining_tables', 'tb', ['perm' => 'settings.manage', 'readPerm' => 'tables.manage', 'required' => ['name', 'area_id'], 'defaults' => ['seats' => 4, 'status' => 'free']]);

        Api::get('/floor', function () {
            $sessions = array_column(Db::rows('table_sessions', fn ($q) => $q->whereNull('closed_at')), null, 'id');
            $orders = Db::rows('orders', fn ($q) => $q->whereNotNull('table_id')->whereNotIn('status', ['completed', 'cancelled']));
            $items = Db::rows('order_items', fn ($q) => $q->whereIn('order_id', array_column($orders, 'id') ?: ['-'])->where('status', '!=', 'void'));
            $users = array_column(Db::rows('users'), 'name', 'id');
            $res = Db::rows('reservations', fn ($q) => $q->where('date', ctx()->today())->whereIn('status', ['booked', 'confirmed']));
            return [
                'areas' => Db::rows('areas', null, 'sort'),
                'tables' => array_map(function ($t) use ($sessions, $orders, $items, $users, $res) {
                    $s = $sessions[$t['current_session_id']] ?? null;
                    $mine = array_filter($orders, fn ($x) => $x['table_id'] === $t['id']);
                    $o = first_where($mine, fn ($x) => $x['payment_status'] === 'unpaid') ?? first_where($mine, fn ($x) => $x['payment_status'] !== 'paid') ?? first_where($mine, fn () => true);
                    return $t + [
                        'session' => $s ? $s + ['captain_name' => $users[$s['captain_id']] ?? null] : null,
                        'order' => $o ? ['id' => $o['id'], 'number' => $o['number'], 'total' => $o['total'], 'status' => $o['status'], 'bill_requested' => $o['bill_requested'], 'updated_at' => $o['updated_at'],
                            'items' => array_map(fn ($i) => ['id' => $i['id'], 'name' => $i['name'], 'qty' => $i['qty'], 'status' => $i['status']], where_eq($items, 'order_id', $o['id']))] : null,
                        'bill' => $s ? Orders::tableBills($s['id']) : null,
                        'reservation' => first_where($res, fn ($x) => $x['table_id'] === $t['id']),
                    ];
                }, Db::rows('dining_tables', null, 'created_at')),
            ];
        });

        Api::post('/tables/bulk', function (Req $r) {
            need($r->body, ['area_id', 'count']);
            $n = Db::q('dining_tables')->count();
            $out = [];
            for ($i = 0; $i < min(100, (int) $r->b('count')); $i++) {
                $n++;
                $out[] = Db::create('dining_tables', ['area_id' => $r->b('area_id'), 'name' => $r->b('prefix', 'T') . $n, 'seats' => (int) $r->b('seats', 4), 'status' => 'free', 'qr_code' => substr(ctx()->tenant['slug'], 0, 2) . $n . random_int(100, 999)], 'tb');
            }
            return $out;
        }, 'staff', 'settings.manage');

        Api::patch('/tables/:id/status', function (Req $r) {
            $t = Db::find('dining_tables', $r->p('id'), 'Table');
            $to = $r->b('status');
            if ($to === 'free' && $t['current_session_id']) {
                if (Db::q('orders')->where('table_session_id', $t['current_session_id'])->whereNotIn('status', ['completed', 'cancelled'])->exists()) throw HttpError::invalid(['status' => 'This table still has an open bill']);
                Db::update('table_sessions', $t['current_session_id'], ['closed_at' => ctx()->iso()]);
            }
            return Db::update('dining_tables', $t['id'], ['status' => $to] + ($to === 'free' ? ['current_session_id' => null, 'merged_into' => null] : []));
        }, 'staff', 'tables.manage');

        Api::post('/tables/:id/customer', function (Req $r) {
            $t = Db::find('dining_tables', $r->p('id'), 'Table');
            $c = Db::find('customers', $r->b('customer_id'), 'Customer');
            if ($t['current_session_id']) Db::update('table_sessions', $t['current_session_id'], ['customer_id' => $c['id']]);
            foreach (Db::rows('orders', fn ($q) => $q->where('table_id', $t['id'])->whereNotIn('status', ['completed', 'cancelled'])) as $o) {
                Orders::recalc(Db::update('orders', $o['id'], ['customer_id' => $c['id'], 'customer_name' => $c['name'], 'customer_phone' => $c['phone']]));
            }
            return ['ok' => true];
        }, 'staff', 'orders.take');

        Api::post('/tables/move', function (Req $r) {
            need($r->body, ['from_table_id', 'to_table_id']);
            $from = Db::find('dining_tables', $r->b('from_table_id'), 'Table');
            $to = Db::find('dining_tables', $r->b('to_table_id'), 'Table');
            if (in_array($to['status'], ['occupied', 'bill_requested'], true)) throw HttpError::invalid(['to_table_id' => "{$to['name']} is in use. Merge instead."]);
            if (!$from['current_session_id']) throw HttpError::invalid(['from_table_id' => "{$from['name']} has no running order"]);
            Db::update('table_sessions', $from['current_session_id'], ['table_id' => $to['id']]);
            Db::q('orders')->where('table_id', $from['id'])->whereNotIn('status', ['completed', 'cancelled'])->update(['table_id' => $to['id']]);
            Db::q('kots')->where('table_name', $from['name'])->where('status', '!=', 'served')->update(['table_name' => $to['name']]);
            Db::update('dining_tables', $to['id'], ['status' => $from['status'], 'current_session_id' => $from['current_session_id']]);
            Db::update('dining_tables', $from['id'], ['status' => 'cleaning', 'current_session_id' => null]);
            Audit::log('table_move', 'table', $from['id'], ['table' => $from['name']], ['table' => $to['name']]);
            return ['ok' => true];
        }, 'staff', 'tables.manage');

        Api::post('/tables/merge', function (Req $r) {
            need($r->body, ['into_id', 'table_ids']);
            $into = Db::find('dining_tables', $r->b('into_id'), 'Table');
            foreach ($r->b('table_ids') as $id) {
                if ($id === $into['id']) continue;
                $t = Db::find('dining_tables', $id, 'Table');
                if ($t['current_session_id'] && $t['current_session_id'] !== $into['current_session_id']) {
                    Db::q('orders')->where('table_id', $t['id'])->whereNotIn('status', ['completed', 'cancelled'])->update(['table_id' => $into['id'], 'table_session_id' => $into['current_session_id']]);
                    Db::update('table_sessions', $t['current_session_id'], ['closed_at' => ctx()->iso()]);
                }
                Db::update('dining_tables', $t['id'], ['merged_into' => $into['id'], 'status' => 'occupied', 'current_session_id' => $into['current_session_id']]);
            }
            return ['ok' => true];
        }, 'staff', 'tables.manage');

        Api::post('/tables/:id/split', function (Req $r) {
            Db::q('dining_tables')->where('merged_into', $r->p('id'))->update(['merged_into' => null, 'status' => 'free', 'current_session_id' => null]);
            return ['ok' => true];
        }, 'staff', 'tables.manage');

        /* QR (public, tied to one table) */
        Api::get('/qr/:code', function (Req $r) {
            $t = Db::first('dining_tables', fn ($q) => $q->where('qr_code', $r->p('code'))) ?? throw HttpError::invalid(['code' => 'This QR code is not valid for this café']);
            $o = $t['current_session_id'] ? (Orders::runningTableOrder($t['current_session_id']) ?? Db::first('orders', fn ($q) => $q->where('table_session_id', $t['current_session_id'])->whereNotIn('status', ['completed', 'cancelled']))) : null;
            return ['table' => ['id' => $t['id'], 'name' => $t['name'], 'seats' => $t['seats'], 'status' => $t['status'], 'area' => Db::first('areas', fn ($q) => $q->where('id', $t['area_id']))['name'] ?? ''],
                'open_order_id' => $o['id'] ?? null, 'flow' => Db::settings('business_type_flags')['qr_order_flow']];
        }, 'public');

        Api::post('/qr/:code/call-waiter', function (Req $r) {
            $t = Db::first('dining_tables', fn ($q) => $q->where('qr_code', $r->p('code'))) ?? throw HttpError::invalid(['code' => 'Invalid QR']);
            $s = $t['current_session_id'] ? Db::update('table_sessions', $t['current_session_id'], ['waiter_called_at' => ctx()->iso()]) : null;
            Audit::notify('captain', 'Waiter called', "{$t['name']} is calling a waiter", '/captain', $s['captain_id'] ?? null);
            return ['ok' => true];
        }, 'public');

        Api::post('/tables/:id/ack-call', function (Req $r) {
            $t = Db::find('dining_tables', $r->p('id'), 'Table');
            if ($t['current_session_id']) Db::update('table_sessions', $t['current_session_id'], ['waiter_called_at' => null]);
            return ['ok' => true];
        }, 'staff', 'orders.take');

        /* Reservations (M2.6) */
        Api::get('/reservations', fn (Req $r) => Db::rows('reservations', function ($q) use ($r) {
            if ($d = $r->q('date')) $q->where('date', $d);
            if ($f = $r->q('from')) $q->where('date', '>=', $f);
            $q->orderBy('date')->orderBy('time');
        }), 'staff', 'reservations.manage');
        Api::post('/reservations', fn (Req $r) => self::reserve($r, true), 'staff', 'reservations.manage');
        Api::post('/public/reservations', function (Req $r) {
            if (!Outlet::feature('reservations')) throw HttpError::invalid(['date' => "This café doesn't take reservations"]);
            return self::reserve($r, false);
        }, 'customer_optional');

        Api::patch('/reservations/:id', function (Req $r) {
            $res = Db::find('reservations', $r->p('id'), 'Reservation');
            $next = Db::update('reservations', $res['id'], array_intersect_key($r->body, array_flip(['status', 'table_id', 'date', 'time', 'party_size', 'notes', 'table_preference'])));
            if ($r->b('status') === 'seated' && $next['table_id']) self::openSession(Db::find('dining_tables', $next['table_id']), $next['party_size'], $next['customer_id']);
            if ($r->b('table_id') && in_array($next['status'], ['booked', 'confirmed'], true) && $next['date'] === ctx()->today()) Db::update('dining_tables', $r->b('table_id'), ['status' => 'reserved']);
            $link = null;
            if ($r->b('status') === 'confirmed') $link = self::message($next['phone'], 'reservation', "Hi {$next['name']}, your table for {$next['party_size']} at " . Db::settings('outlets')['name'] . " on {$next['date']} at {$next['time']} is confirmed.", $next['customer_id']);
            return ['reservation' => $next, 'whatsapp_url' => $link];
        }, 'staff', 'reservations.manage');

        /* Waitlist (M2.7) */
        Api::get('/waitlist', fn () => Db::rows('waitlist_entries', fn ($q) => $q->whereIn('status', ['waiting', 'notified']), 'created_at'), 'staff', 'reservations.manage');
        Api::post('/waitlist', function (Req $r) {
            need($r->body, ['name', 'phone', 'party_size']);
            $ahead = Db::q('waitlist_entries')->where('status', 'waiting')->count();
            return Db::create('waitlist_entries', ['name' => $r->b('name'), 'phone' => $r->b('phone'), 'party_size' => (int) $r->b('party_size'), 'quoted_wait_min' => (int) ($r->b('quoted_wait_min') ?: ($ahead + 1) * 8), 'status' => 'waiting'], 'wl');
        }, 'staff', 'reservations.manage');
        Api::post('/waitlist/:id/notify', function (Req $r) {
            $w = Db::update('waitlist_entries', Db::find('waitlist_entries', $r->p('id'), 'Entry')['id'], ['status' => 'notified']);
            return ['whatsapp_url' => self::message($w['phone'], 'table_ready', "Hi {$w['name']}, your table at " . Db::settings('outlets')['name'] . ' is ready! Please come to the host stand.')];
        }, 'staff', 'reservations.manage');
        Api::post('/waitlist/:id/seat', function (Req $r) {
            $w = Db::find('waitlist_entries', $r->p('id'), 'Entry');
            $t = Db::find('dining_tables', $r->b('table_id'), 'Table');
            if (!in_array($t['status'], ['free', 'reserved', 'cleaning'], true)) throw HttpError::invalid(['table_id' => "{$t['name']} is in use"]);
            self::openSession($t, $w['party_size']);
            return Db::update('waitlist_entries', $w['id'], ['status' => 'seated', 'table_id' => $t['id']]);
        }, 'staff', 'reservations.manage');
        Api::post('/waitlist/:id/left', fn (Req $r) => Db::update('waitlist_entries', Db::find('waitlist_entries', $r->p('id'))['id'], ['status' => 'left']), 'staff', 'reservations.manage');
    }
}
