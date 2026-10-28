<?php

use App\Core\Schema as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds columns that were added to App\Core\Schema after a database was created (here: users.employee_code),
 * then gives existing staff employee IDs (E001, E002, … per café, owner first, in the order they were added).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(Tables::tables()) as $table) {
            if (!Schema::hasTable($table)) continue;
            $missing = array_diff_key(Tables::columns($table), array_flip(Schema::getColumnListing($table)));
            if (!$missing) continue;
            Schema::table($table, function (Blueprint $t) use ($missing, $table) {
                foreach ($missing as $col => $type) {
                    $c = match ($type) {
                        's' => $t->string($col, str_ends_with($col, '_url') ? 255 : 191),
                        't' => $t->longText($col),
                        'i' => $t->bigInteger($col),
                        'n' => $t->decimal($col, 14, 4),
                        'b' => $t->boolean($col),
                        'j' => $t->json($col),
                        'd' => $t->string($col, 10),
                        'ts' => $t->string($col, 32),
                    };
                    $c->nullable();
                    if (in_array($col, Tables::INDEXED, true)) Tables::isTenantTable($table) ? $t->index(['tenant_id', $col]) : $t->index($col);
                }
            });
        }

        \App\Modules\Staff::backfillCodes();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'employee_code')) Schema::table('users', fn (Blueprint $t) => $t->dropColumn('employee_code'));
    }
};
