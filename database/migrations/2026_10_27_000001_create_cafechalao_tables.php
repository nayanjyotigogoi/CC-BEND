<?php

use App\Core\Schema as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Builds every CafeChalao table from App\Core\Schema, so columns are defined in one place. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(Tables::tables()) as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                foreach (Tables::columns($table) as $col => $type) {
                    if ($col === 'id') {
                        $t->string('id', 64)->primary();
                        continue;
                    }
                    $c = match ($type) {
                        's' => $t->string($col, $col === 'token' || str_ends_with($col, '_url') ? 255 : 191),
                        't' => $t->longText($col),
                        'i' => $t->bigInteger($col),
                        'n' => $t->decimal($col, 14, 4),
                        'b' => $t->boolean($col),
                        'j' => $t->json($col),
                        'd' => $t->string($col, 10),
                        'ts' => $t->string($col, 32),
                    };
                    $c->nullable();
                }
                if (Tables::isTenantTable($table)) {
                    $t->index(['tenant_id', 'deleted_at']);
                    $t->index(['tenant_id', 'created_at']);
                }
                foreach (Tables::INDEXED as $col) {
                    if (isset(Tables::columns($table)[$col]) && Tables::columns($table)[$col] !== 't') {
                        Tables::isTenantTable($table) ? $t->index(['tenant_id', $col]) : $t->index($col);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(Tables::tables())) as $table) Schema::dropIfExists($table);
    }
};
