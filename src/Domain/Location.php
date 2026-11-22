<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeZone;

/** Lokacija restavracije in njena pravila rezervacij (iz baze, ne iz kode). */
final class Location
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly string $timezone,
        public readonly ?string $phonePublic,
        public readonly int $slotMinutes,
        public readonly int $defaultDurationMin,
        public readonly int $maxPartySize,
        public readonly int $minLeadMinutes,
        public readonly int $maxDaysAhead,
        public readonly bool $allowTableMerge,
        public readonly string $phoneCountryCode,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (string) $r['name'],
            (string) $r['timezone'],
            $r['phone_public'] !== null ? (string) $r['phone_public'] : null,
            max(5, (int) $r['slot_minutes']),
            (int) $r['default_duration_min'],
            (int) $r['max_party_size'],
            (int) $r['min_lead_minutes'],
            (int) $r['max_days_ahead'],
            (bool) $r['allow_table_merge'],
            (string) $r['phone_country_code'],
        );
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }
}
