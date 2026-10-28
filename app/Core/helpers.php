<?php

use App\Core\Ctx;
use App\Core\HttpError;

/** Global helpers shared by every module. */

function ctx(): Ctx
{
    return app(Ctx::class);
}

/** Required fields check, returns 422 with per-field messages (like a FormRequest). */
function need(array $body, array $fields): void
{
    $errors = [];
    foreach ($fields as $f) {
        $v = $body[$f] ?? null;
        if ($v === null || $v === '' || (is_array($v) && !count($v))) $errors[$f] = str_replace('_', ' ', $f) . ' is required';
    }
    if ($errors) throw HttpError::invalid($errors);
}

function perm(string $p): void
{
    if (!ctx()->can($p)) throw HttpError::forbidden();
}

/** Date part (local) of an ISO timestamp. */
function day_of(?string $iso): ?string
{
    if (!$iso) return null;
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(config('cafechalao.timezone')))->format('Y-m-d');
}

function rupees(int $paise): string
{
    return '₹' . rtrim(rtrim(number_format($paise / 100, 2, '.', ''), '0'), '.');
}

/** Array helpers used where the mock used JS array methods. */
function sum_by(array $rows, callable|string $f): int|float
{
    $s = 0;
    foreach ($rows as $r) $s += is_string($f) ? ($r[$f] ?? 0) : $f($r);
    return $s;
}

function where_eq(array $rows, string $key, mixed $value): array
{
    return array_values(array_filter($rows, fn ($r) => ($r[$key] ?? null) === $value));
}

function first_where(array $rows, callable $f): ?array
{
    foreach ($rows as $r) if ($f($r)) return $r;
    return null;
}

function wa_link(?string $phone, string $body): ?string
{
    $digits = substr(preg_replace('/\D/', '', (string) $phone), -10);
    return strlen($digits) === 10 ? 'https://wa.me/91' . $digits . '?text=' . rawurlencode($body) : null;
}
