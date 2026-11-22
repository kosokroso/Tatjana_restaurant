<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\TenantContext;

/**
 * Faza 1: prijava osebja in ključi za Tatjano še niso narejeni (faza 2 in 3).
 * Do takrat so vse zaščitene poti zaprte. Zaprto, ne odprto: API brez
 * prijave ne sme nikoli do strežnika, tudi ne "samo za preizkus".
 */
final class DenyAllAuthenticator implements Authenticator
{
    public function authenticate(Request $request): ?TenantContext
    {
        return null;
    }
}
