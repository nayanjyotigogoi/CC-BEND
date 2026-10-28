<?php

/*
 * CafeChalao platform settings: one place for permissions, role defaults, business-type presets,
 * limits and third-party keys. Per-café values live in the database (Settings); these are the defaults.
 */

$permissions = [
    'pos.bill' => 'Bill and take payments',
    'pos.discount_preset' => 'Apply preset discounts',
    'pos.discount_any' => 'Apply any discount (within limit)',
    'pos.void' => 'Void items (within limit)',
    'pos.refund' => 'Refund (within limit)',
    'pos.reprint' => 'Reprint bills and KOTs',
    'pos.day_close' => 'Close the day',
    'orders.take' => 'Take orders and send KOT',
    'orders.accept_online' => 'Accept online orders',
    'tables.manage' => 'Seat guests, merge and move tables',
    'reservations.manage' => 'Reservations and waitlist',
    'customers.create' => 'Create customer profiles',
    'customers.view' => 'View customer database',
    'kds.use' => 'Use kitchen display',
    'kds.wastage' => 'Record wastage',
    'stock.view' => 'View stock',
    'stock.receive' => 'Receive goods (GRN) and stock counts',
    'stock.request' => 'Raise purchase requests',
    'stock.approve' => 'Approve purchase requests and POs',
    'menu.edit' => 'Edit menu and availability',
    'menu.price' => 'Change prices',
    'marketing.manage' => 'Coupons, loyalty, gift cards, memberships, offers, marketing budget',
    'expenses.create' => 'Enter expenses',
    'expenses.approve' => 'Approve expenses',
    'staff.manage' => 'Manage staff and attendance',
    'staff.salary' => 'View and pay salaries',
    'delivery.assign' => 'Assign riders and settle',
    'delivery.ride' => 'Rider app',
    'aggregators.manage' => 'Swiggy/Zomato tracker',
    'reports.view' => 'View reports',
    'dashboard.owner' => 'Owner dashboard',
    'audit.view' => 'View audit log',
    'settings.manage' => 'All settings, branding and roles',
    'approvals.decide' => 'Approve requests in the queue',
];
$all = array_keys($permissions);

/** Back office (stock, purchase, expenses, staff, salary) is for the owner and manager only. */
$backOffice = ['stock.view', 'stock.receive', 'stock.request', 'stock.approve', 'expenses.create', 'expenses.approve', 'staff.manage', 'staff.salary'];

$features = ['dine_in', 'qr_ordering', 'takeaway', 'delivery', 'counter_tokens', 'reservations', 'waitlist', 'captain_app', 'kds', 'loyalty', 'coupons', 'gift_cards', 'memberships', 'offers', 'aggregator_tracker', 'service_charge', 'stock', 'purchase', 'expenses', 'staff', 'tips'];
$on = array_fill_keys($features, true);
// Swiggy/Zomato tracker is not in this release.
$base = array_merge($on, ['service_charge' => false, 'aggregator_tracker' => false]);

return [
    'timezone' => env('CAFE_TIMEZONE', 'Asia/Kolkata'),
    'latest_version' => '1.4.2',
    'demo_otp' => env('DEMO_OTP', '123456'),
    'demo_mode' => env('DEMO_MODE', true),

    'permissions' => $permissions,

    'role_permissions' => [
        'owner' => $all,
        'manager' => array_values(array_diff($all, ['settings.manage', 'menu.price'])),
        'cashier' => ['pos.bill', 'pos.discount_preset', 'pos.reprint', 'pos.day_close', 'orders.take', 'orders.accept_online', 'customers.create', 'delivery.assign', 'tables.manage'],
        'receptionist' => ['reservations.manage', 'customers.create', 'customers.view', 'tables.manage', 'orders.take'],
        'captain' => ['orders.take', 'customers.create', 'tables.manage'],
        'kitchen' => ['kds.use', 'kds.wastage'],
        'storekeeper' => ['kds.wastage'],
        'rider' => ['delivery.ride'],
    ],
    'back_office_permissions' => $backOffice,

    'role_labels' => ['owner' => 'Owner', 'manager' => 'Manager', 'cashier' => 'Cashier', 'receptionist' => 'Receptionist', 'captain' => 'Captain', 'kitchen' => 'Kitchen', 'storekeeper' => 'Storekeeper', 'rider' => 'Rider'],
    'role_rank' => ['owner' => 100, 'manager' => 80, 'cashier' => 40, 'receptionist' => 30, 'captain' => 30, 'storekeeper' => 30, 'kitchen' => 20, 'rider' => 10],

    /** Business Type presets (SRS 1, M11.1). */
    'business_types' => [
        'cafe' => array_merge($base, ['counter_tokens' => false]),
        'qsr' => array_merge($base, ['reservations' => false, 'captain_app' => false, 'waitlist' => false, 'dine_in' => false, 'qr_ordering' => false]),
        'cloud_kitchen' => array_merge($base, ['dine_in' => false, 'qr_ordering' => false, 'reservations' => false, 'waitlist' => false, 'captain_app' => false, 'counter_tokens' => false]),
        'restaurant' => array_merge($base, ['counter_tokens' => false, 'service_charge' => true]),
    ],

    /** Default approval limits in paise (discount in %). */
    'default_limits' => [
        'owner' => [100, 1e12, 1e12, 1e12, 1e12],
        'manager' => [25, 200000, 200000, 500000, 2500000],
        'cashier' => [5, 0, 0, 50000, 0],
    ],

    /*
     * Third-party keys. Leave empty to use the built-in alternatives:
     * - Google sign-in → customers can sign in with just their email (account created on first use).
     * - Payment gateway → the café uploads its own UPI QR; customers scan and pay the bill amount directly.
     */
    'google_client_id' => env('GOOGLE_CLIENT_ID', ''),
    'gateways' => [
        'razorpay' => ['key' => env('RAZORPAY_KEY', ''), 'secret' => env('RAZORPAY_SECRET', '')],
        'cashfree' => ['key' => env('CASHFREE_APP_ID', ''), 'secret' => env('CASHFREE_SECRET', '')],
        'phonepe' => ['key' => env('PHONEPE_MERCHANT_ID', ''), 'secret' => env('PHONEPE_SALT', '')],
    ],

    /** CA certificate bundle for outgoing HTTPS when PHP's own curl.cainfo is missing or wrong. */
    'ca_bundle' => env('CA_BUNDLE'),

    'idle_minutes' => 15,
    'idempotency_hours' => 24,
];
