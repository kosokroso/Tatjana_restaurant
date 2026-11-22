<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AppException;
use App\Domain\ClosurePeriod;
use App\Domain\DiningTable;
use App\Domain\Location;
use App\Domain\OpeningWindow;
use App\Domain\TableCombo;
use App\Support\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pravila razpoložljivosti brez baze.
 *
 * Vse odločitve "ali se sme" in "katera miza" so tu, da se dajo preizkusiti
 * brez MySQL. ReservationService pokliče isti razred znotraj transakcije,
 * ko ima zaklenjeno lokacijo in sveže podatke o zasedenosti.
 *
 * Zasedenost ($busy) je array<int table_id, array<string slot_start_utc, true>>.
 */
final class AvailabilityCalculator
{
    /** Najdaljša rezervacija; več je skoraj gotovo tipkarska napaka. */
    public const MAX_DURATION_MIN = 480;

    private readonly DateTimeZone $tz;
    private readonly DateTimeZone $utc;

    public function __construct(
        private readonly Location $location,
        private readonly Clock $clock,
    ) {
        $this->tz  = $location->tz();
        $this->utc = new DateTimeZone('UTC');
    }

    /** Trajanje, zaokroženo navzgor na celo število rezin. */
    public function durationFor(?int $requested): int
    {
        $slot = $this->location->slotMinutes;
        $min  = $requested ?? $this->location->defaultDurationMin;
        if ($min < $slot || $min > self::MAX_DURATION_MIN) {
            throw AppException::invalid(['duration_min' => "Trajanje mora biti med {$slot} in " . self::MAX_DURATION_MIN . ' minut.']);
        }
        return (int) (ceil($min / $slot) * $slot);
    }

    public function endFor(DateTimeImmutable $startUtc, int $durationMin): DateTimeImmutable
    {
        return $startUtc->add(new DateInterval('PT' . $durationMin . 'M'));
    }

    /**
     * Rezine, ki jih rezervacija zasede: [start, end) v korakih slot_minutes.
     *
     * @return list<string> 'Y-m-d H:i:s' v UTC
     */
    public function slotsFor(DateTimeImmutable $startUtc, int $durationMin): array
    {
        $out  = [];
        $step = new DateInterval('PT' . $this->location->slotMinutes . 'M');
        $end  = $this->endFor($startUtc, $durationMin);
        for ($t = $startUtc; $t < $end; $t = $t->add($step)) {
            $out[] = $t->format('Y-m-d H:i:s');
        }
        return $out;
    }

    /**
     * Prebere začetek iz vnosa. Brez odmika velja lokalni čas lokacije.
     * Lokalni čas, ki ga zaradi prehoda na poletni čas ni (02:30), je napaka.
     */
    public function parseStart(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
        $formats = $hasOffset
            ? ['Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:sO']
            : ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

        foreach ($formats as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $value, $this->tz);
            $errors = DateTimeImmutable::getLastErrors();
            if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            if (!$hasOffset && $d->format('Y-m-d H:i') !== substr(str_replace('T', ' ', $value), 0, 16)) {
                throw AppException::invalid(['starts_at' => 'Ta ura na ta dan ne obstaja (prehod na poletni čas).']);
            }
            return $d->setTimezone($this->utc);
        }
        throw AppException::invalid(['starts_at' => 'Začetek mora biti v obliki 2026-12-05T19:30.']);
    }

    /** Lokalni datum (Y-m-d) za trenutek v UTC. */
    public function localDate(DateTimeImmutable $utc): string
    {
        return $utc->setTimezone($this->tz)->format('Y-m-d');
    }

    /** Za odgovor: lokalni čas z odmikom. */
    public function toLocalIso(DateTimeImmutable $utc): string
    {
        return $utc->setTimezone($this->tz)->format('Y-m-d\TH:i:sP');
    }

    /** UTC okno [od, do) za lokalni dan. */
    public function dayWindowUtc(string $localDate): array
    {
        $from = new DateTimeImmutable($localDate . ' 00:00:00', $this->tz);
        $to   = $from->modify('+1 day');
        return [$from->setTimezone($this->utc), $to->setTimezone($this->utc)];
    }

    /**
     * Preveri pravila, ki niso odvisna od miz. Vrže AppException.
     *
     * $staffOverride: osebje sme mimo max_party_size, min_lead in max_days_ahead
     * (gost stoji pred njim). Ne sme mimo urnika in zaprtih dni.
     *
     * @param list<OpeningWindow> $windows  vsi dnevi v tednu
     * @param list<ClosurePeriod> $closures
     */
    public function checkRules(
        DateTimeImmutable $startUtc,
        int $party,
        int $durationMin,
        array $windows,
        array $closures,
        bool $staffOverride = false,
    ): void {
        $loc = $this->location;

        if ($party < 1) {
            throw AppException::invalid(['party_size' => 'Vsaj ena oseba.']);
        }
        if (!$staffOverride && $party > $loc->maxPartySize) {
            throw AppException::rule('party_too_large', "Prek telefona sprejmemo največ {$loc->maxPartySize} oseb.");
        }

        $minutes = (int) $startUtc->format('i');
        if ((int) $startUtc->format('s') !== 0 || $minutes % $loc->slotMinutes !== 0) {
            throw AppException::invalid(['starts_at' => "Začetek mora biti na {$loc->slotMinutes} minut."]);
        }

        $now = $this->clock->now();
        if ($staffOverride) {
            // Osebje vnaša goste, ki so že prišli: začetek sme biti v tekoči rezini.
            $earliest = $now->sub(new DateInterval('PT' . $loc->slotMinutes . 'M'));
        } else {
            $earliest = $now->add(new DateInterval('PT' . $loc->minLeadMinutes . 'M'));
        }
        if ($startUtc < $earliest) {
            throw AppException::rule('too_soon', $staffOverride
                ? 'Ta termin je že mimo.'
                : "Rezervacija mora biti vsaj {$loc->minLeadMinutes} minut vnaprej.");
        }

        if (!$staffOverride) {
            $lastDate = $now->setTimezone($this->tz)
                ->modify('+' . $loc->maxDaysAhead . ' days')
                ->format('Y-m-d');
            if ($this->localDate($startUtc) > $lastDate) {
                throw AppException::rule('too_far', "Rezervacije sprejemamo največ {$loc->maxDaysAhead} dni vnaprej.");
            }
        }

        $local   = $startUtc->setTimezone($this->tz);
        $weekday = (int) $local->format('N');
        $hhmm    = $local->format('H:i');
        $inHours = false;
        foreach ($windows as $w) {
            if ($w->weekday === $weekday && $w->allowsStart($hhmm)) {
                $inHours = true;
                break;
            }
        }
        if (!$inHours) {
            throw AppException::rule('outside_hours', 'Ob tej uri ne sprejemamo rezervacij.');
        }

        $end = $this->endFor($startUtc, $durationMin);
        foreach ($closures as $c) {
            if ($c->overlaps($startUtc, $end)) {
                throw AppException::rule('closed', 'Restavracija je v tem času zaprta.');
            }
        }
    }

    /**
     * Izbere mize. Vrne id-je ali null, če ni proste ustrezne mize.
     *
     * Brez $requested: najprej posamezna miza z najmanj praznimi sedeži, nato
     * (če lokacija dovoli) kombinacija z najmanj praznimi sedeži. Tako velika
     * miza ostane za veliko skupino.
     *
     * Z $requested (izbira osebja): vse izbrane mize morajo biti aktivne
     * mize te lokacije in proste; kapaciteta je vsota max_seats.
     *
     * @param list<DiningTable> $tables  vse mize lokacije
     * @param list<TableCombo>  $combos
     * @param array<int, array<string, true>> $busy
     * @param list<int>|null    $requested
     * @return list<int>|null
     */
    public function chooseTables(
        DateTimeImmutable $startUtc,
        int $durationMin,
        int $party,
        array $tables,
        array $combos,
        array $busy,
        ?array $requested = null,
    ): ?array {
        $slots = $this->slotsFor($startUtc, $durationMin);
        $byId = [];
        foreach ($tables as $t) {
            if ($t->active) {
                $byId[$t->id] = $t;
            }
        }
        $isFree = static function (int $id) use ($slots, $busy): bool {
            foreach ($slots as $s) {
                if (isset($busy[$id][$s])) {
                    return false;
                }
            }
            return true;
        };

        if ($requested !== null) {
            $requested = array_values(array_unique(array_map('intval', $requested)));
            if ($requested === []) {
                throw AppException::invalid(['table_ids' => 'Izberi vsaj eno mizo.']);
            }
            $seats = 0;
            foreach ($requested as $id) {
                if (!isset($byId[$id])) {
                    throw AppException::invalid(['table_ids' => 'Miza ne obstaja ali ni aktivna.']);
                }
                $seats += $byId[$id]->maxSeats;
            }
            if ($party > $seats) {
                throw AppException::rule('table_too_small', 'Izbrane mize imajo premalo sedežev.');
            }
            foreach ($requested as $id) {
                if (!$isFree($id)) {
                    return null;
                }
            }
            sort($requested);
            return $requested;
        }

        $best = null;
        foreach ($byId as $t) {
            if ($party < $t->minSeats || $party > $t->maxSeats || !$isFree($t->id)) {
                continue;
            }
            $key = [$t->maxSeats - $party, $t->sortOrder, $t->id];
            if ($best === null || $key < $best[0]) {
                $best = [$key, [$t->id]];
            }
        }
        if ($best !== null) {
            return $best[1];
        }

        if (!$this->location->allowTableMerge) {
            return null;
        }
        foreach ($combos as $c) {
            if ($party > $c->maxSeats) {
                continue;
            }
            $ok = true;
            foreach ($c->tableIds as $id) {
                if (!isset($byId[$id]) || !$isFree($id)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $key = [$c->maxSeats - $party, count($c->tableIds), $c->id];
            if ($best === null || $key < $best[0]) {
                $ids = $c->tableIds;
                sort($ids);
                $best = [$key, $ids];
            }
        }
        return $best[1] ?? null;
    }

    /**
     * Prosti začetki na lokalni dan, po vrsti.
     *
     * @param list<OpeningWindow> $windows
     * @param list<ClosurePeriod> $closures
     * @param list<DiningTable>   $tables
     * @param list<TableCombo>    $combos
     * @param array<int, array<string, true>> $busy
     * @return list<DateTimeImmutable> UTC
     */
    public function freeStarts(
        string $localDate,
        int $party,
        int $durationMin,
        array $windows,
        array $closures,
        array $tables,
        array $combos,
        array $busy,
        bool $staffOverride = false,
    ): array {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, $this->tz);
        if ($day === false || $day->format('Y-m-d') !== $localDate) {
            throw AppException::invalid(['date' => 'Datum mora biti v obliki 2026-12-05.']);
        }
        $weekday = (int) $day->format('N');
        $slot    = $this->location->slotMinutes;
        $seen    = [];
        $out     = [];

        foreach ($windows as $w) {
            if ($w->weekday !== $weekday) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $w->opensAt));
            $t = $h * 60 + $m;
            // Prva rezina na mreži, ne prej kot opens_at.
            $t = (int) (ceil($t / $slot) * $slot);
            [$lh, $lm] = array_map('intval', explode(':', $w->lastSeating));
            $last = $lh * 60 + $lm;

            for (; $t <= $last; $t += $slot) {
                $hhmm = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                $local = new DateTimeImmutable($localDate . ' ' . $hhmm, $this->tz);
                if ($local->format('H:i') !== $hhmm) {
                    continue; // ura ne obstaja (prehod na poletni čas)
                }
                $start = $local->setTimezone($this->utc);
                $key = $start->format('Y-m-d H:i');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                try {
                    $this->checkRules($start, $party, $durationMin, $windows, $closures, $staffOverride);
                } catch (AppException) {
                    continue;
                }
                if ($this->chooseTables($start, $durationMin, $party, $tables, $combos, $busy) !== null) {
                    $out[] = $start;
                }
            }
        }
        usort($out, static fn($a, $b) => $a <=> $b);
        return $out;
    }

    /**
     * Do $limit prostih začetkov, najbližjih $target (za alternative ob zasedenem terminu).
     *
     * @param list<DateTimeImmutable> $free
     * @return list<DateTimeImmutable>
     */
    public static function nearest(array $free, DateTimeImmutable $target, int $limit = 3): array
    {
        usort($free, static fn($a, $b) =>
            abs($a->getTimestamp() - $target->getTimestamp()) <=> abs($b->getTimestamp() - $target->getTimestamp())
            ?: $a <=> $b);
        $out = array_slice($free, 0, $limit);
        usort($out, static fn($a, $b) => $a <=> $b);
        return $out;
    }
}
