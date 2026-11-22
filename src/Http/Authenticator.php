<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\TenantContext;

/**
 * Iz zahtevka ugotovi, kdo kliče. null = ni avtenticiran (401).
 *
 * Seja osebja in API ključ Tatjane sta ločeni implementaciji; Kernel izbere
 * po oznaki poti ('staff' ali 'agent'), nikoli po vsebini zahtevka.
 */
interface Authenticator
{
    public function authenticate(Request $request): ?TenantContext;
}
