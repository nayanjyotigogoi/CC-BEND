<?php

namespace App\Core;

/**
 * Single source of truth for every table (SRS section 8): the migration builds the tables from this list,
 * Db reads it to cast values, and the seeder uses it to load the demo data. Add a column here and it exists everywhere.
 *
 * Types: s = string, t = text, i = integer (paise, counts), n = decimal number, b = boolean, j = JSON,
 *        d = date (YYYY-MM-DD), ts = ISO-8601 timestamp string (same format the frontend uses).
 * Tenant tables also get: id, tenant_id, created_at, updated_at, created_by, deleted_at.
 */
class Schema
{
    /** Tables without tenant_id or the standard base columns (platform level). */
    public const PLATFORM = ['tenants', 'support_tickets', 'platform_admins', 'api_tokens', 'idempotency_keys', 'counters'];

    public static function tables(): array
    {
        return [
            // Platform (L1)
            'tenants' => ['slug:s', 'domains:j', 'name:s', 'owner_name:s', 'owner_phone:s', 'owner_email:s', 'city:s', 'business_type:s', 'hosting:s', 'licence_key:s', 'plan:s', 'purchase_date:ts', 'support_expiry:ts', 'status:s', 'version:s', 'last_backup_at:ts', 'uptime_pct:n', 'go_live_date:ts', 'support_access_until:ts', 'created_at:ts'],
            'support_tickets' => ['tenant_id:s', 'subject:s', 'kind:s', 'quote:i', 'billing_status:s', 'status:s', 'created_at:ts'],
            'platform_admins' => ['name:s', 'email:s', 'role:s'],
            'api_tokens' => ['token:s', 'tenant_id:s', 'kind:s', 'subject_id:s', 'created_at:ts'],
            'idempotency_keys' => ['key:s', 'tenant_id:s', 'response:j', 'created_at:ts'],
            'counters' => ['tenant_id:s', 'name:s', 'value:i'],

            // Outlet and settings
            'outlets' => ['name:s', 'legal_name:s', 'business_type:s', 'address:t', 'city:s', 'phone:s', 'email:s', 'opening_time:s', 'closing_time:s', 'tagline:s', 'setup_step:i'],
            'brand_settings' => ['app_name:s', 'short_name:s', 'logo_url:t', 'primary_color:s', 'accent_color:s', 'hero_title:s', 'hero_subtitle:s'],
            'tax_settings' => ['gstin:s', 'gst_scheme:s', 'default_gst_rate:n', 'fssai_number:s', 'invoice_prefix:s', 'hsn_sac:s', 'place_of_supply:s', 'service_charge_enabled:b', 'service_charge_pct:n', 'round_off:b'],
            'business_type_flags' => ['features:j', 'qr_order_flow:s', 'auto_accept_online:b', 'kds_amber_min:i', 'kds_red_min:i', 'language:s'],
            'payment_settings' => ['modes:j', 'gateway:s', 'gateway_key_id:s', 'gateway_secret:t', 'gateway_configured:b', 'upi_id:s', 'upi_qr_url:t'],
            'delivery_settings' => ['enabled:b', 'mode:s', 'charge_type:s', 'flat_charge:i', 'slabs:j', 'min_order:i', 'allow_prepaid:b', 'allow_cod:b', 'allow_upi_on_delivery:b'],
            'loyalty_settings' => ['enabled:b', 'earn_per_rupees:n', 'point_value:i', 'min_bill_to_redeem:i', 'expiry_days:i'],
            'printers' => ['name:s', 'station:s', 'width_mm:i', 'connection:s', 'address:s', 'online:b'],
            'devices' => ['name:s', 'kind:s', 'station:s', 'last_seen_at:ts'],

            // Users
            'roles' => ['key:s', 'name:s', 'permissions:j'],
            'approval_limits' => ['role:s', 'discount_pct:n', 'void_value:i', 'refund_value:i', 'expense_value:i', 'po_value:i'],
            'users' => ['employee_code:s', 'name:s', 'role:s', 'phone:s', 'email:s', 'pin_hash:s', 'active:b', 'avatar_color:s'],
            'staff_profiles' => ['user_id:s', 'salary_type:s', 'salary_amount:i', 'weekly_off:i', 'shift_start:s', 'shift_end:s', 'id_document:s', 'joined_on:d'],

            // Customers (owned by the café)
            'customers' => ['name:s', 'phone:s', 'phone_verified:b', 'email:s', 'google_id:s', 'birthday:s', 'anniversary:s', 'gender:s', 'source:s', 'marketing_consent:b', 'tags:j', 'loyalty_points:i', 'total_spend:i', 'visits:i', 'last_visit_at:ts'],
            'addresses' => ['customer_id:s', 'label:s', 'line1:s', 'line2:s', 'landmark:s', 'city:s', 'pincode:s'],
            'consent_logs' => ['customer_id:s', 'purpose:s', 'granted:b'],

            // Menu
            'categories' => ['name:s', 'name_hi:s', 'sort:i', 'station:s', 'active:b'],
            'items' => ['category_id:s', 'code:s', 'name:s', 'name_hi:s', 'description:t', 'food_type:s', 'price:i', 'gst_rate:n', 'photo_url:t', 'bestseller:b', 'in_stock:b', 'available_today:b', 'prep_minutes:i', 'availability_slot_id:s', 'add_on_group_ids:j', 'sort:i'],
            'variants' => ['item_id:s', 'name:s', 'price:i', 'is_default:b'],
            'add_on_groups' => ['name:s', 'min_select:i', 'max_select:i'],
            'add_ons' => ['group_id:s', 'name:s', 'price:i', 'food_type:s'],
            'availability_slots' => ['name:s', 'start_time:s', 'end_time:s'],

            // Dine-in
            'areas' => ['name:s', 'sort:i'],
            'dining_tables' => ['area_id:s', 'name:s', 'seats:i', 'status:s', 'qr_code:s', 'merged_into:s', 'current_session_id:s'],
            'table_sessions' => ['table_id:s', 'guests:i', 'captain_id:s', 'customer_id:s', 'opened_at:ts', 'closed_at:ts', 'waiter_called_at:ts'],
            'reservations' => ['customer_id:s', 'name:s', 'phone:s', 'date:d', 'time:s', 'party_size:i', 'table_preference:s', 'table_id:s', 'notes:t', 'status:s'],
            'waitlist_entries' => ['name:s', 'phone:s', 'party_size:i', 'quoted_wait_min:i', 'status:s', 'table_id:s'],

            // Orders
            'orders' => ['number:s', 'token:i', 'type:s', 'channel:s', 'status:s', 'payment_status:s', 'payment_method:s', 'customer_id:s', 'customer_name:s', 'customer_phone:s', 'table_id:s', 'table_session_id:s', 'guests:i', 'captain_id:s', 'cashier_id:s', 'address_text:t', 'pickup_time:s', 'note:t', 'coupon_code:s', 'points_redeemed:i', 'gift_card_code:s', 'subtotal:i', 'discount:i', 'service_charge:i', 'tax:i', 'delivery_charge:i', 'packaging:i', 'tip:i', 'round_off:i', 'total:i', 'delivery_otp:s', 'payment_reference:s', 'idempotency_key:s', 'bill_requested:b', 'accepted_at:ts', 'ready_at:ts', 'completed_at:ts'],
            'order_items' => ['order_id:s', 'item_id:s', 'variant_id:s', 'name:s', 'variant_name:s', 'add_ons:j', 'unit_price:i', 'qty:i', 'gst_rate:n', 'note:t', 'course:s', 'kot_id:s', 'status:s', 'complimentary:b'],
            'kots' => ['order_id:s', 'number:i', 'station:s', 'status:s', 'table_name:s', 'order_type:s', 'started_at:ts', 'ready_at:ts', 'printed:b'],
            'order_status_logs' => ['order_id:s', 'status:s', 'note:t'],

            // Billing
            'invoices' => ['order_id:s', 'number:s', 'financial_year:s', 'bill_of_supply:b', 'subtotal:i', 'discount:i', 'service_charge:i', 'taxable:i', 'cgst:i', 'sgst:i', 'round_off:i', 'total:i', 'cashier_id:s', 'shift_id:s', 'reprints:i'],
            'invoice_taxes' => ['invoice_id:s', 'rate:n', 'taxable:i', 'cgst:i', 'sgst:i'],
            'payments' => ['invoice_id:s', 'order_id:s', 'mode:s', 'amount:i', 'reference:s', 'status:s', 'gateway:s', 'shift_id:s'],
            'refunds' => ['invoice_id:s', 'amount:i', 'reason:t', 'approved_by:s'],
            'discounts' => ['order_id:s', 'kind:s', 'value:n', 'amount:i', 'reason:t', 'approved_by:s'],
            'voids' => ['order_id:s', 'order_item_id:s', 'amount:i', 'reason:t', 'approved_by:s'],
            'shifts' => ['user_id:s', 'opened_at:ts', 'closed_at:ts', 'opening_cash:i', 'handed_to:s'],
            'day_closes' => ['business_date:d', 'expected_cash:i', 'counted_cash:i', 'variance:i', 'variance_reason:t', 'closed_by:s', 'sales_total:i'],
            'cash_counts' => ['day_close_id:s', 'denomination:i', 'count:i'],

            // Marketing
            'loyalty_ledger' => ['customer_id:s', 'order_id:s', 'points:i', 'reason:s', 'expires_at:ts'],
            'coupons' => ['code:s', 'kind:s', 'value:n', 'max_discount:i', 'min_order:i', 'valid_from:d', 'valid_to:d', 'usage_limit_total:i', 'usage_limit_per_customer:i', 'used_count:i', 'order_types:j', 'item_ids:j', 'active:b'],
            'coupon_redemptions' => ['coupon_id:s', 'order_id:s', 'customer_id:s', 'amount:i'],
            'gift_cards' => ['code:s', 'initial_value:i', 'balance:i', 'customer_id:s', 'expires_on:d', 'active:b'],
            'gift_card_txns' => ['gift_card_id:s', 'order_id:s', 'amount:i'],
            'membership_plans' => ['name:s', 'price:i', 'validity_days:i', 'discount_pct:n', 'free_item_id:s', 'benefits:t', 'active:b'],
            'memberships' => ['plan_id:s', 'customer_id:s', 'starts_on:d', 'ends_on:d', 'status:s'],
            'offers' => ['name:s', 'kind:s', 'description:t', 'discount_pct:n', 'days:j', 'start_time:s', 'end_time:s', 'item_ids:j', 'buy_qty:i', 'get_qty:i', 'combo_price:i', 'active:b'],
            'marketing_budgets' => ['month:s', 'amount:i', 'enforce:b'],
            'message_logs' => ['customer_id:s', 'channel:s', 'template:s', 'to:s', 'body:t', 'status:s'],
            'feedback' => ['order_id:s', 'customer_id:s', 'food:i', 'service:i', 'ambience:i', 'comment:t', 'replied:b'],

            // Stock
            'raw_materials' => ['name:s', 'category:s', 'unit:s', 'storage:s', 'min_level:n', 'stock:n', 'avg_cost:i', 'last_cost:i', 'supplier_id:s'],
            'recipes' => ['item_id:s', 'variant_id:s'],
            'recipe_lines' => ['recipe_id:s', 'raw_material_id:s', 'qty:n', 'unit:s'],
            'stock_ledger' => ['raw_material_id:s', 'qty:n', 'reason:s', 'ref_id:s', 'balance_after:n'],
            'stock_counts' => ['counted_on:d', 'lines:j', 'status:s'],
            'wastage' => ['raw_material_id:s', 'item_id:s', 'qty:n', 'unit:s', 'reason:s', 'note:t', 'value:i'],

            // Purchase
            'suppliers' => ['name:s', 'gstin:s', 'contact_person:s', 'phone:s', 'email:s', 'payment_terms_days:i', 'items_supplied:t', 'outstanding:i'],
            'purchase_requests' => ['number:s', 'lines:j', 'status:s', 'estimated_value:i'],
            'quotations' => ['purchase_request_id:s', 'supplier_id:s', 'received_on:d'],
            'quotation_lines' => ['quotation_id:s', 'raw_material_id:s', 'rate:i'],
            'purchase_orders' => ['number:s', 'supplier_id:s', 'purchase_request_id:s', 'status:s', 'expected_on:d', 'total:i'],
            'po_lines' => ['purchase_order_id:s', 'raw_material_id:s', 'qty:n', 'rate:i', 'received_qty:n'],
            'grns' => ['number:s', 'purchase_order_id:s', 'supplier_id:s', 'received_on:d', 'total:i', 'invoice_ref:s'],
            'grn_lines' => ['grn_id:s', 'po_line_id:s', 'raw_material_id:s', 'received_qty:n', 'rate:i', 'batch:s', 'expiry:d', 'shortfall:n'],
            'supplier_payments' => ['supplier_id:s', 'amount:i', 'mode:s', 'reference:s', 'paid_on:d'],

            // Expenses
            'expense_categories' => ['name:s', 'monthly_budget:i'],
            'expenses' => ['category_id:s', 'amount:i', 'mode:s', 'spent_on:d', 'note:t', 'bill_photo_url:t', 'status:s', 'petty_cash:b'],
            'budgets' => ['category_id:s', 'month:s', 'amount:i'],
            'approvals' => ['kind:s', 'ref_id:s', 'amount:i', 'summary:t', 'requested_by:s', 'status:s', 'decided_by:s', 'decided_at:ts', 'required_role:s'],

            // Staff
            'attendance' => ['user_id:s', 'date:d', 'clock_in:ts', 'clock_out:ts', 'selfie_url:t', 'status:s', 'overtime_min:i', 'correction_note:t'],
            'shift_rosters' => ['user_id:s', 'day:i', 'start:s', 'end:s'],
            'advances' => ['user_id:s', 'amount:i', 'given_on:d', 'note:t', 'recovered:b'],
            'salary_slips' => ['user_id:s', 'month:s', 'days_present:n', 'overtime_pay:i', 'gross:i', 'advances:i', 'deductions:i', 'delivery_earnings:i', 'net:i', 'paid:b'],

            // Delivery
            'riders' => ['user_id:s', 'name:s', 'phone:s', 'vehicle:s', 'id_document:s', 'type:s', 'online:b', 'active:b'],
            'deliveries' => ['order_id:s', 'rider_id:s', 'status:s', 'charge:i', 'distance_km:n', 'to_collect:i', 'collected:i', 'collected_mode:s', 'proof:s', 'accepted_at:ts', 'picked_at:ts', 'delivered_at:ts'],
            'delivery_requests' => ['delivery_id:s', 'rider_id:s', 'status:s'],
            'rider_settlements' => ['rider_id:s', 'business_date:d', 'cash_collected:i', 'charges_earned:i', 'net:i', 'settled:b', 'settled_by:s'],

            // System
            'audit_logs' => ['user_id:s', 'user_name:s', 'action:s', 'entity:s', 'entity_id:s', 'device:s', 'old_value:j', 'new_value:j'],
            'notifications' => ['audience:s', 'user_id:s', 'title:s', 'body:t', 'link:s', 'read:b'],
        ];
    }

    /** column => type for a table, including base columns. */
    public static function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $out = in_array($table, self::PLATFORM, true) ? ['id' => 's'] : ['id' => 's', 'tenant_id' => 's', 'created_at' => 'ts', 'updated_at' => 'ts', 'created_by' => 's', 'deleted_at' => 'ts'];
        foreach (self::tables()[$table] ?? throw new \InvalidArgumentException("Unknown table $table") as $c) {
            [$name, $type] = explode(':', $c);
            $out[$name] = $type;
        }
        return $cache[$table] = $out;
    }

    public static function isTenantTable(string $table): bool
    {
        return !in_array($table, self::PLATFORM, true);
    }

    /** Columns queried often get an index next to tenant_id (SRS 16.6). */
    public const INDEXED = ['status', 'order_id', 'customer_id', 'item_id', 'user_id', 'phone', 'code', 'raw_material_id', 'invoice_id', 'kot_id', 'table_id', 'rider_id', 'delivery_id', 'supplier_id', 'purchase_order_id', 'business_date', 'date', 'month', 'key', 'slug', 'token', 'qr_code', 'employee_code'];
}
