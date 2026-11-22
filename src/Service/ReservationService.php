<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AppException;
use App\Domain\Location;
use App\Domain\Phone;
use App\Domain\ReservationStatus;
use App\Domain\TenantContext;
use App\Repository\LocationRepository;
use App\Repository\ReservationRepository;
use App\Repository\ScheduleRepository;
use App\Repository\TableRepository;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Random;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Edino mesto, kjer rezervacije nastajajo in se spreminjajo.
 *
 * Osebje (PWA) in Tatjana kličeta ta razred. Razlika med njima je samo v
 * TenantContext: osebje sme mimo nekaterih pravil (glej
 * AvailabilityCalculator::checkRules), Tatjana ne.
 *
 * Vsako pisanje: transakcija -> zaklep vrstice lokacije -> sveže branje ->
 * pravila -> zapis -> dogodek. Glej sql/schema.sql, razdelek ZAKLEPANJE.
 */
final class ReservationService
{
    private const IDEMPOTENCY_KEY = '/^[A-Za-z0-9_-]{8,64}$/';

    private readonly LocationRepository $locations;
    private readonly TableRepository $tables;
    private readonly ScheduleRepository $schedule;
    private readonly ReservationRepository $reservations;
    /** @var array<int, Location> */
    private array $locationCache = [];

    /**
     * @param bool $lockLocation false samo v testu varnostne mreže (UNIQUE na rezinah)
     */
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $ctx,
        private readonly Clock $clock,
        private readonly bool $lockLocation = true,
    ) {
        $this->locations    = new LocationRepository($db, $ctx);
        $this->tables       = new TableRepository($db, $ctx);
        $this->schedule     = new ScheduleRepository($db, $ctx);
        $this->reservations = new ReservationRepository($db, $ctx);
    }

    // ------------------------------------------------------------------
    // Branje
    // ------------------------------------------------------------------

    /**
     * Prosti začetki za lokalni dan.
     *
     * @return list<string> lokalni ISO časi
     */
    public function availability(int $locationId, string $date, int $party, ?int $durationMin = null): array
    {
        $loc  = $this->location($locationId);
        $calc = new AvailabilityCalculator($loc, $this->clock);
        $duration = $calc->durationFor($this->ctx->isStaff() ? $durationMin : null);
        $free = $this->freeStartsFor($loc, $calc, $date, $party, $duration);
        return array_map([$calc, 'toLocalIso'], $free);
    }

    /**
     * Dnevni pregled: rezervacije dneva in mize.
     *
     * @return array{date:string, location_id:int, tables:list<array<string,mixed>>, reservations:list<array<string,mixed>>}
     */
    public function day(int $locationId, string $date): array
    {
        $loc  = $this->location($locationId);
        $calc = new AvailabilityCalculator($loc, $this->clock);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw AppException::invalid(['date' => 'Datum mora biti v obliki 2026-12-05.']);
        }
        [$from, $to] = $calc->dayWindowUtc($date);

        return [
            'date'         => $date,
            'location_id'  => $loc->id,
            'tables'       => array_map(static fn($t) => $t->toArray(), $this->tables->forLocation($loc->id)),
            'reservations' => array_map(fn($r) => $this->view($r, $calc), $this->reservations->listBetween($loc->id, $from, $to)),
        ];
    }

    /** @return array<string, mixed> rezervacija in njena sled */
    public function get(int $id): array
    {
        $row = $this->reservations->find($id) ?? throw AppException::notFound();
        $this->assertLocationAllowed((int) $row['location_id']);
        $view = $this->view($row);
        $view['events'] = $this->reservations->events($id);
        return $view;
    }

    /** @return list<array<string, mixed>> */
    public function search(?string $phone, ?string $text): array
    {
        $normalized = null;
        if ($phone !== null && trim($phone) !== '') {
            $country = $this->locations->all()[0]->phoneCountryCode ?? '386';
            $normalized = Phone::normalize($phone, $country)
                ?? throw AppException::invalid(['phone' => 'Telefonska številka ni veljavna.']);
        }
        $text = $text !== null ? mb_substr(trim($text), 0, 60) : null;
        if ($normalized === null && ($text === null || mb_strlen($text) < 2)) {
            throw AppException::invalid(['q' => 'Vpiši telefon ali vsaj dva znaka imena.']);
        }
        return array_map(fn($r) => $this->view($r), $this->reservations->search($normalized, $text));
    }

    // ------------------------------------------------------------------
    // Pisanje
    // ------------------------------------------------------------------

    /**
     * Ustvari rezervacijo atomarno.
     *
     * @param array<string, mixed> $input
     * @return array{reservation: array<string, mixed>, replayed: bool}
     */
    public function create(array $input, ?string $idempotencyKey): array
    {
        $staff = $this->ctx->isStaff();
        $locationId = $this->resolveLocationId($input);
        $guest = $this->validateGuest($input, $this->location($locationId));
        $party = $this->intField($input, 'party_size');
        $startsAt = $this->stringField($input, 'starts_at', 40);
        $requestedTables = $staff && isset($input['table_ids']) ? $this->intList($input['table_ids'], 'table_ids') : null;
        $durationIn = $staff && isset($input['duration_min']) ? $this->intField($input, 'duration_min') : null;

        $hash = null;
        if ($idempotencyKey !== null) {
            if (!preg_match(self::IDEMPOTENCY_KEY, $idempotencyKey)) {
                throw AppException::invalid(['Idempotency-Key' => '8-64 znakov A-Z, a-z, 0-9, _ ali -.']);
            }
            $hash = hash('sha256', json_encode(
                [$locationId, $startsAt, $party, $guest, $requestedTables, $durationIn],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } elseif (!$staff) {
            // Tatjana ponovi klic ob prekinjeni povezavi; brez ključa bi nastali dve rezervaciji.
            throw AppException::invalid(['Idempotency-Key' => 'Obvezno.']);
        }

        try {
            return $this->db->transaction(function () use ($locationId, $guest, $party, $startsAt, $requestedTables, $durationIn, $idempotencyKey, $hash, $staff): array {
                $loc = $this->locations->lockForWrite($locationId, $this->lockLocation) ?? throw AppException::notFound();

                // Po zaklepu: morda je isti zahtevek (ponovljen) pravkar končal.
                if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey, $hash)) !== null) {
                    return $existing;
                }

                $calc     = new AvailabilityCalculator($loc, $this->clock);
                $start    = $calc->parseStart($startsAt);
                $duration = $calc->durationFor($durationIn);
                $end      = $calc->endFor($start, $duration);

                $calc->checkRules($start, $party, $duration, $this->schedule->windows($loc->id),
                    $this->schedule->closures($loc->id, $start, $end), $staff);

                $tables = $this->tables->forLocation($loc->id);
                $busy   = $this->reservations->busySlots(array_map(static fn($t) => $t->id, $tables), $start, $end);
                $chosen = $calc->chooseTables($start, $duration, $party, $tables, $this->tables->combos($loc->id), $busy, $requestedTables);
                if ($chosen === null) {
                    throw $this->slotTakenWithAlternatives($loc, $calc, $start, $party, $duration);
                }

                $id = $this->insertWithUniqueCode([
                    'location_id'      => $loc->id,
                    'status'           => ReservationStatus::Confirmed->value,
                    'source'           => $this->ctx->source(),
                    'party_size'       => $party,
                    'starts_at'        => $start->format('Y-m-d H:i:s'),
                    'ends_at'          => $end->format('Y-m-d H:i:s'),
                    'guest_name'       => $guest['name'],
                    'guest_phone'      => $guest['phone'],
                    'guest_email'      => $guest['email'],
                    'notes'            => $guest['notes'],
                    'cancel_token'     => Random::token(),
                    'idempotency_key'  => $idempotencyKey,
                    'idempotency_hash' => $hash,
                    'created_by'       => $staff ? $this->ctx->actorId : null,
                ]);
                $this->occupy($id, $chosen, $calc->slotsFor($start, $duration));

                $row = $this->reservations->find($id) ?? throw new \LogicException('Pravkar zapisana rezervacija manjka.');
                $view = $this->view($row, $calc);
                $this->reservations->addEvent($id, 'created', null, $this->auditFields($view));

                return ['reservation' => $view, 'replayed' => false];
            });
        } catch (PDOException $e) {
            // Brez zaklepa (ali ob napaki v kodi) se dva enaka ključa srečata na UNIQUE.
            if ($idempotencyKey !== null && Database::duplicateKeyName($e) === 'uq_res_idem') {
                return $this->replay($idempotencyKey, $hash) ?? throw $e;
            }
            throw $e;
        }
    }

    /**
     * Spremeni rezervacijo. Premik časa, osebe ali mize gre skozi enaka
     * pravila kot nova rezervacija; stare rezine se sprostijo v isti transakciji.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $current = $this->reservations->find($id) ?? throw AppException::notFound();
        $this->assertLocationAllowed((int) $current['location_id']);

        return $this->db->transaction(function () use ($id, $input, $current): array {
            $loc = $this->locations->lockForWrite((int) $current['location_id'], $this->lockLocation) ?? throw AppException::notFound();
            $row = $this->reservations->find($id, true) ?? throw AppException::notFound();
            $status = ReservationStatus::from((string) $row['status']);
            if (!$status->holdsTable()) {
                throw AppException::conflict('invalid_transition', 'Odpovedane ali zaključene rezervacije ni mogoče spreminjati.');
            }

            $calc   = new AvailabilityCalculator($loc, $this->clock);
            $utc    = new DateTimeZone('UTC');
            $before = $this->view($row, $calc);

            $oldStart    = new DateTimeImmutable((string) $row['starts_at'], $utc);
            $oldDuration = (int) round(((new DateTimeImmutable((string) $row['ends_at'], $utc))->getTimestamp() - $oldStart->getTimestamp()) / 60);

            $start    = array_key_exists('starts_at', $input) ? $calc->parseStart($this->stringField($input, 'starts_at', 40)) : $oldStart;
            $party    = array_key_exists('party_size', $input) ? $this->intField($input, 'party_size') : (int) $row['party_size'];
            $duration = array_key_exists('duration_min', $input) && $this->ctx->isStaff()
                ? $calc->durationFor($this->intField($input, 'duration_min'))
                : $oldDuration;
            $requested = array_key_exists('table_ids', $input) && $this->ctx->isStaff()
                ? $this->intList($input['table_ids'], 'table_ids')
                : null;

            $fields = [];
            $moved = $start != $oldStart || $party !== (int) $row['party_size'] || $duration !== $oldDuration
                || ($requested !== null && $requested != $row['table_ids']);

            if ($moved) {
                $end = $calc->endFor($start, $duration);
                if ($start != $oldStart) {
                    // Pravila veljajo za nov začetek. Ob podaljšanju ali dodani osebi
                    // pri gostih, ki že sedijo, se "too_soon" ne sme sprožiti.
                    $calc->checkRules($start, $party, $duration, $this->schedule->windows($loc->id),
                        $this->schedule->closures($loc->id, $start, $end), $this->ctx->isStaff());
                } elseif ($party < 1) {
                    throw AppException::invalid(['party_size' => 'Vsaj ena oseba.']);
                } elseif (!$this->ctx->isStaff() && $party > $loc->maxPartySize) {
                    throw AppException::rule('party_too_large', "Prek telefona sprejmemo največ {$loc->maxPartySize} oseb.");
                }

                $this->reservations->releaseSlots($id);
                $this->reservations->clearTables($id);

                $tables = $this->tables->forLocation($loc->id);
                $busy   = $this->reservations->busySlots(array_map(static fn($t) => $t->id, $tables), $start, $end);
                $combos = $this->tables->combos($loc->id);

                $chosen = null;
                if ($requested !== null) {
                    $chosen = $calc->chooseTables($start, $duration, $party, $tables, $combos, $busy, $requested);
                } else {
                    // Gost naj ostane za isto mizo, če je še prosta in dovolj velika.
                    try {
                        $chosen = $calc->chooseTables($start, $duration, $party, $tables, $combos, $busy, $row['table_ids']);
                    } catch (AppException) {
                        $chosen = null;
                    }
                    $chosen ??= $calc->chooseTables($start, $duration, $party, $tables, $combos, $busy);
                }
                if ($chosen === null) {
                    // Izjema razveljavi transakcijo: stare rezine se vrnejo.
                    throw $this->slotTakenWithAlternatives($loc, $calc, $start, $party, $duration);
                }
                $this->occupy($id, $chosen, $calc->slotsFor($start, $duration));

                $fields['starts_at']  = $start->format('Y-m-d H:i:s');
                $fields['ends_at']    = $end->format('Y-m-d H:i:s');
                $fields['party_size'] = $party;
            }

            $guestInput = array_intersect_key($input, array_flip(['guest_name', 'guest_phone', 'guest_email', 'notes']));
            if ($guestInput !== []) {
                $merged = $this->validateGuest($guestInput + [
                    'guest_name'  => $row['guest_name'],
                    'guest_phone' => $row['guest_phone'],
                    'guest_email' => $row['guest_email'],
                    'notes'       => $row['notes'],
                ], $loc);
                $fields['guest_name']  = $merged['name'];
                $fields['guest_phone'] = $merged['phone'];
                $fields['guest_email'] = $merged['email'];
                $fields['notes']       = $merged['notes'];
            }

            $this->reservations->update($id, $fields);
            $after = $this->view($this->reservations->find($id) ?? throw new \LogicException('Rezervacija izginila.'), $calc);

            [$b, $a] = $this->diff($this->auditFields($before), $this->auditFields($after));
            if ($a !== []) {
                $this->reservations->addEvent($id, $moved ? 'moved' : 'updated', $b, $a);
            }
            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function changeStatus(int $id, string $newStatus, ?string $reason = null): array
    {
        $to = ReservationStatus::tryFrom($newStatus) ?? throw AppException::invalid(['status' => 'Neznano stanje.']);
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 200) : null;

        $current = $this->reservations->find($id) ?? throw AppException::notFound();
        $this->assertLocationAllowed((int) $current['location_id']);

        return $this->db->transaction(function () use ($id, $to, $reason, $current): array {
            $this->locations->lockForWrite((int) $current['location_id'], $this->lockLocation) ?? throw AppException::notFound();
            $row  = $this->reservations->find($id, true) ?? throw AppException::notFound();
            $from = ReservationStatus::from((string) $row['status']);

            if (!$from->canTransitionTo($to)) {
                throw AppException::conflict('invalid_transition', "Prehod iz {$from->value} v {$to->value} ni dovoljen.");
            }
            $this->reservations->update($id, ['status' => $to->value]);
            if (!$to->holdsTable()) {
                $this->reservations->releaseSlots($id);
            }
            $this->reservations->addEvent($id, 'status_changed',
                ['status' => $from->value],
                ['status' => $to->value] + ($reason !== null && $reason !== '' ? ['reason' => $reason] : []));

            return $this->view($this->reservations->find($id) ?? throw new \LogicException('Rezervacija izginila.'));
        });
    }

    // ------------------------------------------------------------------
    // Pomožno
    // ------------------------------------------------------------------

    private function location(int $id): Location
    {
        $this->assertLocationAllowed($id);
        return $this->locationCache[$id] ??= $this->locations->find($id) ?? throw AppException::notFound();
    }

    /** Agent je vezan na svojo lokacijo; tuja lokacija istega tenanta je zanj neobstoječa. */
    private function assertLocationAllowed(int $locationId): void
    {
        if ($this->ctx->locationId !== null && $this->ctx->locationId !== $locationId) {
            throw AppException::notFound();
        }
    }

    /** @param array<string, mixed> $input */
    private function resolveLocationId(array $input): int
    {
        if ($this->ctx->locationId !== null) {
            return $this->ctx->locationId;
        }
        return $this->intField($input, 'location_id');
    }

    /** @return array{reservation: array<string, mixed>, replayed: bool}|null */
    private function replay(string $key, ?string $hash): ?array
    {
        $existing = $this->reservations->findByIdempotencyKey($key);
        if ($existing === null) {
            return null;
        }
        if (!hash_equals((string) $existing['idempotency_hash'], (string) $hash)) {
            throw AppException::conflict('idempotency_conflict', 'Isti ključ je bil že uporabljen za drugačen zahtevek.');
        }
        $this->assertLocationAllowed((int) $existing['location_id']);
        return ['reservation' => $this->view($existing), 'replayed' => true];
    }

    /** @param array<string, mixed> $r */
    private function insertWithUniqueCode(array $r): int
    {
        for ($i = 0; ; $i++) {
            try {
                return $this->reservations->insert($r + ['code' => Random::code()]);
            } catch (PDOException $e) {
                // 31^8 kombinacij; trk je skoraj nemogoč, a ne sme postati napaka 500.
                if ($i < 3 && Database::duplicateKeyName($e) === 'uq_res_code') {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * @param list<int>    $tableIds
     * @param list<string> $slots
     */
    private function occupy(int $id, array $tableIds, array $slots): void
    {
        try {
            $this->reservations->occupy($id, $tableIds, $slots);
        } catch (PDOException $e) {
            // Varnostna mreža: nekdo drug drži isto mizo v isti rezini.
            if (Database::duplicateKeyName($e) === 'PRIMARY') {
                throw AppException::slotTaken();
            }
            throw $e;
        }
    }

    private function slotTakenWithAlternatives(Location $loc, AvailabilityCalculator $calc, DateTimeImmutable $start, int $party, int $duration): AppException
    {
        // Če skupina ne gre za nobeno mizo niti v prazni restavraciji, "zaseden"
        // ni res in alternativ ne bo. Model bi sicer ponujal druge ure brez konca.
        if ($calc->chooseTables($start, $duration, $party, $this->tables->forLocation($loc->id), $this->tables->combos($loc->id), []) === null) {
            return AppException::rule('no_table_for_party', 'Za toliko oseb nimamo ustrezne mize.');
        }
        try {
            $free = $this->freeStartsFor($loc, $calc, $calc->localDate($start), $party, $duration);
        } catch (AppException) {
            $free = [];
        }
        return AppException::slotTaken(array_map(
            static fn(DateTimeImmutable $d): array => ['starts_at' => $calc->toLocalIso($d)],
            AvailabilityCalculator::nearest($free, $start, 3)
        ));
    }

    /** @return list<DateTimeImmutable> */
    private function freeStartsFor(Location $loc, AvailabilityCalculator $calc, string $date, int $party, int $duration): array
    {
        [$from, $to] = $calc->dayWindowUtc($date);
        // Rezervacija, ki se začne pozno, sega v naslednji dan.
        $toWithTail = $to->modify('+' . AvailabilityCalculator::MAX_DURATION_MIN . ' minutes');
        $tables = $this->tables->forLocation($loc->id);

        return $calc->freeStarts(
            $date, $party, $duration,
            $this->schedule->windows($loc->id),
            $this->schedule->closures($loc->id, $from, $toWithTail),
            $tables,
            $this->tables->combos($loc->id),
            $this->reservations->busySlots(array_map(static fn($t) => $t->id, $tables), $from, $toWithTail),
            $this->ctx->isStaff(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string, phone:string, email:?string, notes:?string}
     */
    private function validateGuest(array $input, Location $loc): array
    {
        $errors = [];
        $name = $this->clean((string) ($input['guest_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            $errors['guest_name'] = 'Ime je obvezno (do 160 znakov).';
        }
        $phone = Phone::normalize((string) ($input['guest_phone'] ?? ''), $loc->phoneCountryCode);
        if ($phone === null) {
            $errors['guest_phone'] = 'Telefonska številka ni veljavna.';
        }
        $email = $this->clean((string) ($input['guest_email'] ?? ''));
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['guest_email'] = 'E-poštni naslov ni veljaven.';
        }
        $notes = $this->clean((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Opombe do 500 znakov.';
        }
        if ($errors !== []) {
            throw AppException::invalid($errors);
        }
        return ['name' => $name, 'phone' => (string) $phone, 'email' => $email !== '' ? $email : null, 'notes' => $notes !== '' ? $notes : null];
    }

    /** Odstrani kontrolne znake (tudi prelome vrstic v imenu) in odvečne presledke. */
    private function clean(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
    }

    /** @param array<string, mixed> $input */
    private function intField(array $input, string $key): int
    {
        $v = $input[$key] ?? null;
        if (is_int($v) || (is_string($v) && preg_match('/^\d{1,9}$/', $v))) {
            return (int) $v;
        }
        throw AppException::invalid([$key => 'Pričakovano celo število.']);
    }

    /** @param array<string, mixed> $input */
    private function stringField(array $input, string $key, int $max): string
    {
        $v = $input[$key] ?? null;
        if (!is_string($v) || $v === '' || strlen($v) > $max) {
            throw AppException::invalid([$key => 'Obvezno polje.']);
        }
        return $v;
    }

    /** @return list<int> */
    private function intList(mixed $v, string $key): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 10) {
            throw AppException::invalid([$key => 'Pričakovan seznam številk.']);
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_int($item) || $item < 1) {
                throw AppException::invalid([$key => 'Pričakovan seznam številk.']);
            }
            $out[] = $item;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Rezervacija za odgovor API. Časi v lokalnem času lokacije.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row, ?AvailabilityCalculator $calc = null): array
    {
        $calc ??= new AvailabilityCalculator($this->location((int) $row['location_id']), $this->clock);
        $utc = new DateTimeZone('UTC');
        return [
            'id'          => (int) $row['id'],
            'code'        => (string) $row['code'],
            'location_id' => (int) $row['location_id'],
            'status'      => (string) $row['status'],
            'source'      => (string) $row['source'],
            'party_size'  => (int) $row['party_size'],
            'starts_at'   => $calc->toLocalIso(new DateTimeImmutable((string) $row['starts_at'], $utc)),
            'ends_at'     => $calc->toLocalIso(new DateTimeImmutable((string) $row['ends_at'], $utc)),
            'guest_name'  => (string) $row['guest_name'],
            'guest_phone' => (string) $row['guest_phone'],
            'guest_email' => $row['guest_email'] !== null ? (string) $row['guest_email'] : null,
            'notes'       => $row['notes'] !== null ? (string) $row['notes'] : null,
            'table_ids'   => array_map('intval', $row['table_ids'] ?? []),
        ];
    }

    /** Polja, ki gredo v revizijsko sled. */
    private function auditFields(array $view): array
    {
        return array_intersect_key($view, array_flip([
            'status', 'party_size', 'starts_at', 'ends_at', 'guest_name', 'guest_phone', 'guest_email', 'notes', 'table_ids',
        ]));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} samo spremenjena polja */
    private function diff(array $before, array $after): array
    {
        $b = $a = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $b[$k] = $before[$k] ?? null;
                $a[$k] = $v;
            }
        }
        return [$b, $a];
    }
}
