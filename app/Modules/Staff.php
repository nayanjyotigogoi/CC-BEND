<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Approvals;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Media;

/** Staff (M7): profiles with role and hashed PIN, attendance by PIN + selfie, advances, salary sheet, staff sales. */
class Staff
{
    private static function row(array $u): array
    {
        unset($u['pin_hash']);
        return $u + ['profile' => Db::first('staff_profiles', fn ($q) => $q->where('user_id', $u['id']))];
    }

    /** A PIN must be 4–6 digits and not used by another active staff member (PINs are stored hashed). */
    public static function checkPin(string $pin, ?string $exceptId = null): void
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) throw HttpError::invalid(['pin' => 'PIN must be 4 to 6 digits']);
        foreach (Db::rows('users', fn ($q) => $q->where('active', 1)) as $u) {
            if ($u['id'] !== $exceptId && password_verify($pin, (string) $u['pin_hash'])) throw HttpError::invalid(['pin' => 'This PIN is used by someone else. Pick another.']);
        }
    }

    /** Next employee ID for this café: E001, E002, … Shown on the staff list and typed at login. */
    public static function nextCode(): string
    {
        $n = 0; // removed staff count too, so an ID is never given to someone else
        foreach (Db::raw('users')->where('tenant_id', ctx()->tenantId())->pluck('employee_code') as $c) $n = max($n, (int) substr((string) $c, 1));
        return 'E' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Gives staff without an employee ID one (per café: owner first, then in the order they were added). */
    public static function backfillCodes(): void
    {
        $users = \Illuminate\Support\Facades\DB::table('users')->orderByRaw("role = 'owner' desc")->orderBy('created_at')->get(['id', 'tenant_id', 'employee_code']);
        $next = [];
        foreach ($users as $u) $next[$u->tenant_id] = max($next[$u->tenant_id] ?? 0, (int) substr((string) $u->employee_code, 1));
        foreach ($users as $u) {
            if ($u->employee_code) continue;
            $next[$u->tenant_id]++;
            \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update(['employee_code' => 'E' . str_pad((string) $next[$u->tenant_id], 3, '0', STR_PAD_LEFT)]);
        }
    }

    public static function userByPin(string $pin): ?array
    {
        return first_where(Db::rows('users', fn ($q) => $q->where('active', 1)), fn ($u) => password_verify($pin, (string) $u['pin_hash']));
    }

    public static function sheet(string $month): array
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);
        $att = Db::rows('attendance', fn ($q) => $q->where('date', 'like', "$month%"));
        $slips = Db::rows('salary_slips', fn ($q) => $q->where('month', $month));
        return array_map(function ($u) use ($att, $slips, $days, $month) {
            $p = Db::first('staff_profiles', fn ($q) => $q->where('user_id', $u['id']));
            $mine = where_eq($att, 'user_id', $u['id']);
            $present = count(where_eq($mine, 'status', 'present')) + count(where_eq($mine, 'status', 'half_day')) * 0.5;
            $offs = count(where_eq($mine, 'status', 'weekly_off'));
            $ot = sum_by($mine, 'overtime_min');
            $daily = $p ? ($p['salary_type'] === 'monthly' ? $p['salary_amount'] / $days : $p['salary_amount']) : 0;
            $gross = (int) round(($p['salary_type'] ?? 'monthly') === 'monthly' ? $daily * ($present + $offs) : $daily * $present);
            $otPay = (int) round($daily / 8 * $ot / 60 * 1.5);
            $adv = sum_by(Db::rows('advances', fn ($q) => $q->where('user_id', $u['id'])->where('recovered', 0)), 'amount');
            $rider = Db::first('riders', fn ($q) => $q->where('user_id', $u['id'])->where('type', 'own'));
            // In-house riders get delivery charges on salary day (M8.6).
            $del = $rider ? sum_by(Db::rows('deliveries', fn ($q) => $q->where('rider_id', $rider['id'])->where('status', 'delivered')->where('delivered_at', 'like', "$month%")), 'charge') : 0;
            $slip = first_where($slips, fn ($s) => $s['user_id'] === $u['id']);
            return ['user_id' => $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'salary_type' => $p['salary_type'] ?? null, 'salary_amount' => $p['salary_amount'] ?? 0, 'days_present' => $present, 'weekly_offs' => $offs,
                'overtime_min' => $ot, 'gross' => $gross, 'overtime_pay' => $otPay, 'advances' => $adv, 'deductions' => 0, 'delivery_earnings' => $del, 'net' => $gross + $otPay + $del - $adv, 'paid' => (bool) ($slip['paid'] ?? false), 'slip_id' => $slip['id'] ?? null];
        }, Db::rows('users', fn ($q) => $q->where('role', '!=', 'owner')));
    }

    public static function routes(): void
    {
        Api::get('/staff', fn () => array_map([self::class, 'row'], Db::rows('users', null, 'created_at')), 'staff', 'staff.manage');

        Api::post('/staff', function (Req $r) {
            need($r->body, ['name', 'role', 'pin', 'phone']);
            self::checkPin((string) $r->b('pin'));
            if ($r->b('role') === 'owner' && ctx()->user['role'] !== 'owner') throw HttpError::forbidden('Only the owner can add another owner');
            $u = Db::create('users', ['employee_code' => self::nextCode(), 'name' => $r->b('name'), 'role' => $r->b('role'), 'phone' => $r->b('phone'), 'email' => $r->b('email'), 'pin_hash' => password_hash((string) $r->b('pin'), PASSWORD_BCRYPT), 'active' => true, 'avatar_color' => $r->b('avatar_color', '#5E6B33')], 'us');
            Db::create('staff_profiles', ['user_id' => $u['id'], 'salary_type' => $r->b('salary_type', 'monthly'), 'salary_amount' => (int) $r->b('salary_amount', 0), 'weekly_off' => (int) $r->b('weekly_off', 1),
                'shift_start' => $r->b('shift_start', '09:00'), 'shift_end' => $r->b('shift_end', '18:00'), 'id_document' => $r->b('id_document'), 'joined_on' => $r->b('joined_on', ctx()->today())], 'sp');
            Audit::log('create', 'staff', $u['id'], null, ['name' => $u['name'], 'role' => $u['role']]);
            return self::row($u);
        }, 'staff', 'staff.manage');

        Api::put('/staff/:id', function (Req $r) {
            $u = Db::find('users', $r->p('id'), 'Staff');
            if ($u['role'] === 'owner' && ctx()->user['role'] !== 'owner') throw HttpError::forbidden();
            $patch = array_intersect_key($r->body, array_flip(['name', 'role', 'phone', 'email', 'active', 'avatar_color']));
            if ($pin = $r->b('pin')) {
                self::checkPin((string) $pin, $u['id']);
                $patch['pin_hash'] = password_hash((string) $pin, PASSWORD_BCRYPT);
            }
            Db::update('users', $u['id'], $patch);
            $p = Db::first('staff_profiles', fn ($q) => $q->where('user_id', $u['id']));
            if ($prof = $r->b('profile')) {
                if (array_key_exists('salary_amount', $prof) && !ctx()->can('staff.salary')) throw HttpError::forbidden('Only the owner can change salaries');
                $p ? Db::update('staff_profiles', $p['id'], $prof) : Db::create('staff_profiles', $prof + ['user_id' => $u['id']], 'sp');
            }
            Audit::log('update', 'staff', $u['id'], ['role' => $u['role'], 'active' => $u['active']], array_merge(array_diff_key($patch, ['pin_hash' => 1]), $pin ? ['pin' => 'changed'] : []));
            return self::row(Db::find('users', $u['id']));
        }, 'staff', 'staff.manage');

        /** PIN clock-in/out on the café device with a selfie (M7.2). */
        Api::post('/attendance/clock', function (Req $r) {
            need($r->body, ['pin']);
            $u = self::userByPin((string) $r->b('pin')) ?? throw HttpError::invalid(['pin' => 'Wrong PIN']);
            $selfie = $r->b('selfie') ? Media::storeDataUrl($r->b('selfie'), 'selfies') : null;
            $d = ctx()->today();
            $row = Db::first('attendance', fn ($q) => $q->where('user_id', $u['id'])->where('date', $d));
            if (!$row) return ['action' => 'in', 'name' => $u['name'], 'attendance' => Db::create('attendance', ['user_id' => $u['id'], 'date' => $d, 'clock_in' => ctx()->iso(), 'selfie_url' => $selfie, 'status' => 'present', 'overtime_min' => 0], 'at')];
            if ($row['clock_out']) throw HttpError::invalid(['pin' => "{$u['name']} already clocked out today"]);
            if (!$row['clock_in']) return ['action' => 'in', 'name' => $u['name'], 'attendance' => Db::update('attendance', $row['id'], ['clock_in' => ctx()->iso(), 'status' => 'present', 'selfie_url' => $selfie ?? $row['selfie_url']])];
            $p = Db::first('staff_profiles', fn ($q) => $q->where('user_id', $u['id']));
            $end = new \DateTimeImmutable($d . ' ' . ($p['shift_end'] ?? '23:00'), new \DateTimeZone(config('cafechalao.timezone')));
            $ot = max(0, (int) round((ctx()->now()->getTimestamp() - $end->getTimestamp()) / 60));
            return ['action' => 'out', 'name' => $u['name'], 'attendance' => Db::update('attendance', $row['id'], ['clock_out' => ctx()->iso(), 'overtime_min' => $ot >= 15 ? $ot : 0])];
        }, 'public');

        Api::get('/attendance', fn (Req $r) => Db::rows('attendance', fn ($q) => $q->where('date', 'like', $r->q('month', substr(ctx()->today(), 0, 7)) . '%')->when($r->q('user_id'), fn ($x, $v) => $x->where('user_id', $v))), 'staff', 'staff.manage');

        Api::post('/attendance/:id/correct', function (Req $r) {
            $a = Db::find('attendance', $r->p('id'), 'Attendance');
            need($r->body, ['patch', 'reason']);
            Db::update('attendance', $a['id'], ['correction_note' => json_encode(['patch' => $r->b('patch'), 'reason' => $r->b('reason')])]);
            $name = Db::find('users', $a['user_id'])['name'];
            Approvals::queue('attendance', $a['id'], 0, "Attendance correction · $name · {$a['date']}: {$r->b('reason')}", ctx()->user['role'] === 'manager' ? 'owner' : 'manager');
            return ['ok' => true];
        }, 'staff', 'staff.manage');

        Api::get('/advances', fn () => Db::rows('advances', null, 'given_on', 'desc'), 'staff', 'staff.salary');
        Api::post('/advances', function (Req $r) {
            need($r->body, ['user_id', 'amount']);
            return Db::create('advances', ['user_id' => $r->b('user_id'), 'amount' => (int) $r->b('amount'), 'given_on' => $r->b('given_on', ctx()->today()), 'note' => (string) $r->b('note', ''), 'recovered' => false], 'av');
        }, 'staff', 'staff.salary');

        Api::get('/salary-sheet', fn (Req $r) => self::sheet($r->q('month', substr(ctx()->today(), 0, 7))), 'staff', 'staff.salary');
        Api::post('/salary-sheet/pay', function (Req $r) {
            need($r->body, ['month', 'user_id']);
            $row = first_where(self::sheet($r->b('month')), fn ($x) => $x['user_id'] === $r->b('user_id')) ?? throw HttpError::invalid(['user_id' => 'Not on this sheet']);
            if ($row['paid']) throw HttpError::invalid(['user_id' => 'Already paid']);
            $ded = (int) $r->b('deductions', 0);
            $slip = Db::create('salary_slips', ['user_id' => $row['user_id'], 'month' => $r->b('month'), 'days_present' => $row['days_present'], 'overtime_pay' => $row['overtime_pay'], 'gross' => $row['gross'], 'advances' => $row['advances'], 'deductions' => $ded, 'delivery_earnings' => $row['delivery_earnings'], 'net' => $row['net'] - $ded, 'paid' => true], 'ss');
            Db::q('advances')->where('user_id', $row['user_id'])->where('recovered', 0)->update(['recovered' => 1]);
            if ($cat = Db::first('expense_categories', fn ($q) => $q->where('name', 'Salary'))) {
                Db::create('expenses', ['category_id' => $cat['id'], 'amount' => $row['net'] - $ded, 'mode' => $r->b('mode', 'bank'), 'spent_on' => ctx()->today(), 'note' => "Salary {$r->b('month')} · {$row['name']}", 'status' => 'approved', 'petty_cash' => false], 'ex');
            }
            Audit::log('salary_paid', 'staff', $row['user_id'], null, ['month' => $r->b('month'), 'net' => $row['net'] - $ded]);
            return $slip;
        }, 'staff', 'staff.salary');

        /** Staff sales and tips (M7.4). */
        Api::get('/reports/staff-sales', function (Req $r) {
            $orders = Db::rows('orders', fn ($q) => $q->where('payment_status', 'paid')->when($r->q('from'), fn ($x, $v) => $x->where('created_at', '>=', Orders::dayStart($v)))->when($r->q('to'), fn ($x, $v) => $x->where('created_at', '<', Orders::dayStart($v, 1))));
            $rows = array_map(function ($u) use ($orders) {
                $mine = array_filter($orders, fn ($o) => $o['captain_id'] === $u['id'] || (!$o['captain_id'] && $o['cashier_id'] === $u['id']));
                $sales = sum_by($mine, 'total');
                return ['user_id' => $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'orders' => count($mine), 'sales' => $sales, 'tips' => sum_by($mine, 'tip'), 'average' => $mine ? (int) round($sales / count($mine)) : 0];
            }, Db::rows('users', fn ($q) => $q->whereIn('role', ['captain', 'cashier', 'manager'])));
            usort($rows, fn ($a, $b) => $b['sales'] <=> $a['sales']);
            return $rows;
        }, 'staff', 'reports.view');
    }
}
