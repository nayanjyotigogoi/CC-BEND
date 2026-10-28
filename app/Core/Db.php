<?php

namespace App\Core;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB as LDB;
use Illuminate\Support\Str;

/**
 * Data access for every module. Rows are plain arrays shaped exactly like the API JSON
 * (types cast from App\Core\Schema). Tenant scope and soft delete are applied here once,
 * so no module can read another café's data by accident (SRS 7: every query scoped to tenant_id).
 */
class Db
{
    /** Scoped query: this café only, not deleted. */
    public static function q(string $table): Builder
    {
        $q = LDB::table($table);
        if (Schema::isTenantTable($table)) $q->where('tenant_id', ctx()->tenantId())->whereNull('deleted_at');
        return $q;
    }

    /** Unscoped query (platform tables, Control Panel). */
    public static function raw(string $table): Builder
    {
        return LDB::table($table);
    }

    public static function decode(string $table, object|array|null $row): ?array
    {
        if ($row === null) return null;
        $row = (array) $row;
        foreach (Schema::columns($table) as $col => $type) {
            if (!array_key_exists($col, $row)) continue;
            $v = $row[$col];
            if ($v === null) continue;
            $row[$col] = match ($type) {
                'i' => (int) $v,
                'n' => (float) $v,
                'b' => (bool) $v,
                'j' => is_string($v) ? json_decode($v, true) : $v,
                default => $v,
            };
        }
        return $row;
    }

    public static function encode(string $table, array $data): array
    {
        $cols = Schema::columns($table);
        $out = [];
        foreach ($data as $k => $v) {
            if (!isset($cols[$k])) continue; // unknown keys are ignored, like a Laravel $fillable list
            $out[$k] = match ($cols[$k]) {
                'j' => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE),
                'b' => $v === null ? null : ($v ? 1 : 0),
                'i' => $v === null || $v === '' ? null : (int) round((float) $v),
                'n' => $v === null || $v === '' ? null : (float) $v,
                default => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v),
            };
        }
        return $out;
    }

    /** @return array<int,array> */
    public static function rows(string $table, ?callable $where = null, ?string $orderBy = null, string $dir = 'asc'): array
    {
        $q = self::q($table);
        if ($where) $where($q);
        if ($orderBy) $q->orderBy($orderBy, $dir);
        return array_map(fn ($r) => self::decode($table, $r), $q->get()->all());
    }

    public static function first(string $table, ?callable $where = null): ?array
    {
        $q = self::q($table);
        if ($where) $where($q);
        return self::decode($table, $q->first());
    }

    public static function find(string $table, ?string $id, string $what = 'Record'): array
    {
        $row = $id ? self::decode($table, self::q($table)->where('id', $id)->first()) : null;
        return $row ?? throw HttpError::notFound($what);
    }

    public static function newId(string $prefix): string
    {
        return $prefix . '_' . strtolower((string) Str::ulid());
    }

    /** Insert with base columns filled; returns the decoded row. */
    public static function create(string $table, array $data, ?string $prefix = null): array
    {
        $ctx = ctx();
        $base = ['id' => $data['id'] ?? self::newId($prefix ?? substr($table, 0, 2))];
        if (Schema::isTenantTable($table)) {
            $now = $ctx->iso();
            $base += ['tenant_id' => $ctx->tenantId(), 'created_at' => $now, 'updated_at' => $now, 'created_by' => $ctx->actorId(), 'deleted_at' => null];
        }
        $row = array_merge(array_fill_keys(array_keys(Schema::columns($table)), null), $data, $base);
        LDB::table($table)->insert(self::encode($table, $row));
        return self::decode($table, LDB::table($table)->where('id', $row['id'])->first());
    }

    public static function update(string $table, string $id, array $patch): array
    {
        unset($patch['id'], $patch['tenant_id'], $patch['created_at'], $patch['created_by']);
        if (Schema::isTenantTable($table)) $patch['updated_at'] = ctx()->iso();
        $q = Schema::isTenantTable($table) ? self::q($table) : LDB::table($table);
        $q->where('id', $id)->update(self::encode($table, $patch));
        return self::decode($table, LDB::table($table)->where('id', $id)->first()) ?? throw HttpError::notFound();
    }

    /** Soft delete (invoices are never hard-deleted). */
    public static function remove(string $table, string $id): void
    {
        self::update($table, $id, ['deleted_at' => ctx()->iso()]);
    }

    /** The one settings row of a café (outlets, tax_settings, ...). */
    public static function settings(string $table): array
    {
        return self::first($table) ?? throw HttpError::notFound($table);
    }

    public static function transaction(callable $fn): mixed
    {
        return LDB::transaction($fn, 3); // deadlock retry 3 times (SRS 16.6)
    }
}
