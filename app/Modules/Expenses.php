<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Approvals;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Media;

/** Expenses and budget (M6): categories, expenses and petty cash with bill photo, approval by limit, budget vs actual. */
class Expenses
{
    public static function budgetRows(string $month): array
    {
        $budgets = Db::rows('budgets', fn ($q) => $q->where('month', $month));
        $exp = Db::rows('expenses', fn ($q) => $q->where('spent_on', 'like', "$month%")->where('status', 'approved'));
        return array_map(function ($c) use ($budgets, $exp, $month) {
            $budget = first_where($budgets, fn ($b) => $b['category_id'] === $c['id'])['amount'] ?? $c['monthly_budget'];
            $actual = sum_by(where_eq($exp, 'category_id', $c['id']), 'amount');
            return ['category_id' => $c['id'], 'category' => $c['name'], 'month' => $month, 'budget' => $budget, 'actual' => $actual, 'variance' => $actual - $budget, 'variance_pct' => $budget ? ($actual - $budget) / $budget * 100 : null];
        }, Db::rows('expense_categories'));
    }

    public static function routes(): void
    {
        Api::crud('/expense-categories', 'expense_categories', 'ec', ['perm' => 'settings.manage', 'readPerm' => 'expenses.create', 'required' => ['name'], 'defaults' => ['monthly_budget' => 0]]);

        Api::get('/expenses', function (Req $r) {
            $cats = array_column(Db::rows('expense_categories'), 'name', 'id');
            return array_map(fn ($e) => $e + ['category' => $cats[$e['category_id']] ?? null], Db::rows('expenses', function ($q) use ($r) {
                if ($m = $r->q('month')) $q->where('spent_on', 'like', "$m%");
                if ($r->q('petty')) $q->where('petty_cash', 1);
            }, 'spent_on', 'desc'));
        }, 'staff', 'expenses.create');

        /** Within the user's expense limit it is approved; above it goes to the manager or owner. */
        Api::post('/expenses', function (Req $r) {
            need($r->body, ['category_id', 'amount', 'mode']);
            $amount = (int) round($r->b('amount'));
            if ($amount <= 0) throw HttpError::invalid(['amount' => 'Enter an amount']);
            $me = ctx()->user;
            $auto = $amount <= Approvals::limits($me['role'])['expense_value'];
            $data = Media::storeFields($r->body, ['bill_photo_url'], 'bills');
            $e = Db::create('expenses', ['category_id' => $data['category_id'], 'amount' => $amount, 'mode' => $data['mode'], 'spent_on' => $data['spent_on'] ?? ctx()->today(), 'note' => (string) ($data['note'] ?? ''),
                'bill_photo_url' => $data['bill_photo_url'] ?? null, 'status' => $auto ? 'approved' : 'pending', 'petty_cash' => !empty($data['petty_cash'])], 'ex');
            if (!$auto) {
                $role = $me['role'] !== 'manager' && $amount <= Approvals::limits('manager')['expense_value'] ? 'manager' : 'owner';
                Approvals::queue('expense', $e['id'], $amount, 'Expense · ' . ($e['note'] ?: 'no note'), $role);
            }
            return $e;
        }, 'staff', 'expenses.create');

        Api::delete('/expenses/:id', function (Req $r) {
            $e = Db::find('expenses', $r->p('id'), 'Expense');
            if ($e['status'] === 'approved' && !ctx()->can('expenses.approve')) throw HttpError::forbidden('Approved expenses can only be removed by a manager');
            Db::remove('expenses', $e['id']);
            Audit::log('delete', 'expense', $e['id'], $e, null);
            return ['deleted' => true];
        }, 'staff', 'expenses.create');

        Api::get('/budgets', fn (Req $r) => self::budgetRows($r->q('month', substr(ctx()->today(), 0, 7))), 'staff', 'expenses.create');
        Api::put('/budgets', function (Req $r) {
            need($r->body, ['month', 'category_id', 'amount']);
            $cur = Db::first('budgets', fn ($q) => $q->where('month', $r->b('month'))->where('category_id', $r->b('category_id')));
            return $cur ? Db::update('budgets', $cur['id'], ['amount' => (int) $r->b('amount')]) : Db::create('budgets', ['category_id' => $r->b('category_id'), 'month' => $r->b('month'), 'amount' => (int) $r->b('amount')], 'bg');
        }, 'staff', 'expenses.approve');
    }
}
