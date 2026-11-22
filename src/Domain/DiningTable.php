<?php

declare(strict_types=1);

namespace App\Domain;

final class DiningTable
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly int $minSeats,
        public readonly int $maxSeats,
        public readonly bool $active = true,
        public readonly ?string $zone = null,
        public readonly int $sortOrder = 0,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (string) $r['label'],
            (int) $r['min_seats'],
            (int) $r['max_seats'],
            (bool) $r['active'],
            $r['zone'] !== null ? (string) $r['zone'] : null,
            (int) $r['sort_order'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'label' => $this->label, 'min_seats' => $this->minSeats,
            'max_seats' => $this->maxSeats, 'zone' => $this->zone, 'active' => $this->active,
        ];
    }
}
