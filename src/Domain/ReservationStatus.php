<?php

declare(strict_types=1);

namespace App\Domain;

enum ReservationStatus: string
{
    case Confirmed = 'confirmed';
    case Arrived   = 'arrived';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow    = 'no_show';

    /** Samo ta stanja držijo mizo. Ob prehodu iz njih se rezine sprostijo. */
    public function holdsTable(): bool
    {
        return $this === self::Confirmed || $this === self::Arrived;
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Confirmed => in_array($to, [self::Arrived, self::Cancelled, self::NoShow], true),
            self::Arrived   => $to === self::Completed,
            default         => false,
        };
    }
}
