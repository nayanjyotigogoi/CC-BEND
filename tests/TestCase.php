<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * API tests run against the real Laravel stack with the demo data (seeded once in tests/bootstrap.php);
 * each test is rolled back so it starts from the same data.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected array $tokens = [];

    /** Calls the API like the frontend does and returns the decoded body; asserts the status. */
    protected function api(string $method, string $path, array $body = [], ?string $as = null, string $tenant = 'chai-adda', ?string $idem = null, int $status = 200): array
    {
        $headers = ['X-Tenant' => $tenant, 'Accept' => 'application/json'];
        if ($as && isset($this->tokens[$as])) $headers['Authorization'] = 'Bearer ' . $this->tokens[$as];
        if ($idem) $headers['Idempotency-Key'] = $idem;
        $res = $this->json($method, "/api/v1$path", $body, $headers);
        $this->assertSame($status, $res->status(), "$method $path → " . substr($res->getContent(), 0, 600));
        return $res->json() ?? [];
    }

    protected function staff(string $role, string $tenant = 'chai-adda'): array
    {
        $pins = ['owner' => '1111', 'manager' => '2222', 'cashier' => '3333', 'receptionist' => '4444', 'captain' => '5555', 'kitchen' => '6666', 'storekeeper' => '7777'];
        $code = \Illuminate\Support\Facades\DB::table('users')->join('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->where('tenants.slug', $tenant)->where('users.role', $role)->where('users.active', 1)->orderBy('users.employee_code')->value('users.employee_code');
        $r = $this->api('POST', '/auth/pin', ['employee_code' => $code, 'pin' => $pins[$role]], null, $tenant)['data'];
        $this->tokens[$role] = $r['token'];
        return $r;
    }

    protected function customer(string $email = 'priya.demo@gmail.com', string $tenant = 'chai-adda'): array
    {
        $r = $this->api('POST', '/auth/email', ['email' => $email], null, $tenant)['data'];
        $this->tokens['customer'] = $r['token'];
        return $r;
    }

    protected function item(string $name, string $tenant = 'chai-adda'): array
    {
        return collect($this->api('GET', '/menu', [], null, $tenant)['data']['items'])->firstWhere('name', $name);
    }

    protected function row(string $table, array $where): ?object
    {
        return DB::table($table)->where($where)->first();
    }
}
