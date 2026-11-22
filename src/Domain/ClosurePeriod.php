<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** Zaprto obdobje (praznik, zasebna zabava). Časi v UTC. */
final class ClosurePeriod
{
    public function __construct(
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly ?string $reason = null,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $utc = new DateTimeZone('UTC');
        return new self(
            new DateTimeImmutable((string) $r['starts_at'], $utc),
            new DateTimeImmutable((string) $r['ends_at'], $utc),
            $r['reason'] !== null ? (string) $r['reason'] : null,
        );
    }

    public function overlaps(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $start < $this->endsAt && $end > $this->startsAt;
    }
}
