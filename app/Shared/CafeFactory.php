<?php

namespace App\Shared;

use App\Core\Db;
use App\Core\HttpError;

/**
 * Creates a new café (tenant) with every default setting: one place, used by Control Panel onboarding
 * and by `php artisan cafe:create`. Business-type presets and role permissions come from config/cafechalao.php.
 */
class CafeFactory
{
    public static function create(array $b): array
    {
        need($b, ['name', 'slug', 'owner_name', 'owner_phone', 'owner_email', 'city', 'business_type']);
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($b['slug']));
        if (Db::raw('tenants')->where('slug', $slug)->exists()) throw HttpError::invalid(['slug' => 'This web address is taken']);
        $types = config('cafechalao.business_types');
        if (!isset($types[$b['business_type']])) throw HttpError::invalid(['business_type' => 'Unknown business type']);
        $c = ctx();
        $now = $c->iso();
        $id = 'tn_' . str_replace('-', '_', $slug);
        $tenant = ['id' => $id, 'slug' => $slug, 'domains' => array_values(array_filter([$slug . '.localhost', $b['domain'] ?? null])), 'name' => $b['name'], 'owner_name' => $b['owner_name'], 'owner_phone' => $b['owner_phone'],
            'owner_email' => strtolower($b['owner_email']), 'city' => $b['city'], 'business_type' => $b['business_type'], 'hosting' => $b['hosting'] ?? 'shared',
            'licence_key' => 'CC-P1-' . strtoupper(substr($slug, 0, 4)) . '-' . random_int(1000, 9999) . '-' . random_int(1000, 9999), 'plan' => 'phase1', 'purchase_date' => $now,
            'support_expiry' => $c->iso($c->now()->modify('+90 days')), 'status' => $b['status'] ?? 'trial', 'version' => config('cafechalao.latest_version'), 'last_backup_at' => $now, 'uptime_pct' => 100, 'created_at' => $now];
        Db::raw('tenants')->insert(Db::encode('tenants', $tenant));

        $prev = $c->tenant;
        $c->tenant = Db::decode('tenants', Db::raw('tenants')->where('id', $id)->first());
        try {
            Db::create('outlets', ['name' => $b['name'], 'legal_name' => $b['legal_name'] ?? $b['name'], 'business_type' => $b['business_type'], 'address' => $b['address'] ?? '', 'city' => $b['city'], 'phone' => $b['owner_phone'], 'email' => $b['owner_email'], 'opening_time' => '09:00', 'closing_time' => '22:00', 'tagline' => '', 'setup_step' => 0], 'ot');
            Db::create('brand_settings', ['app_name' => $b['name'], 'short_name' => explode(' ', $b['name'])[0], 'primary_color' => '#5E6B33', 'accent_color' => '#F9D1D9', 'hero_title' => "Welcome to {$b['name']}", 'hero_subtitle' => 'Order online or scan the QR at your table.'], 'bs');
            Db::create('tax_settings', ['gst_scheme' => 'regular', 'default_gst_rate' => 5, 'fssai_number' => '', 'invoice_prefix' => self::invoicePrefix($b['name'], $slug), 'hsn_sac' => '9963', 'place_of_supply' => '', 'service_charge_enabled' => $b['business_type'] === 'restaurant', 'service_charge_pct' => 5, 'round_off' => true], 'tx');
            Db::create('business_type_flags', ['features' => $types[$b['business_type']], 'qr_order_flow' => 'direct_to_kitchen', 'auto_accept_online' => true, 'kds_amber_min' => 10, 'kds_red_min' => 15, 'language' => 'en'], 'bf');
            Db::create('payment_settings', ['modes' => ['cash' => true, 'upi' => true, 'card' => true, 'online' => true, 'cod' => true, 'upi_on_delivery' => true], 'gateway' => 'upi_qr', 'gateway_key_id' => '', 'gateway_configured' => false, 'upi_id' => '', 'upi_qr_url' => null], 'ps');
            Db::create('delivery_settings', ['enabled' => $types[$b['business_type']]['delivery'], 'mode' => 'own', 'charge_type' => 'flat', 'flat_charge' => 3000, 'slabs' => [], 'min_order' => 9900, 'allow_prepaid' => true, 'allow_cod' => true, 'allow_upi_on_delivery' => true], 'ds');
            Db::create('loyalty_settings', ['enabled' => true, 'earn_per_rupees' => 10, 'point_value' => 25, 'min_bill_to_redeem' => 20000, 'expiry_days' => 365], 'ls');
            self::rolesAndLimits();
            // No PIN yet: the owner sets their own the first time they sign in with their employee ID.
            Db::create('users', ['employee_code' => 'E001', 'name' => $b['owner_name'], 'role' => 'owner', 'phone' => $b['owner_phone'], 'email' => strtolower($b['owner_email']), 'pin_hash' => null, 'active' => true, 'avatar_color' => '#5E6B33'], 'us');
            foreach (['Rent', 'Electricity', 'Gas', 'Salary', 'Marketing', 'Repairs', 'Misc'] as $n) Db::create('expense_categories', ['name' => $n, 'monthly_budget' => 0], 'ec');
        } finally {
            $c->tenant = $prev;
        }
        return ['id' => $id, 'slug' => $slug, 'owner_code' => 'E001', 'setup_url' => "/admin/setup?outlet=$slug"];
    }

    /**
     * Invoice prefix from the café's name, so each café's bills are told apart:
     * "Café 1BHK" → "1BHK/", "Chai Adda" → "CA/". Words like "Café" and "The" are skipped; the owner can change it in Tax & GST.
     */
    public static function invoicePrefix(string $name, string $slug = ''): string
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [], fn ($w) => $w !== '' && !in_array(mb_strtolower($w), ['cafe', 'café', 'the', 'and', 'restaurant', 'kitchen'], true)));
        $ascii = fn ($w) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) iconv('UTF-8', 'ASCII//TRANSLIT', $w)));
        $p = count($words) === 1 ? substr($ascii($words[0]), 0, 6) : implode('', array_map(fn ($w) => substr($ascii($w), 0, 1), array_slice($words, 0, 4)));
        return ($p !== '' ? $p : strtoupper(substr(str_replace('-', '', $slug), 0, 4))) . '/';
    }

    /** Roles with the default permission grid and approval limits, from config (also used by the demo seeder). */
    public static function rolesAndLimits(): void
    {
        foreach (config('cafechalao.role_permissions') as $key => $perms) {
            $existing = Db::first('roles', fn ($q) => $q->where('key', $key));
            $existing ? Db::update('roles', $existing['id'], ['permissions' => $perms]) : Db::create('roles', ['key' => $key, 'name' => config('cafechalao.role_labels')[$key], 'permissions' => $perms], 'rl');
        }
        foreach (config('cafechalao.default_limits') as $role => [$d, $v, $rf, $e, $po]) {
            if (!Db::q('approval_limits')->where('role', $role)->exists()) Db::create('approval_limits', ['role' => $role, 'discount_pct' => $d, 'void_value' => $v, 'refund_value' => $rf, 'expense_value' => $e, 'po_value' => $po], 'al');
        }
    }
}
