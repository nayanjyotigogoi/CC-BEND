<?php

namespace App\Core;

/**
 * The request context every module reads: the café (tenant), who is signed in, their permissions, and "now".
 * Filled by the ResolveTenant and Authenticate middleware; reached anywhere through ctx().
 */
class Ctx
{
    public ?array $tenant = null;
    public ?array $user = null;      // staff
    public array $perms = [];
    public ?array $customer = null;
    public ?array $rider = null;
    public ?array $admin = null;     // CafeChalao platform admin (L1)
    public ?string $idem = null;
    public string $device = 'Web';
    private ?\DateTimeImmutable $clock = null;

    public function tenantId(): string
    {
        return $this->tenant['id'] ?? throw HttpError::notFound('Café');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function setNow(\DateTimeImmutable $d): void { $this->clock = $d; }

    /** ISO-8601 UTC with milliseconds, the format the frontend compares. */
    public function iso(?\DateTimeImmutable $d = null): string
    {
        return ($d ?? $this->now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Business date in the café's local time (India). */
    public function today(): string
    {
        return $this->now()->setTimezone(new \DateTimeZone(config('cafechalao.timezone')))->format('Y-m-d');
    }

    public function can(string $perm): bool { return in_array($perm, $this->perms, true); }

    public function actorId(): ?string
    {
        return $this->user['id'] ?? $this->customer['id'] ?? $this->rider['id'] ?? null;
    }
}
