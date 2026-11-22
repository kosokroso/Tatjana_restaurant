<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\TenantContext;
use App\Support\Database;
use DateTimeImmutable;

/**
 * Rezervacije, njihove mize, rezine zasedenosti in revizijska sled.
 *
 * Vse v enem razredu, ker se te štiri tabele vedno pišejo skupaj v isti
 * transakciji. Transakcijo odpre service, ne repository.
 */
final class ReservationRepository
{
    private const COLUMNS = 'r.id, r.location_id, r.code, r.status, r.source, r.party_size, r.starts_at, r.ends_at,
        r.guest_name, r.guest_phone, r.guest_email, r.notes, r.idempotency_key, r.idempotency_hash,
        r.created_by, r.created_at, r.updated_at';

    /** Stolpci, ki jih update() sme spremeniti. Vse drugo je napaka v kodi. */
    private const UPDATABLE = ['status', 'party_size', 'starts_at', 'ends_at', 'guest_name', 'guest_phone', 'guest_email', 'notes'];

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $ctx,
    ) {
    }

    /**
     * Zasedene rezine za dane mize v [from, to).
     *
     * @param list<int> $tableIds
     * @return array<int, array<string, true>>
     */
    public function busySlots(array $tableIds, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        if ($tableIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($tableIds), '?'));
        $rows = $this->db->all(
            "SELECT table_id, slot_start FROM table_slots
              WHERE tenant_id = ? AND table_id IN ({$in}) AND slot_start >= ? AND slot_start < ?",
            array_merge([$this->ctx->tenantId], array_map('intval', $tableIds),
                [$fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')])
        );
        $busy = [];
        foreach ($rows as $r) {
            $busy[(int) $r['table_id']][(string) $r['slot_start']] = true;
        }
        return $busy;
    }

    /** @param array<string, mixed> $r */
    public function insert(array $r): int
    {
        $this->db->run(
            'INSERT INTO reservations
               (tenant_id, location_id, code, status, source, party_size, starts_at, ends_at,
                guest_name, guest_phone, guest_email, notes, cancel_token,
                idempotency_key, idempotency_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->ctx->tenantId, $r['location_id'], $r['code'], $r['status'], $r['source'],
                $r['party_size'], $r['starts_at'], $r['ends_at'], $r['guest_name'], $r['guest_phone'],
                $r['guest_email'], $r['notes'], $r['cancel_token'], $r['idempotency_key'],
                $r['idempotency_hash'], $r['created_by'],
            ]
        );
        return $this->db->lastInsertId();
    }

    /**
     * Zapiše mize in rezine. Podvojen (table_id, slot_start) vrže PDOException 1062.
     *
     * @param list<int>    $tableIds
     * @param list<string> $slots
     */
    public function occupy(int $reservationId, array $tableIds, array $slots): void
    {
        $t = $this->ctx->tenantId;

        $rows = [];
        $params = [];
        foreach ($tableIds as $tableId) {
            $rows[] = '(?, ?, ?)';
            array_push($params, $t, $reservationId, $tableId);
        }
        $this->db->run('INSERT INTO reservation_tables (tenant_id, reservation_id, table_id) VALUES ' . implode(',', $rows), $params);

        $rows = [];
        $params = [];
        foreach ($tableIds as $tableId) {
            foreach ($slots as $slot) {
                $rows[] = '(?, ?, ?, ?)';
                array_push($params, $tableId, $slot, $t, $reservationId);
            }
        }
        $this->db->run('INSERT INTO table_slots (table_id, slot_start, tenant_id, reservation_id) VALUES ' . implode(',', $rows), $params);
    }

    /** Sprosti rezine. Mize (reservation_tables) ostanejo za zgodovino, razen ob premiku. */
    public function releaseSlots(int $reservationId): void
    {
        $this->db->run('DELETE FROM table_slots WHERE tenant_id = ? AND reservation_id = ?', [$this->ctx->tenantId, $reservationId]);
    }

    public function clearTables(int $reservationId): void
    {
        $this->db->run('DELETE FROM reservation_tables WHERE tenant_id = ? AND reservation_id = ?', [$this->ctx->tenantId, $reservationId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM reservations r WHERE r.tenant_id = ? AND r.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$this->ctx->tenantId, $id]
        );
        return $row ? $this->withTables([$row])[0] : null;
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM reservations r WHERE r.tenant_id = ? AND r.idempotency_key = ?',
            [$this->ctx->tenantId, $key]
        );
        return $row ? $this->withTables([$row])[0] : null;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = [];
        $params = [];
        foreach ($fields as $col => $value) {
            if (!in_array($col, self::UPDATABLE, true)) {
                throw new \LogicException("Stolpca {$col} ni dovoljeno spreminjati.");
            }
            $set[] = "{$col} = ?";
            $params[] = $value;
        }
        array_push($params, $this->ctx->tenantId, $id);
        $this->db->run('UPDATE reservations SET ' . implode(', ', $set) . ' WHERE tenant_id = ? AND id = ?', $params);
    }

    /**
     * Rezervacije, ki se začnejo v [from, to), z mizami.
     *
     * @return list<array<string, mixed>>
     */
    public function listBetween(int $locationId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ' FROM reservations r
              WHERE r.tenant_id = ? AND r.location_id = ? AND r.starts_at >= ? AND r.starts_at < ?
              ORDER BY r.starts_at, r.id',
            [$this->ctx->tenantId, $locationId, $fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')]
        );
        return $this->withTables($rows);
    }

    /**
     * Iskanje po telefonu (natančno) ali imenu/kodi (začetek niza).
     *
     * @return list<array<string, mixed>>
     */
    public function search(?string $phone, ?string $text, int $limit = 50): array
    {
        $where = [];
        $params = [$this->ctx->tenantId];
        if ($phone !== null) {
            $where[] = 'r.guest_phone = ?';
            $params[] = $phone;
        }
        if ($text !== null && $text !== '') {
            $like = addcslashes($text, '%_\\') . '%';
            $where[] = '(r.guest_name LIKE ? OR r.code LIKE ?)';
            array_push($params, $like, strtoupper($like));
        }
        $params[] = max(1, min($limit, 200));
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ' FROM reservations r WHERE r.tenant_id = ?'
            . ($where !== [] ? ' AND ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.starts_at DESC LIMIT ?',
            $params
        );
        return $this->withTables($rows);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function addEvent(int $reservationId, string $event, ?array $before, ?array $after): void
    {
        $this->db->run(
            'INSERT INTO reservation_events (tenant_id, reservation_id, event, actor_type, actor_id, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $this->ctx->tenantId, $reservationId, $event, $this->ctx->actorType, $this->ctx->actorId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(int $reservationId): array
    {
        $rows = $this->db->all(
            'SELECT event, actor_type, actor_id, before_json, after_json, created_at
               FROM reservation_events WHERE tenant_id = ? AND reservation_id = ? ORDER BY id',
            [$this->ctx->tenantId, $reservationId]
        );
        foreach ($rows as &$r) {
            $r['before'] = $r['before_json'] !== null ? json_decode((string) $r['before_json'], true) : null;
            $r['after']  = $r['after_json'] !== null ? json_decode((string) $r['after_json'], true) : null;
            unset($r['before_json'], $r['after_json']);
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withTables(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach ($this->db->all(
            "SELECT reservation_id, table_id FROM reservation_tables
              WHERE tenant_id = ? AND reservation_id IN ({$in}) ORDER BY table_id",
            array_merge([$this->ctx->tenantId], $ids)
        ) as $t) {
            $map[(int) $t['reservation_id']][] = (int) $t['table_id'];
        }
        foreach ($rows as &$r) {
            $r['table_ids'] = $map[(int) $r['id']] ?? [];
        }
        return $rows;
    }
}
