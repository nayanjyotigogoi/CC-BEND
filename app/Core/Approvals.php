<?php

namespace App\Core;

/**
 * Approval engine (SRS 2, Core/Approvals): limits per role, the "higher-role PIN on the spot" check,
 * and the shared queue used by purchase requests, POs, expenses and attendance corrections.
 */
class Approvals
{
    public static function limits(string $role): array
    {
        return Db::first('approval_limits', fn ($q) => $q->where('role', $role))
            ?? ['role' => $role, 'discount_pct' => 0, 'void_value' => 0, 'refund_value' => 0, 'expense_value' => 0, 'po_value' => 0];
    }

    public static function rank(string $role): int
    {
        return config('cafechalao.role_rank')[$role] ?? 0;
    }

    /** Returns the approver when one was needed; throws approval_required when the user is over the limit. */
    public static function check(string $kind, float $amount, ?string $approverId): ?array
    {
        $me = ctx()->user;
        if ($amount <= self::limits($me['role'])[$kind]) return null;
        if (!$approverId) throw new HttpError(403, 'Above your limit: a manager PIN is needed', 'approval_required', ['kind' => $kind, 'amount' => (string) $amount]);
        $a = Db::first('users', fn ($q) => $q->where('id', $approverId)->where('active', 1));
        if (!$a || self::rank($a['role']) <= self::rank($me['role']) || $amount > self::limits($a['role'])[$kind]) {
            throw new HttpError(403, "That approver's limit doesn't cover this amount", 'approval_required');
        }
        return $a;
    }

    /** Lowest role whose limit covers the amount: manager, else owner. */
    public static function roleFor(string $kind, int $amount): string
    {
        return $amount <= self::limits('manager')[$kind] ? 'manager' : 'owner';
    }

    public static function queue(string $kind, string $refId, int $amount, string $summary, string $role): array
    {
        $a = Db::create('approvals', ['kind' => $kind, 'ref_id' => $refId, 'amount' => $amount, 'summary' => $summary, 'requested_by' => ctx()->user['id'] ?? null, 'status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'required_role' => $role], 'ap');
        Audit::notify($role, 'Approval needed', $summary . ($amount ? ' · ' . rupees($amount) : ''), '/admin/approvals');
        return $a;
    }
}
