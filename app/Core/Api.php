<?php

namespace App\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * One router for every module (same contract as the frontend mock in mock/core.ts of the frontend repo):
 *   Api::get('/menu', fn (Req $r) => ..., auth: 'public');
 * The dispatcher resolves the café, signs in the caller, checks auth and permission, applies the
 * suspended-licence rule and Idempotency-Key, and wraps the result as { data }. Errors become
 * { message, code, errors }. Nothing of this is repeated inside the modules.
 */
class Api
{
    /** @var array<int,array{method:string,path:string,handler:callable,auth:string,perm:?string,tenantless:bool}> */
    private static array $routes = [];

    public static function get(string $path, callable $h, string $auth = 'staff', ?string $perm = null, bool $tenantless = false): void { self::add('GET', $path, $h, $auth, $perm, $tenantless); }
    public static function post(string $path, callable $h, string $auth = 'staff', ?string $perm = null, bool $tenantless = false): void { self::add('POST', $path, $h, $auth, $perm, $tenantless); }
    public static function put(string $path, callable $h, string $auth = 'staff', ?string $perm = null, bool $tenantless = false): void { self::add('PUT', $path, $h, $auth, $perm, $tenantless); }
    public static function patch(string $path, callable $h, string $auth = 'staff', ?string $perm = null, bool $tenantless = false): void { self::add('PATCH', $path, $h, $auth, $perm, $tenantless); }
    public static function delete(string $path, callable $h, string $auth = 'staff', ?string $perm = null, bool $tenantless = false): void { self::add('DELETE', $path, $h, $auth, $perm, $tenantless); }

    private static function add(string $method, string $path, callable $h, string $auth, ?string $perm, bool $tenantless): void
    {
        self::$routes[] = compact('method', 'path', 'auth', 'perm', 'tenantless') + ['handler' => $h];
    }

    /**
     * Simple CRUD for settings-style tables (suppliers, coupons, areas ...): list, show, create, update, soft delete.
     * @param array{perm:string, readPerm?:string, readAuth?:string, required?:array, defaults?:array, audit?:string, sort?:string} $o
     */
    public static function crud(string $path, string $table, string $prefix, array $o): void
    {
        $read = $o['readPerm'] ?? $o['perm'];
        $readAuth = $o['readAuth'] ?? 'staff';
        self::get($path, function (Req $r) use ($table, $o) {
            $rows = Db::rows($table, null, $o['sort'] ?? null);
            if ($q = strtolower($r->q('q', ''))) $rows = array_values(array_filter($rows, fn ($x) => str_contains(strtolower(json_encode($x)), $q)));
            return $rows;
        }, $readAuth, $readAuth === 'public' ? null : $read);
        self::get("$path/:id", fn (Req $r) => Db::find($table, $r->p('id')), 'staff', $read);
        self::post($path, function (Req $r) use ($table, $prefix, $o) {
            need($r->body, $o['required'] ?? []);
            $row = Db::create($table, array_merge($o['defaults'] ?? [], $r->body), $prefix);
            if (!empty($o['audit'])) Audit::log('create', $o['audit'], $row['id'], null, $r->body);
            return $row;
        }, 'staff', $o['perm']);
        self::put("$path/:id", function (Req $r) use ($table, $o) {
            $old = Db::find($table, $r->p('id'));
            $row = Db::update($table, $old['id'], $r->body);
            if (!empty($o['audit'])) Audit::log('update', $o['audit'], $old['id'], $old, $r->body);
            return $row;
        }, 'staff', $o['perm']);
        self::delete("$path/:id", function (Req $r) use ($table, $o) {
            $old = Db::find($table, $r->p('id'));
            Db::remove($table, $old['id']);
            Audit::log('delete', $o['audit'] ?? $table, $old['id'], $old, null);
            return ['deleted' => true];
        }, 'staff', $o['perm']);
    }

    /** Registers every collected route with Laravel under /api/v1. */
    public static function register(): void
    {
        foreach (self::$routes as $def) {
            $lpath = preg_replace('/:(\w+)/', '{$1}', ltrim($def['path'], '/'));
            Route::match([$def['method']], $lpath, fn (Request $request) => self::dispatch($def, $request));
        }
    }

    private static function dispatch(array $def, Request $request): JsonResponse
    {
        app()->forgetScopedInstances(); // fresh context per request, even in long-running workers and tests
        $ctx = ctx();
        try {
            $ctx->tenant = Tenancy::resolve($request, $def['tenantless']);
            Tenancy::authenticate($request, $ctx);

            $need = $def['auth'];
            if ($need === 'staff' && !$ctx->user) throw HttpError::unauthenticated('Please log in with your PIN');
            if ($need === 'customer' && !$ctx->customer) throw HttpError::unauthenticated('Please sign in to continue');
            if ($need === 'rider' && !$ctx->rider) throw HttpError::unauthenticated('Please log in with your phone');
            if ($need === 'control' && !$ctx->admin) throw HttpError::unauthenticated('Control Panel login required');
            if ($need === 'staff_or_customer' && !$ctx->user && !$ctx->customer) throw HttpError::unauthenticated();
            if ($def['perm'] && !$ctx->can($def['perm'])) throw HttpError::forbidden();

            if (($ctx->tenant['status'] ?? null) === 'suspended' && $def['method'] !== 'GET' && !$def['tenantless'] && $need !== 'control') {
                throw new HttpError(423, "This café's licence is suspended. The app is read-only.", 'licence_suspended');
            }

            $params = $request->route()?->parameters() ?? [];
            $req = new Req($request->json()->all() ?: $request->except(array_keys($request->query())), $request->query(), $params);
            $ctx->idem = $request->header('Idempotency-Key');
            $ctx->device = (string) ($request->header('X-Device') ?: 'Web');

            $run = fn () => Db::transaction(fn () => ($def['handler'])($req));
            if ($ctx->idem && $def['method'] !== 'GET') {
                return response()->json(['data' => Idempotency::once($ctx->idem, $run)]);
            }
            return response()->json(['data' => $run()]);
        } catch (HttpError $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => $e->errors], $e->status);
        }
    }
}
