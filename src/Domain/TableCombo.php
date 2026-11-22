<?php

declare(strict_types=1);

namespace App\Domain;

/** Dovoljena kombinacija miz z ročno podano kapaciteto. */
final class TableCombo
{
    /** @param list<int> $tableIds */
    public function __construct(
        public readonly int $id,
        public readonly int $maxSeats,
        public readonly array $tableIds,
    ) {
    }
}
