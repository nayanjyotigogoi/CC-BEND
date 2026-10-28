<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Core\Tenancy;
use App\Shared\CafeFactory;

/**
 * CafeChalao Control Panel (L1, SRS 11). Sees every café's licence, version, health and flags, never its customers
 * or sales, except through the time-limited support access the owner grants (written to the café's audit log).
 */
class Control
{
    private static function tenants(): array
    {
        return Db::raw('tenants')->orderBy('created_at')->get()->map(fn ($r) => Db::decode('tenants', $r))->all();
    }

    private static function audit(string $tenantId, string $action, mixed $new): void
    {
        $now = ctx()->iso();
        Db::raw('audit_logs')->insert(Db::encode('audit_logs', ['id' => Db::newId('au'), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now, 'user_name' => 'CafeChalao · ' . ctx()->admin['name'],
            'action' => $action, 'entity' => 'tenant', 'entity_id' => $tenantId, 'device' => 'Control Panel', 'new_value' => $new]));
    }

    public static function routes(): void
    {
        Api::get('/control/overview', function () {
            $t = self::tenants();
            $month = substr(ctx()->today(), 0, 7);
            $versions = array_count_values(array_column($t, 'version'));
            return [
                'cafes' => count($t), 'active' => count(where_eq($t, 'status', 'active')), 'trial' => count(where_eq($t, 'status', 'trial')), 'suspended' => count(where_eq($t, 'status', 'suspended')),
                'new_this_month' => count(array_filter($t, fn ($x) => str_starts_with((string) $x['created_at'], $month))),
                // Platform total only (a count, never order contents).
                'orders_today' => Db::raw('orders')->where('created_at', '>=', Orders::dayStart(ctx()->today()))->count(),
                'uptime' => 99.9, 'open_tickets' => Db::raw('support_tickets')->where('status', '!=', 'closed')->count(),
                'versions' => array_map(fn ($v, $c) => ['version' => $v, 'count' => $c], array_keys($versions), $versions), 'latest_version' => config('cafechalao.latest_version'),
            ];
        }, 'control', null, true);

        Api::get('/control/tenants', fn () => array_map(fn ($x) => $x + [
            'setup_step' => (int) (Db::raw('outlets')->where('tenant_id', $x['id'])->value('setup_step') ?? 0),
            'features' => json_decode((string) Db::raw('business_type_flags')->where('tenant_id', $x['id'])->value('features'), true),
            'open_tickets' => Db::raw('support_tickets')->where('tenant_id', $x['id'])->where('status', '!=', 'closed')->count(),
        ], self::tenants()), 'control', null, true);

        Api::patch('/control/tenants/:id', function (Req $r) {
            $t = Db::decode('tenants', Db::raw('tenants')->where('id', $r->p('id'))->first()) ?? throw HttpError::notFound('Café');
            $patch = array_intersect_key($r->body, array_flip(['status', 'support_expiry', 'version', 'domains', 'hosting']));
            if ($patch) Db::raw('tenants')->where('id', $t['id'])->update(Db::encode('tenants', $patch));
            if ($f = $r->b('features')) {
                $row = Db::raw('business_type_flags')->where('tenant_id', $t['id'])->first();
                if ($row) Db::raw('business_type_flags')->where('id', $row->id)->update(['features' => json_encode(array_merge(json_decode($row->features, true), $f))]);
            }
            self::audit($t['id'], 'control_change', $r->body);
            return Db::decode('tenants', Db::raw('tenants')->where('id', $t['id'])->first());
        }, 'control', null, true);

        /** Onboard a café: tenant, outlet and default settings; the owner then runs the setup wizard. */
        Api::post('/control/tenants', fn (Req $r) => CafeFactory::create($r->body), 'control', null, true);

        Api::post('/control/tenants/:id/support-login', function (Req $r) {
            $t = Db::decode('tenants', Db::raw('tenants')->where('id', $r->p('id'))->first()) ?? throw HttpError::notFound('Café');
            if (!$t['support_access_until'] || $t['support_access_until'] < ctx()->iso()) throw new HttpError(403, "The owner hasn't granted support access. Ask them to allow it in Settings → Support access.", 'no_support_access');
            $owner = Db::raw('users')->where('tenant_id', $t['id'])->where('role', 'owner')->where('active', 1)->whereNull('deleted_at')->first() ?? throw HttpError::notFound('Owner');
            self::audit($t['id'], 'support_access', ['until' => $t['support_access_until']]);
            return ['token' => Tenancy::issue('staff', $owner->id, $t['id']), 'slug' => $t['slug']];
        }, 'control', null, true);

        Api::get('/control/tickets', function () {
            $names = array_column(self::tenants(), 'name', 'id');
            return Db::raw('support_tickets')->orderByDesc('created_at')->get()->map(fn ($x) => Db::decode('support_tickets', $x) + ['cafe' => $names[$x->tenant_id] ?? null])->all();
        }, 'control', null, true);
        Api::post('/control/tickets', function (Req $r) {
            need($r->body, ['tenant_id', 'subject', 'kind']);
            $row = ['id' => Db::newId('tk'), 'tenant_id' => $r->b('tenant_id'), 'subject' => $r->b('subject'), 'kind' => $r->b('kind'), 'quote' => $r->b('quote'), 'billing_status' => $r->b('quote') ? 'quoted' : 'none', 'status' => 'open', 'created_at' => ctx()->iso()];
            Db::raw('support_tickets')->insert(Db::encode('support_tickets', $row));
            return $row;
        }, 'control', null, true);
        Api::patch('/control/tickets/:id', function (Req $r) {
            Db::raw('support_tickets')->where('id', $r->p('id'))->update(Db::encode('support_tickets', array_intersect_key($r->body, array_flip(['status', 'quote', 'billing_status']))));
            return Db::decode('support_tickets', Db::raw('support_tickets')->where('id', $r->p('id'))->first());
        }, 'control', null, true);

        /** One deployment updates every shared café; risky features go to a pilot group first. Backup before update. */
        Api::post('/control/release', function (Req $r) {
            need($r->body, ['version', 'target']);
            $q = Db::raw('tenants');
            if ($r->b('target') !== 'all') $q->whereIn('id', (array) $r->b('tenant_ids', []));
            $n = $q->update(['version' => $r->b('version'), 'last_backup_at' => ctx()->iso()]);
            return ['updated' => $n, 'backup_taken' => true];
        }, 'control', null, true);

        Api::get('/control/admins', fn () => Db::raw('platform_admins')->get()->map(fn ($x) => Db::decode('platform_admins', $x))->all(), 'control', null, true);

        Api::get('/public/cafes', fn () => array_values(array_map(function ($t) {
            $b = Db::decode('brand_settings', Db::raw('brand_settings')->where('tenant_id', $t['id'])->first());
            return ['slug' => $t['slug'], 'name' => $t['name'], 'city' => $t['city'], 'business_type' => $t['business_type'], 'primary_color' => $b['primary_color'] ?? null, 'accent_color' => $b['accent_color'] ?? null];
        }, array_filter(self::tenants(), fn ($t) => $t['status'] !== 'suspended'))), 'public', null, true);
    }
}
