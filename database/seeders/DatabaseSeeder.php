<?php

namespace Database\Seeders;

use App\Core\Ctx;
use App\Core\Db;
use App\Core\Schema;
use App\Shared\CafeFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB as SQL;

/**
 * Loads the demo cafés from database/seed-data/<table>.json (made by `npm run seed:export` in the frontend repo),
 * so the API starts with exactly the rows the frontend was built and tested against.
 * PINs are hashed; the latest role rules and payment choice (UPI QR) are applied on top.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $dir = env('SEED_DATA_PATH', base_path('database/seed-data'));
        if (!is_dir($dir)) throw new \RuntimeException("Seed data not found in $dir. Copy it from the frontend repo (npm run seed:export writes seed-data/) into database/seed-data, or set SEED_DATA_PATH.");

        foreach (array_keys(Schema::tables()) as $table) {
            $file = "$dir/$table.json";
            if (!is_file($file)) continue;
            $rows = json_decode(file_get_contents($file), true);
            SQL::table($table)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                SQL::table($table)->insert(array_map(function ($r) use ($table) {
                    if ($table === 'users') $r['pin_hash'] = $r['pin_hash'] ? password_hash((string) $r['pin_hash'], PASSWORD_BCRYPT, ['cost' => 10]) : null;
                    // Every column present, so all rows in a batch have the same keys.
                    return Db::encode($table, array_merge(array_fill_keys(array_keys(Schema::columns($table)), null), $r));
                }, $chunk));
            }
            $this->command?->info(sprintf('%-22s %6d rows', $table, count($rows)));
        }

        // Per-café updates for this release.
        $ctx = app(Ctx::class);
        foreach (SQL::table('tenants')->get() as $t) {
            $ctx->tenant = Db::decode('tenants', $t);
            CafeFactory::rolesAndLimits(); // stock, purchase, expenses, staff, salary: owner and manager only
            SQL::table('payment_settings')->where('tenant_id', $t->id)->update(['gateway' => 'upi_qr', 'gateway_configured' => 0]);
            $flags = SQL::table('business_type_flags')->where('tenant_id', $t->id)->first();
            // Table QR orders go straight to the kitchen (the guest is at the café).
            SQL::table('business_type_flags')->where('id', $flags->id)->update(['features' => json_encode(['aggregator_tracker' => false] + json_decode($flags->features, true)), 'qr_order_flow' => 'direct_to_kitchen']);
            if (!SQL::table('marketing_budgets')->where('tenant_id', $t->id)->exists()) {
                Db::create('marketing_budgets', ['month' => substr($ctx->today(), 0, 7), 'amount' => 1500000, 'enforce' => false], 'mb');
            }
        }
        $ctx->tenant = null;
        \App\Modules\Staff::backfillCodes(); // seed data exported before employee IDs existed

        // Demo dish photos (downloaded once, re-linked on every seed). Skipped in tests and if offline.
        if (!app()->environment('testing')) {
            try {
                $this->command?->call('menu:demo-photos');
            } catch (\Throwable $e) {
                $this->command?->warn('Menu photos skipped: ' . $e->getMessage());
            }
        }
    }
}
