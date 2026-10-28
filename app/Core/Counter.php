<?php

namespace App\Core;

use Illuminate\Support\Facades\DB;

/** Sequential numbers (order, KOT, invoice per financial year, PO …) with a row lock so two devices never clash. */
class Counter
{
    public static function next(string $name, int $start = 0): int
    {
        $id = ctx()->tenantId() . ':' . $name;
        $row = DB::table('counters')->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            DB::table('counters')->insert(['id' => $id, 'tenant_id' => ctx()->tenantId(), 'name' => $name, 'value' => $start + 1]);
            return $start + 1;
        }
        DB::table('counters')->where('id', $id)->update(['value' => $row->value + 1]);
        return $row->value + 1;
    }
}
