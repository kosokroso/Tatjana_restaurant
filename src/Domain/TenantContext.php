<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Kdo kliče in za katero restavracijo.
 *
 * Nastane samo pri avtentikaciji (seja osebja ali API ključ). tenant_id nikoli
 * ne pride iz telesa zahtevka. Repository brez tega objekta ne obstaja.
 */
final class TenantContext
{
    private function __construct(
        public readonly int $tenantId,
        public readonly string $actorType,      // staff | agent | guest | system
        public readonly ?int $actorId = null,   // staff_users.id ali api_keys.id
        public readonly ?string $role = null,   // staff | manager
        public readonly ?int $locationId = null, // agent je vezan na eno lokacijo
    ) {
    }

    public static function staff(int $tenantId, int $userId, string $role): self
    {
        return new self($tenantId, 'staff', $userId, $role);
    }

    public static function agent(int $tenantId, int $apiKeyId, int $locationId): self
    {
        return new self($tenantId, 'agent', $apiKeyId, null, $locationId);
    }

    public static function guest(int $tenantId): self
    {
        return new self($tenantId, 'guest');
    }

    /** Cron in skripte (opomniki, čiščenje). */
    public static function system(int $tenantId): self
    {
        return new self($tenantId, 'system');
    }

    public function isStaff(): bool
    {
        return $this->actorType === 'staff';
    }

    public function isManager(): bool
    {
        return $this->actorType === 'staff' && $this->role === 'manager';
    }

    /** Vir rezervacije za stolpec reservations.source. */
    public function source(): string
    {
        return match ($this->actorType) {
            'agent' => 'phone',
            'guest' => 'web',
            default => 'staff',
        };
    }
}
