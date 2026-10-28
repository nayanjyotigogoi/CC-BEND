<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The same cases as web/tests/unit/api.test.ts, run against the Laravel API (SRS 16.5). */
class ApiTest extends TestCase
{
    public function test_iso_01_cafes_cannot_see_each_others_customers(): void
    {
        $this->staff('owner');
        $id = $this->api('GET', '/customers', [], 'owner')['data'][0]['id'];
        $this->staff('owner', 'momo-mahal');
        $this->api('GET', "/customers/$id", [], 'owner', 'momo-mahal', null, 404);
    }

    public function test_iso_02_token_only_works_at_its_cafe(): void
    {
        $this->staff('owner');
        $this->api('GET', '/customers', [], 'owner', 'momo-mahal', null, 401);
    }

    public function test_iso_03_same_email_is_a_separate_customer_per_cafe(): void
    {
        $a = $this->customer('new.person@gmail.com')['customer'];
        $b = $this->customer('new.person@gmail.com', 'brew-bros')['customer'];
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame('tn_brew_bros', $b['tenant_id']);
    }

    public function test_email_login_creates_the_account_on_first_use(): void
    {
        $r = $this->customer('first.time@example.com');
        $this->assertTrue($r['needs_profile']);
        $again = $this->customer('first.time@example.com');
        $this->assertSame($r['customer']['id'], $again['customer']['id']);
    }

    public function test_cus_01_delivery_order_end_to_end(): void
    {
        $this->customer();
        $chai = $this->item('Masala Chai');
        $addr = $this->api('GET', '/me/addresses', [], 'customer')['data'][0];
        $o = $this->api('POST', '/orders', ['lines' => [['item_id' => $chai['id'], 'qty' => 4]], 'order_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $addr['id']], 'customer')['data']['order'];
        $this->assertSame('accepted', $o['status']);

        $this->staff('kitchen');
        foreach (array_filter($this->api('GET', '/kots', [], 'kitchen')['data'], fn ($k) => $k['order_id'] === $o['id']) as $k) {
            $this->api('PATCH', "/kots/{$k['id']}", ['status' => 'preparing'], 'kitchen');
            $this->api('PATCH', "/kots/{$k['id']}", ['status' => 'ready'], 'kitchen');
        }
        $this->staff('cashier');
        $del = collect($this->api('GET', '/deliveries?active=1', [], 'cashier')['data'])->firstWhere('order_id', $o['id']);
        $rider = DB::table('riders')->where('tenant_id', 'tn_chai_adda')->first();
        $this->api('POST', "/deliveries/{$del['id']}/assign", ['rider_ids' => [$rider->id]], 'cashier');
        $this->tokens['rider'] = $this->api('POST', '/auth/rider', ['phone' => $rider->phone, 'otp' => '123456'])['data']['token'];
        $this->api('POST', "/deliveries/{$del['id']}/accept", [], 'rider');
        $this->api('PATCH', "/deliveries/{$del['id']}/status", ['status' => 'picked_up'], 'rider');
        $this->api('PATCH', "/deliveries/{$del['id']}/status", ['status' => 'delivered', 'proof' => 'otp', 'otp' => '0000', 'collected_mode' => 'cash'], 'rider', 'chai-adda', null, 422);
        $otp = $this->row('orders', ['id' => $o['id']])->delivery_otp;
        $this->api('PATCH', "/deliveries/{$del['id']}/status", ['status' => 'delivered', 'proof' => 'otp', 'otp' => $otp, 'collected_mode' => 'cash'], 'rider');
        $done = $this->api('GET', "/orders/{$o['id']}", [], 'customer')['data'];
        $this->assertSame('completed', $done['status']);
        $this->assertMatchesRegularExpression('#^CA/\d\d-\d\d/\d{6}$#', $done['invoice']['number']);
    }

    public function test_upi_qr_order_waits_for_cafe_to_confirm_payment(): void
    {
        $this->customer();
        $chai = $this->item('Masala Chai');
        $r = $this->api('POST', '/orders', ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'order_type' => 'takeaway', 'payment_method' => 'upi_qr'], 'customer')['data'];
        $this->assertSame('pending', $r['order']['payment_status']);
        $this->assertSame('upi_qr', $r['payment']['gateway']);
        $this->api('POST', "/orders/{$r['order']['id']}/pay", ['reference' => 'UTR123'], 'customer');
        $this->assertSame('placed', $this->row('orders', ['id' => $r['order']['id']])->status, 'no KOT before the café confirms');
        $this->staff('cashier');
        $o = $this->api('POST', "/orders/{$r['order']['id']}/confirm-payment", [], 'cashier')['data'];
        $this->assertSame('paid', $o['payment_status']);
        $this->assertNotNull($o['invoice']);
        $this->assertNotEmpty($o['kots']);
    }

    public function test_cus_02_table_qr_order_goes_straight_to_kitchen(): void
    {
        $table = DB::table('dining_tables')->where('tenant_id', 'tn_chai_adda')->where('status', 'free')->first();
        $chai = $this->item('Masala Chai');
        $body = ['lines' => [['item_id' => $chai['id'], 'qty' => 2]], 'order_type' => 'dine_in', 'table_code' => $table->qr_code, 'payment_method' => 'upi_qr'];
        $o = $this->api('POST', '/orders', $body)['data']['order'];
        $this->assertSame('accepted', $o['status'], 'no one has to accept a dine-in table order');
        $this->staff('kitchen');
        $this->assertNotEmpty(array_filter($this->api('GET', '/kots', [], 'kitchen')['data'], fn ($k) => $k['order_id'] === $o['id']));
        $this->api('POST', '/orders', ['order_type' => 'takeaway'] + $body, null, 'chai-adda', null, 422); // a table QR can't be takeaway
    }

    public function test_cus_04_extra_items_after_paying_go_on_a_new_bill(): void
    {
        $table = DB::table('dining_tables')->where('tenant_id', 'tn_chai_adda')->where('status', 'free')->first();
        $chai = $this->item('Masala Chai');
        $first = $this->api('POST', '/orders', ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'order_type' => 'dine_in', 'table_code' => $table->qr_code, 'payment_method' => 'upi_qr'])['data']['order'];
        $this->staff('cashier');
        $this->api('POST', "/orders/{$first['id']}/confirm-payment", [], 'cashier');

        $this->staff('captain');
        $open = $this->api('POST', '/pos/orders', ['type' => 'dine_in', 'table_id' => $table->id], 'captain')['data'];
        $this->assertNotSame($first['id'], $open['id'], 'a paid bill is never reopened');
        $extra = $this->api('POST', "/orders/{$first['id']}/items", ['lines' => [['item_id' => $chai['id'], 'qty' => 2]]], 'captain')['data'];
        $this->assertSame($open['id'], $extra['id'], 'items added to the paid bill land on the unpaid one');
        $this->assertSame($first['total'], $extra['table_bills']['paid']);
        $this->assertSame($extra['total'], $extra['table_bills']['due']);
        $this->assertGreaterThan(0, $extra['total']);

        $this->api('POST', '/shifts/open', ['opening_cash' => 0], 'cashier');
        $this->api('POST', "/orders/{$extra['id']}/settle", ['payments' => [['mode' => 'cash', 'amount' => $extra['total']]]], 'cashier');
        $this->assertSame('cleaning', $this->row('dining_tables', ['id' => $table->id])->status, 'table frees once nothing is due');
        $this->assertSame('completed', $this->row('orders', ['id' => $first['id']])->status);
    }

    public function test_cus_05_more_from_the_table_joins_the_open_bill_but_never_a_upi_one(): void
    {
        $table = DB::table('dining_tables')->where('tenant_id', 'tn_chai_adda')->where('status', 'free')->first();
        $chai = $this->item('Masala Chai');
        $line = ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'order_type' => 'dine_in', 'table_code' => $table->qr_code];
        $first = $this->api('POST', '/orders', $line + ['payment_method' => 'cash'])['data']['order'];
        $more = $this->api('POST', '/orders', $line + ['payment_method' => 'cash'])['data']['order'];
        $this->assertSame($first['id'], $more['id'], 'pay-at-counter extras go on the same bill');
        $this->assertSame(2, (int) DB::table('order_items')->where('order_id', $first['id'])->sum('qty'));

        $upi = $this->api('POST', '/orders', $line + ['payment_method' => 'upi_qr'])['data']['order'];
        $this->assertNotSame($first['id'], $upi['id'], 'a UPI order is its own bill');
        $after = $this->api('POST', '/orders', $line + ['payment_method' => 'cash'])['data']['order'];
        $this->assertSame($first['id'], $after['id'], 'the open unpaid bill keeps taking items, not the UPI one');
    }

    public function test_cus_06_delivery_needs_a_verified_mobile(): void
    {
        $chai = $this->item('Masala Chai');
        $this->tokens['nophone'] = $this->api('POST', '/auth/email', ['email' => 'no.phone@example.com'])['data']['token'];
        $this->api('POST', '/orders', ['lines' => [['item_id' => $chai['id'], 'qty' => 5]], 'order_type' => 'delivery', 'payment_method' => 'cod', 'address' => 'Somewhere'], 'nophone', 'chai-adda', null, 422);
    }

    public function test_wc_01_double_tap_creates_one_order(): void
    {
        $this->customer();
        $chai = $this->item('Masala Chai');
        $body = ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'order_type' => 'takeaway', 'payment_method' => 'upi_qr'];
        $before = DB::table('orders')->count();
        $a = $this->api('POST', '/orders', $body, 'customer', 'chai-adda', 'same-key')['data'];
        $b = $this->api('POST', '/orders', $body, 'customer', 'chai-adda', 'same-key')['data'];
        $this->assertSame($a['order']['id'], $b['order']['id']);
        $this->assertSame($before + 1, DB::table('orders')->count());
    }

    public function test_wc_03_out_of_stock_refused_before_payment(): void
    {
        $chai = $this->item('Masala Chai');
        DB::table('items')->where('id', $chai['id'])->update(['in_stock' => 0]);
        $this->api('POST', '/cart/quote', ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'order_type' => 'takeaway'], null, 'chai-adda', null, 422);
    }

    public function test_wc_04_stale_captain_gets_409(): void
    {
        $this->staff('captain');
        $table = DB::table('dining_tables')->where('tenant_id', 'tn_chai_adda')->where('status', 'free')->first();
        $chai = $this->item('Masala Chai');
        $o = $this->api('POST', '/pos/orders', ['type' => 'dine_in', 'table_id' => $table->id], 'captain')['data'];
        $this->api('POST', "/orders/{$o['id']}/items", ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'version' => $o['updated_at']], 'captain');
        usleep(5000);
        $this->api('POST', "/orders/{$o['id']}/items", ['lines' => [['item_id' => $chai['id'], 'qty' => 1]], 'version' => $o['updated_at']], 'captain', 'chai-adda', null, 409);
    }

    public function test_wc_05_day_closes_once(): void
    {
        $this->staff('cashier');
        $this->api('POST', '/shifts/open', ['opening_cash' => 200000], 'cashier');
        $p = $this->api('GET', '/day-close/preview', [], 'cashier')['data'];
        $counts = [['denomination' => 1, 'count' => intdiv($p['expected_cash'], 100)]];
        $this->api('POST', '/day-close', ['counts' => $counts, 'variance_reason' => 'paise'], 'cashier');
        $this->api('POST', '/day-close', ['counts' => $counts, 'variance_reason' => 'paise'], 'cashier', 'chai-adda', null, 409);
    }

    public function test_wc_06_stock_can_go_negative(): void
    {
        $this->staff('cashier');
        $this->api('POST', '/shifts/open', ['opening_cash' => 0], 'cashier');
        $milk = DB::table('raw_materials')->where('tenant_id', 'tn_chai_adda')->where('name', 'Milk')->first();
        DB::table('raw_materials')->where('id', $milk->id)->update(['stock' => 0.1]);
        $o = $this->api('POST', '/pos/orders', ['type' => 'takeaway', 'lines' => [['item_id' => $this->item('Masala Chai')['id'], 'qty' => 5]]], 'cashier')['data'];
        $this->api('POST', "/orders/{$o['id']}/settle", ['payments' => [['mode' => 'cash', 'amount' => $o['total']]]], 'cashier');
        $this->assertEqualsWithDelta(-0.5, (float) $this->row('raw_materials', ['id' => $milk->id])->stock, 0.001);
    }

    public function test_wc_07_first_rider_wins(): void
    {
        $this->staff('owner');
        $d = DB::table('deliveries')->where('tenant_id', 'tn_chai_adda')->first();
        DB::table('deliveries')->where('id', $d->id)->update(['status' => 'unassigned', 'rider_id' => null]);
        $riders = DB::table('riders')->where('tenant_id', 'tn_chai_adda')->get();
        $this->api('POST', "/deliveries/{$d->id}/assign", ['rider_ids' => $riders->pluck('id')->all()], 'owner');
        $t1 = $this->api('POST', '/auth/rider', ['phone' => $riders[0]->phone, 'otp' => '123456'])['data']['token'];
        $t2 = $this->api('POST', '/auth/rider', ['phone' => $riders[1]->phone, 'otp' => '123456'])['data']['token'];
        $this->tokens['r1'] = $t1;
        $this->tokens['r2'] = $t2;
        $this->api('POST', "/deliveries/{$d->id}/accept", [], 'r1');
        $this->assertSame('already_taken', $this->api('POST', "/deliveries/{$d->id}/accept", [], 'r2', 'chai-adda', null, 409)['code']);
    }

    public function test_wc_08_bad_import_saves_nothing(): void
    {
        $this->staff('owner');
        $before = DB::table('items')->count();
        $this->api('POST', '/menu/import', ['rows' => [['name' => 'Tea', 'category' => 'Chai', 'price' => '20'], ['name' => 'Bad', 'category' => 'Chai', 'price' => 'abc']]], 'owner', 'chai-adda', null, 422);
        $this->assertSame($before, DB::table('items')->count());
    }

    public function test_wc_09_price_change_audited_and_owner_only(): void
    {
        $this->staff('owner');
        $chai = $this->item('Masala Chai');
        $this->api('PUT', "/items/{$chai['id']}", ['price' => $chai['price'] + 1000], 'owner');
        $this->assertNotNull($this->row('audit_logs', ['action' => 'price_change', 'entity_id' => $chai['id']]));
        $this->staff('manager');
        $this->api('PUT', "/items/{$chai['id']}", ['price' => 1], 'manager', 'chai-adda', null, 403);
    }

    public function test_apr_01_discount_above_limit_needs_manager_pin(): void
    {
        DB::table('offers')->update(['active' => false]); // no happy hour: the result must not depend on the time of day
        $this->staff('cashier');
        $this->api('POST', '/shifts/open', ['opening_cash' => 0], 'cashier');
        $o = $this->api('POST', '/pos/orders', ['type' => 'takeaway', 'lines' => [['item_id' => $this->item('Kulhad Chai')['id'], 'qty' => 2]]], 'cashier')['data'];
        $body = ['discount' => ['kind' => 'pct', 'value' => 20, 'reason' => 'Regular'], 'payments' => [['mode' => 'cash', 'amount' => $o['total']]]];
        $this->assertSame('approval_required', $this->api('POST', "/orders/{$o['id']}/settle", $body, 'cashier', 'chai-adda', null, 403)['code']);
        $ap = $this->api('POST', '/auth/approve', ['pin' => '2222', 'kind' => 'discount_pct', 'amount' => 20], 'cashier')['data'];
        $r = $this->api('POST', "/orders/{$o['id']}/settle", $body + ['approver_id' => $ap['approver_id']], 'cashier')['data'];
        $this->assertSame((int) round(12000 * 0.2), $r['invoice']['discount']);
    }

    public function test_back_office_is_owner_and_manager_only(): void
    {
        $this->staff('cashier');
        $cat = DB::table('expense_categories')->where('tenant_id', 'tn_chai_adda')->first();
        $this->api('POST', '/expenses', ['category_id' => $cat->id, 'amount' => 10000, 'mode' => 'cash'], 'cashier', 'chai-adda', null, 403);
        $this->staff('storekeeper');
        $this->api('GET', '/raw-materials', [], 'storekeeper', 'chai-adda', null, 403);
        $this->staff('manager');
        $this->api('GET', '/raw-materials', [], 'manager');
        $this->api('GET', '/salary-sheet', [], 'manager');
    }

    public function test_marketing_budget_stops_coupons_when_enforced(): void
    {
        $this->staff('owner');
        $month = substr(now('Asia/Kolkata')->toDateString(), 0, 7);
        $this->api('PUT', '/marketing/budget', ['month' => $month, 'amount' => 100, 'enforce' => true], 'owner');
        $q = $this->api('POST', '/cart/quote', ['lines' => [['item_id' => $this->item('Kulhad Chai')['id'], 'qty' => 4]], 'order_type' => 'takeaway', 'coupon_code' => 'CHAI10'])['data'];
        $this->assertSame("This month's offer budget is used up", $q['coupon_error']);
        $this->assertSame(0, $q['quote']['coupon_discount']);
    }

    public function test_reports_dashboard_and_pnl(): void
    {
        $this->staff('owner');
        $d = $this->api('GET', '/reports/dashboard?range=30d', [], 'owner')['data'];
        $this->assertGreaterThan(0, $d['orders']);
        $this->assertArrayHasKey('marketing_budget', $d);
        $p = $this->api('GET', '/reports/pnl?range=30d', [], 'owner')['data'];
        $this->assertSame($p['gross_profit'] - $p['labour'] - $p['expenses_total'], $p['net_profit']);
    }

    public function test_control_panel_needs_owner_permission_for_support_login(): void
    {
        $this->tokens['control'] = $this->api('POST', '/control/auth', ['email' => 'admin@cafechalao.in', 'otp' => '123456'])['data']['token'];
        $this->assertCount(4, $this->api('GET', '/control/tenants', [], 'control')['data']);
        $this->api('POST', '/control/tenants/tn_chai_adda/support-login', [], 'control', 'chai-adda', null, 403);
    }
    public function test_staff_sign_in_by_employee_id_and_new_owner_sets_own_pin(): void
    {
        $this->api('GET', '/staff/directory', [], null, 'chai-adda', null, 404); // no public list of staff names
        $this->tokens['control'] = $this->api('POST', '/control/auth', ['email' => 'admin@cafechalao.in', 'otp' => '123456'])['data']['token'];
        $new = $this->api('POST', '/control/tenants', ['name' => 'Café 1BHK', 'slug' => 'test-1bhk', 'owner_name' => 'Rohan', 'owner_phone' => '9876543210', 'owner_email' => 'rohan@1bhk.in', 'city' => 'Guwahati', 'business_type' => 'cafe'], 'control')['data'];
        $this->assertSame('E001', $new['owner_code']);
        $this->assertArrayNotHasKey('owner_pin', $new);

        $this->api('POST', '/auth/pin', ['employee_code' => 'E001', 'pin' => '1111'], null, 'test-1bhk', null, 409); // no default PIN
        $this->assertSame('••••••3210', $this->api('POST', '/auth/pin/code', ['employee_code' => 'e001'], null, 'test-1bhk')['data']['phone_hint']);
        $this->api('POST', '/auth/pin/setup', ['employee_code' => 'E001', 'otp' => '000000', 'pin' => '4826'], null, 'test-1bhk', null, 422);
        $this->api('POST', '/auth/pin/setup', ['employee_code' => 'E001', 'otp' => '123456', 'pin' => '4826'], null, 'test-1bhk');
        $this->api('POST', '/auth/pin/setup', ['employee_code' => 'E001', 'otp' => '123456', 'pin' => '9999'], null, 'test-1bhk', null, 422); // only once
        $this->assertSame('owner', $this->api('POST', '/auth/pin', ['employee_code' => 'E001', 'pin' => '4826'], null, 'test-1bhk')['data']['user']['role']);
        $this->api('POST', '/auth/pin', ['employee_code' => 'E001', 'pin' => '4826'], null, 'chai-adda', null, 422); // IDs are per café

        $this->assertSame('1BHK/', \Illuminate\Support\Facades\DB::table('tax_settings')->where('tenant_id', $new['id'])->value('invoice_prefix'));
    }
}
