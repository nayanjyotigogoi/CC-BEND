<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Approvals;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Core\Tenancy;
use App\Shared\Net;

/**
 * Logins: customers by email (account created on first use) or Google (when GOOGLE_CLIENT_ID is set),
 * phone OTP fallback; staff by employee ID + PIN; riders by phone OTP; Control Panel by email + code.
 */
class Auth
{
    public static function needsProfile(array $c): bool
    {
        return !$c['phone_verified']; // birthday is optional; a verified mobile is what orders need
    }

    private static function norm(?string $p): string
    {
        return substr(preg_replace('/\D/', '', (string) $p), -10);
    }

    private static function checkOtp(?string $otp): void
    {
        // Demo: fixed code. With an SMS provider the sent code is checked here.
        if ((string) $otp !== (string) config('cafechalao.demo_otp')) throw HttpError::invalid(['otp' => 'That code is not right.']);
    }

    /** Finds the café's customer by Google id or email, or creates one. Each café has its own customer rows. */
    private static function customerFor(string $email, string $name, ?string $googleId): array
    {
        $email = strtolower(trim($email));
        $c = Db::first('customers', fn ($q) => $q->where(fn ($w) => $w->where('email', $email)->when($googleId, fn ($x) => $x->orWhere('google_id', $googleId))));
        if (!$c) {
            $c = Db::create('customers', [
                'name' => $name ?: ucfirst(strstr($email, '@', true)), 'email' => $email, 'google_id' => $googleId, 'phone' => null, 'phone_verified' => false,
                'source' => 'app', 'marketing_consent' => false, 'tags' => ['new'], 'loyalty_points' => 0, 'total_spend' => 0, 'visits' => 0,
            ], 'cu');
        } elseif ($googleId && !$c['google_id']) {
            $c = Db::update('customers', $c['id'], ['google_id' => $googleId]);
        }
        return ['token' => Tenancy::issue('customer', $c['id']), 'customer' => $c, 'needs_profile' => self::needsProfile($c)];
    }

    private static function staffByCode(?string $code): ?array
    {
        $code = strtoupper(trim((string) $code));
        return $code === '' ? null : Db::first('users', fn ($q) => $q->where('employee_code', $code)->where('active', 1)->where('role', '!=', 'rider'));
    }

    private static function phoneHint(array $u): ?string
    {
        $d = self::norm($u['phone']);
        return $d ? '••••••' . substr($d, -4) : null;
    }

    public static function staffSession(string $userId): array
    {
        $u = Db::find('users', $userId);
        unset($u['pin_hash']);
        return ['user' => $u, 'permissions' => Db::first('roles', fn ($q) => $q->where('key', $u['role']))['permissions'] ?? [], 'limits' => Approvals::limits($u['role'])];
    }

    public static function routes(): void
    {
        /** Email login: enter the email and you're in; the account is created the first time. */
        Api::post('/auth/email', function (Req $r) {
            need($r->body, ['email']);
            if (!filter_var($r->b('email'), FILTER_VALIDATE_EMAIL)) throw HttpError::invalid(['email' => 'Enter a valid email']);
            return self::customerFor($r->b('email'), (string) $r->b('name', ''), null);
        }, 'public');

        /** Google sign-in. With GOOGLE_CLIENT_ID set, the ID token from Google Identity Services is verified. */
        Api::post('/auth/google', function (Req $r) {
            $clientId = config('cafechalao.google_client_id');
            if ($r->b('credential')) {
                if (!$clientId) throw HttpError::invalid(['credential' => 'Google sign-in is not configured. Use email login.']);
                $info = Net::http(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $r->b('credential')])->json();
                if (($info['aud'] ?? null) !== $clientId || empty($info['email'])) throw HttpError::invalid(['credential' => 'Google sign-in failed. Try again.']);
                return self::customerFor($info['email'], $info['name'] ?? '', $info['sub']);
            }
            // Demo mode keeps the "Continue with Google (demo)" button working without a client id.
            if (!config('cafechalao.demo_mode')) throw HttpError::invalid(['credential' => 'Missing Google credential']);
            need($r->body, ['email', 'name']);
            return self::customerFor($r->b('email'), $r->b('name'), $r->b('google_id') ?? 'g_' . $r->b('email'));
        }, 'public');

        Api::get('/auth/config', fn () => ['google_client_id' => config('cafechalao.google_client_id') ?: null, 'email_login' => true, 'demo' => (bool) config('cafechalao.demo_mode')], 'public');

        Api::post('/auth/otp/send', function (Req $r) {
            need($r->body, ['phone']);
            if (strlen(self::norm($r->b('phone'))) !== 10) throw HttpError::invalid(['phone' => 'Enter a 10-digit mobile number']);
            return ['sent' => true] + (config('cafechalao.demo_mode') ? ['demo_otp' => config('cafechalao.demo_otp')] : []);
        }, 'public');

        /** Verify the mobile; a walk-in profile with the same mobile is claimed by this account (M1.2). */
        Api::post('/auth/otp/verify', function (Req $r) {
            need($r->body, ['phone', 'otp']);
            self::checkOtp($r->b('otp'));
            $me = ctx()->customer;
            $phone = $r->b('phone');
            $walk = first_where(Db::rows('customers', fn ($q) => $q->where('id', '!=', $me['id'])->whereNull('google_id')), fn ($c) => $c['phone'] && self::norm($c['phone']) === self::norm($phone) && !$c['email']);
            if ($walk) {
                $claimed = Db::update('customers', $walk['id'], ['google_id' => $me['google_id'], 'email' => $me['email'] ?? $walk['email'], 'phone_verified' => true]);
                Db::update('customers', $me['id'], ['deleted_at' => ctx()->iso(), 'email' => null, 'google_id' => null]);
                Audit::log('claim_profile', 'customer', $walk['id'], null, ['from' => $me['id']]);
                return ['token' => Tenancy::issue('customer', $claimed['id']), 'customer' => $claimed, 'claimed' => true, 'needs_profile' => self::needsProfile($claimed)];
            }
            $c = Db::update('customers', $me['id'], ['phone' => $phone, 'phone_verified' => true]);
            return ['token' => null, 'customer' => $c, 'claimed' => false, 'needs_profile' => self::needsProfile($c)];
        }, 'customer');

        Api::post('/auth/phone', function (Req $r) {
            need($r->body, ['phone', 'otp']);
            self::checkOtp($r->b('otp'));
            $c = first_where(Db::rows('customers', fn ($q) => $q->whereNotNull('phone')), fn ($x) => self::norm($x['phone']) === self::norm($r->b('phone')));
            if (!$c) $c = Db::create('customers', ['name' => $r->b('name', 'Guest'), 'phone' => $r->b('phone'), 'phone_verified' => true, 'source' => 'app', 'marketing_consent' => false, 'tags' => ['new'], 'loyalty_points' => 0, 'total_spend' => 0, 'visits' => 0], 'cu');
            elseif (!$c['phone_verified']) $c = Db::update('customers', $c['id'], ['phone_verified' => true]);
            return ['token' => Tenancy::issue('customer', $c['id']), 'customer' => $c, 'needs_profile' => self::needsProfile($c)];
        }, 'public');

        /* Staff: employee ID + PIN. There is no public staff list; each device remembers who signed in on it. */
        Api::post('/auth/pin', function (Req $r) {
            need($r->body, ['employee_code', 'pin']);
            $u = self::staffByCode($r->b('employee_code'));
            if ($u && !$u['pin_hash']) throw new HttpError(409, 'Set your PIN first.', 'pin_not_set', ['phone_hint' => self::phoneHint($u)]);
            if (!$u || !password_verify((string) $r->b('pin'), (string) $u['pin_hash'])) throw new HttpError(422, 'Employee ID or PIN is not right.', 'wrong_pin', ['pin' => 'Wrong PIN']);
            return ['token' => Tenancy::issue('staff', $u['id'])] + self::staffSession($u['id']);
        }, 'public');

        /** First sign-in (e.g. a new café's owner): confirm with the code sent to their mobile, then choose a PIN. */
        Api::post('/auth/pin/code', function (Req $r) {
            need($r->body, ['employee_code']);
            $u = self::staffByCode($r->b('employee_code'));
            if (!$u || $u['pin_hash']) throw new HttpError(422, 'This employee ID already has a PIN. Ask the owner to reset it if you forgot it.', 'pin_already_set');
            return ['sent' => true, 'phone_hint' => self::phoneHint($u)] + (config('cafechalao.demo_mode') ? ['demo_otp' => config('cafechalao.demo_otp')] : []);
        }, 'public');

        Api::post('/auth/pin/setup', function (Req $r) {
            need($r->body, ['employee_code', 'otp', 'pin']);
            $u = self::staffByCode($r->b('employee_code'));
            if (!$u || $u['pin_hash']) throw new HttpError(422, 'This employee ID already has a PIN.', 'pin_already_set');
            self::checkOtp($r->b('otp'));
            Staff::checkPin((string) $r->b('pin'), $u['id']);
            Db::update('users', $u['id'], ['pin_hash' => password_hash((string) $r->b('pin'), PASSWORD_BCRYPT)]);
            Audit::log('update', 'staff', $u['id'], null, ['pin' => 'set on first sign-in']);
            return ['token' => Tenancy::issue('staff', $u['id'])] + self::staffSession($u['id']);
        }, 'public');

        Api::get('/auth/staff/me', fn () => self::staffSession(ctx()->user['id']));

        /** Higher-role PIN on the spot. */
        Api::post('/auth/approve', function (Req $r) {
            need($r->body, ['pin']);
            $me = ctx()->user;
            $kind = $r->b('kind');
            $amount = (float) $r->b('amount', 0);
            foreach (Db::rows('users', fn ($q) => $q->where('active', 1)) as $u) {
                if (Approvals::rank($u['role']) > Approvals::rank($me['role']) && password_verify((string) $r->b('pin'), (string) $u['pin_hash']) && (!$kind || $amount <= Approvals::limits($u['role'])[$kind])) {
                    return ['approver_id' => $u['id'], 'approver_name' => $u['name'], 'role' => $u['role']];
                }
            }
            throw new HttpError(403, "That PIN can't approve this. Ask a manager or the owner.", 'approval_denied');
        });

        /* Riders */
        Api::post('/auth/rider', function (Req $r) {
            need($r->body, ['phone', 'otp']);
            self::checkOtp($r->b('otp'));
            $rd = first_where(Db::rows('riders', fn ($q) => $q->where('active', 1)), fn ($x) => self::norm($x['phone']) === self::norm($r->b('phone')));
            if (!$rd) throw new HttpError(404, 'This phone is not registered as a rider for this café.', 'not_rider');
            return ['token' => Tenancy::issue('rider', $rd['id']), 'rider' => $rd];
        }, 'public');

        /* Control Panel */
        Api::post('/control/auth', function (Req $r) {
            need($r->body, ['email', 'otp']);
            $a = Db::decode('platform_admins', Db::raw('platform_admins')->where('email', strtolower($r->b('email')))->first());
            if (!$a || (string) $r->b('otp') !== (string) config('cafechalao.demo_otp')) throw new HttpError(422, 'Email or code is not right', 'bad_login');
            return ['token' => Tenancy::issue('control', $a['id']), 'admin' => $a];
        }, 'public', null, true);

        Api::post('/auth/logout', function () {
            $t = request()->bearerToken();
            if ($t) Db::raw('api_tokens')->where('token', hash('sha256', $t))->delete();
            return ['ok' => true];
        }, 'public', null, true);
    }
}
