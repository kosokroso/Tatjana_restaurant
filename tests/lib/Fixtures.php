<?php

declare(strict_types=1);

use App\Support\Database;

/**
 * Testni podatki z neposrednim SQL. Testi smejo pisati SQL; koda v src/ ne.
 */
final class Fixtures
{
    public function __construct(private readonly Database $db)
    {
    }

    public function tenant(string $name = 'Test'): int
    {
        $this->db->run('INSERT INTO tenants (name) VALUES (?)', [$name]);
        return $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $o */
    public function location(int $tenantId, array $o = []): int
    {
        $this->db->run(
            'INSERT INTO locations (tenant_id, name, timezone, slot_minutes, default_duration_min, max_party_size,
                                    min_lead_minutes, max_days_ahead, allow_table_merge)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $o['name'] ?? 'Lokacija', 'Europe/Ljubljana', $o['slot'] ?? 15, $o['duration'] ?? 90,
             $o['max_party'] ?? 10, $o['lead'] ?? 0, $o['ahead'] ?? 60, (int) ($o['merge'] ?? false)]
        );
        $id = $this->db->lastInsertId();
        // Privzeto odprto vsak dan 11:00-23:00, zadnji začetek 21:30.
        if ($o['hours'] ?? true) {
            for ($d = 1; $d <= 7; $d++) {
                $this->db->run(
                    'INSERT INTO opening_hours (tenant_id, location_id, weekday, opens_at, last_seating, closes_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$tenantId, $id, $d, '11:00:00', '21:30:00', '23:00:00']
                );
            }
        }
        return $id;
    }

    public function table(int $tenantId, int $locationId, string $label, int $min, int $max, bool $active = true): int
    {
        $this->db->run(
            'INSERT INTO dining_tables (tenant_id, location_id, label, min_seats, max_seats, active) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $locationId, $label, $min, $max, (int) $active]
        );
        return $this->db->lastInsertId();
    }

    /** Zaprto [from, to) v lokalnem času Ljubljane. */
    public function closure(int $tenantId, int $locationId, string $fromLocal, string $toLocal): void
    {
        $tz = new DateTimeZone('Europe/Ljubljana');
        $utc = new DateTimeZone('UTC');
        $this->db->run(
            'INSERT INTO closures (tenant_id, location_id, starts_at, ends_at, reason) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $locationId,
             (new DateTimeImmutable($fromLocal, $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
             (new DateTimeImmutable($toLocal, $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
             'test']
        );
    }

    /** Lokalni datum čez $days dni (Y-m-d). */
    public static function dateIn(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana')))->modify("+{$days} days")->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public static function guest(array $o = []): array
    {
        return $o + ['guest_name' => 'Ana Novak', 'guest_phone' => '041 234 567', 'party_size' => 2];
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}", $params)['n'];
    }
}
