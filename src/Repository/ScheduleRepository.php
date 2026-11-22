<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ClosurePeriod;
use App\Domain\OpeningWindow;
use App\Domain\TenantContext;
use App\Support\Database;
use DateTimeImmutable;

final class ScheduleRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $ctx,
    ) {
    }

    /** @return list<OpeningWindow> */
    public function windows(int $locationId): array
    {
        return array_map([OpeningWindow::class, 'fromRow'], $this->db->all(
            'SELECT weekday, opens_at, last_seating, closes_at
               FROM opening_hours
              WHERE tenant_id = ? AND location_id = ?
              ORDER BY weekday, opens_at',
            [$this->ctx->tenantId, $locationId]
        ));
    }

    /** @return list<ClosurePeriod> zaprtja, ki se prekrivajo z [from, to) */
    public function closures(int $locationId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        return array_map([ClosurePeriod::class, 'fromRow'], $this->db->all(
            'SELECT starts_at, ends_at, reason
               FROM closures
              WHERE tenant_id = ? AND location_id = ? AND starts_at < ? AND ends_at > ?
              ORDER BY starts_at',
            [$this->ctx->tenantId, $locationId, $toUtc->format('Y-m-d H:i:s'), $fromUtc->format('Y-m-d H:i:s')]
        ));
    }
}
