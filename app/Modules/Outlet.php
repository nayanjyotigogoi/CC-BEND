<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Media;

/** Café settings (Core/Settings): one /settings call feeds the theme, features and rules for every surface. */
class Outlet
{
    public const SECTIONS = ['outlet' => 'outlets', 'brand' => 'brand_settings', 'tax' => 'tax_settings', 'flags' => 'business_type_flags', 'payment' => 'payment_settings', 'delivery' => 'delivery_settings', 'loyalty' => 'loyalty_settings'];

    public static function feature(string $f): bool
    {
        return (bool) (Db::settings('business_type_flags')['features'][$f] ?? false);
    }

    public static function routes(): void
    {
        Api::get('/settings', function () {
            $t = ctx()->tenant;
            $pay = Db::settings('payment_settings');
            unset($pay['gateway_secret']); // secrets never leave the server
            return [
                'tenant' => ['id' => $t['id'], 'slug' => $t['slug'], 'status' => $t['status'], 'version' => $t['version'], 'support_access_until' => $t['support_access_until']],
                'outlet' => Db::settings('outlets'), 'brand' => Db::settings('brand_settings'), 'tax' => Db::settings('tax_settings'),
                'flags' => Db::settings('business_type_flags'), 'payment' => $pay, 'delivery' => Db::settings('delivery_settings'),
                'loyalty' => Db::settings('loyalty_settings'),
                'powered_by' => ['text' => 'Powered by CafeChalao', 'url' => 'https://cafechalao.in', 'locked' => true],
            ];
        }, 'public');

        Api::put('/settings/:section', function (Req $r) {
            $table = self::SECTIONS[$r->p('section')] ?? throw HttpError::forbidden('Unknown settings section');
            $row = Db::settings($table);
            $patch = $r->body;
            if ($table === 'payment_settings') {
                if (!empty($patch['gateway_secret'])) $patch['gateway_configured'] = true; // stored server-side only
                else unset($patch['gateway_secret']);
                if (!empty($patch['upi_qr_url']) && str_starts_with($patch['upi_qr_url'], 'data:')) $patch['upi_qr_url'] = Media::storeDataUrl($patch['upi_qr_url'], 'upi-qr');
            }
            if ($table === 'brand_settings' && !empty($patch['logo_url']) && str_starts_with($patch['logo_url'], 'data:')) $patch['logo_url'] = Media::storeDataUrl($patch['logo_url'], 'logo');
            if ($table === 'outlets' && !empty($patch['apply_defaults']) && ($patch['business_type'] ?? null) !== $row['business_type']) {
                $flags = Db::settings('business_type_flags');
                Db::update('business_type_flags', $flags['id'], ['features' => config('cafechalao.business_types')[$patch['business_type']]]);
            }
            unset($patch['apply_defaults']);
            $next = Db::update($table, $row['id'], $patch);
            unset($patch['gateway_secret']);
            Audit::log('setting_change', $table, $row['id'], array_intersect_key($row, $patch), $patch);
            unset($next['gateway_secret']);
            return $next;
        }, 'staff', 'settings.manage');

        Api::post('/setup/step', function (Req $r) {
            need($r->body, ['step']);
            $o = Db::settings('outlets');
            return Db::update('outlets', $o['id'], ['setup_step' => max($o['setup_step'], (int) $r->b('step'))]);
        }, 'staff', 'settings.manage');

        /** Owner grants CafeChalao support time-limited access (SRS 11). */
        Api::post('/support-access', function (Req $r) {
            $h = (int) $r->b('hours', 0);
            $until = $h > 0 ? ctx()->iso(ctx()->now()->modify("+$h hours")) : null;
            Db::update('tenants', ctx()->tenantId(), ['support_access_until' => $until]);
            Audit::log('support_access', 'tenant', ctx()->tenantId(), null, ['until' => $until]);
            return ['support_access_until' => $until];
        }, 'staff', 'settings.manage');

        Api::get('/roles', fn () => ['roles' => Db::rows('roles'), 'all_permissions' => array_keys(config('cafechalao.permissions')), 'limits' => Db::rows('approval_limits')], 'staff', 'settings.manage');
        Api::put('/roles/:id', function (Req $r) {
            $role = Db::find('roles', $r->p('id'));
            if ($role['key'] === 'owner') throw HttpError::forbidden('The owner always has every permission');
            $perms = array_values(array_intersect((array) $r->b('permissions', []), array_keys(config('cafechalao.permissions'))));
            Audit::log('setting_change', 'role', $role['id'], $role['permissions'], $perms);
            return Db::update('roles', $role['id'], ['permissions' => $perms]);
        }, 'staff', 'settings.manage');
        Api::put('/approval-limits/:id', function (Req $r) {
            $l = Db::find('approval_limits', $r->p('id'));
            $patch = array_intersect_key($r->body, array_flip(['discount_pct', 'void_value', 'refund_value', 'expense_value', 'po_value']));
            Audit::log('setting_change', 'approval_limit', $l['id'], $l, $patch);
            return Db::update('approval_limits', $l['id'], $patch);
        }, 'staff', 'settings.manage');

        Api::crud('/printers', 'printers', 'pr', ['perm' => 'settings.manage', 'required' => ['name', 'station']]);
        Api::crud('/devices', 'devices', 'dv', ['perm' => 'settings.manage', 'required' => ['name', 'kind']]);

        Api::get('/notifications', function () {
            $u = ctx()->user;
            $rows = Db::rows('notifications', fn ($q) => $q->where(fn ($w) => $w->where('user_id', $u['id'])->orWhere(fn ($x) => $x->whereNull('user_id')->whereIn('audience', $u['role'] === 'manager' ? ['manager', 'owner'] : [$u['role']]))), 'created_at', 'desc');
            return array_slice($rows, 0, 40);
        });
        Api::post('/notifications/:id/read', fn (Req $r) => Db::update('notifications', Db::find('notifications', $r->p('id'))['id'], ['read' => true]));

        Api::get('/audit-logs', function (Req $r) {
            return Db::rows('audit_logs', function ($q) use ($r) {
                if ($a = $r->q('action')) $q->where('action', $a);
                if ($f = $r->q('from')) $q->where('created_at', '>=', $f);
                if ($t = $r->q('to')) $q->where('created_at', '<=', $t . 'T23:59:59.999Z');
                $q->limit(500);
            }, 'created_at', 'desc');
        }, 'staff', 'audit.view');
    }
}
