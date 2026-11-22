<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Vir trenutnega časa. Service ga dobi v konstruktorju, da testi lahko
 * zamrznejo čas (min_lead, "že mimo", okno vnaprej).
 */
class Clock
{
    public function __construct(private readonly ?DateTimeImmutable $fixed = null)
    {
    }

    public static function fixed(string $utc): self
    {
        return new self(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
