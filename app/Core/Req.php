<?php

namespace App\Core;

/** What a handler receives: JSON body, query string and path params. */
class Req
{
    public function __construct(public array $body, public array $query, public array $params) {}

    public function p(string $k): string { return (string) ($this->params[$k] ?? ''); }
    public function q(string $k, mixed $default = null): mixed { $v = $this->query[$k] ?? null; return $v === null || $v === '' ? $default : $v; }
    public function b(string $k, mixed $default = null): mixed { return $this->body[$k] ?? $default; }
}
