<?php

namespace App\Core;

use Illuminate\Http\Request;

/**
 * Multi-tenancy (SRS 7): the café is identified by its domain; in development the frontend also sends X-Tenant.
 * Tokens are only valid for the café that issued them; Control Panel tokens are platform-wide.
 */
class Tenancy
{
    public static function resolve(Request $request, bool $tenantless): ?array
    {
        $key = $request->header('X-Tenant') ?: preg_replace('/\.localhost$/', '', $request->getHost());
        $rows = Db::raw('tenants')->get()->map(fn ($r) => Db::decode('tenants', $r))->all();
        $tenant = first_where($rows, fn ($t) => $t['slug'] === $key || in_array($request->getHost(), $t['domains'] ?? [], true) || in_array($key, $t['domains'] ?? [], true));
        if (!$tenant && $tenantless) return $rows[0] ?? null;
        return $tenant ?? throw new HttpError(404, 'This café was not found', 'tenant_not_found');
    }

    public static function authenticate(Request $request, Ctx $ctx): void
    {
        $token = $request->bearerToken();
        if (!$token) return;
        $tok = Db::decode('api_tokens', Db::raw('api_tokens')->where('token', hash('sha256', $token))->first());
        if (!$tok || ($tok['kind'] !== 'control' && $tok['tenant_id'] !== ($ctx->tenant['id'] ?? null))) return;
        switch ($tok['kind']) {
            case 'staff':
                $u = Db::first('users', fn ($q) => $q->where('id', $tok['subject_id'])->where('active', 1));
                if ($u) {
                    $ctx->user = $u;
                    $ctx->perms = Db::first('roles', fn ($q) => $q->where('key', $u['role']))['permissions'] ?? [];
                }
                break;
            case 'customer':
                $ctx->customer = Db::first('customers', fn ($q) => $q->where('id', $tok['subject_id']));
                break;
            case 'rider':
                $ctx->rider = Db::first('riders', fn ($q) => $q->where('id', $tok['subject_id']));
                break;
            case 'control':
                $ctx->admin = Db::decode('platform_admins', Db::raw('platform_admins')->where('id', $tok['subject_id'])->first());
                break;
        }
    }

    /** Issues a bearer token; only its hash is stored. */
    public static function issue(string $kind, string $subjectId, ?string $tenantId = null): string
    {
        $plain = $kind . '_' . bin2hex(random_bytes(24));
        Db::raw('api_tokens')->insert(['id' => Db::newId('tk'), 'token' => hash('sha256', $plain), 'tenant_id' => $kind === 'control' ? null : ($tenantId ?? ctx()->tenantId()), 'kind' => $kind, 'subject_id' => $subjectId, 'created_at' => ctx()->iso()]);
        return $plain;
    }
}
