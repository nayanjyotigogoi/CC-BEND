<?php

namespace App\Core;

/** Idempotency-Key (SRS 16.6): the same key returns the first response; no second charge or order. Kept 24 h. */
class Idempotency
{
    public static function once(string $key, callable $run): mixed
    {
        $id = ctx()->tenantId() . ':' . $key;
        $hit = Db::raw('idempotency_keys')->where('id', $id)->first();
        if ($hit) return json_decode($hit->response, true);
        $data = $run();
        Db::raw('idempotency_keys')->insert(['id' => $id, 'key' => $key, 'tenant_id' => ctx()->tenantId(), 'response' => json_encode($data), 'created_at' => ctx()->iso()]);
        return $data;
    }
}
