<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Eno okno urnika na dan v tednu, v lokalnem času.
 *
 * Omejitev v1: last_seating mora biti na isti dan kot opens_at (>= opens_at).
 * closes_at sme biti po polnoči; rezervacija se lahko konča po zaprtju,
 * začne pa ne po last_seating.
 */
final class OpeningWindow
{
    public function __construct(
        public readonly int $weekday,      // 1 = ponedeljek ... 7 = nedelja
        public readonly string $opensAt,   // 'H:i'
        public readonly string $lastSeating,
        public readonly string $closesAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['weekday'],
            substr((string) $r['opens_at'], 0, 5),
            substr((string) $r['last_seating'], 0, 5),
            substr((string) $r['closes_at'], 0, 5),
        );
    }

    public function allowsStart(string $hhmm): bool
    {
        return $hhmm >= $this->opensAt && $hhmm <= $this->lastSeating;
    }
}
