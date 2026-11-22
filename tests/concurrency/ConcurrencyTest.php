<?php

declare(strict_types=1);

use App\Support\Database;

/**
 * Dokaz, da dvojne zasedbe ni.
 *
 * N ločenih PHP procesov (vsak s svojo povezavo) hkrati rezervira. Vsak
 * scenarij teče `concurrency_iterations`-krat (privzeto 50); en sam
 * neuspeh je rdeč. Število procesov omejuje max_user_connections na
 * gostovanju, zato je nastavljivo (`concurrency_workers`, privzeto 8).
 */
final class ConcurrencyTest extends TestCase
{
    private Database $db;
    private Fixtures $fx;
    private int $tenant;
    private int $loc;
    private string $date;
    private int $iterations;
    private int $workers;

    public function setUp(): void
    {
        if (!TestDb::available()) {
            $this->skip(TestDb::whyUnavailable());
        }
        $this->db = TestDb::fresh();
        $this->fx = new Fixtures($this->db);
        $this->tenant = $this->fx->tenant();
        $this->loc = $this->fx->location($this->tenant);
        $this->date = Fixtures::dateIn(7);
        $cfg = TestDb::config();
        $this->iterations = (int) ($cfg['concurrency_iterations'] ?? 50);
        $this->workers = (int) ($cfg['concurrency_workers'] ?? 8);
    }

    /**
     * Zažene tekmece in vrne njihove odgovore.
     *
     * @param list<array{0:string, 1:int, 2?:string}> $jobs [starts_at, party, idempotency_key]
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs, bool $lock = true): array
    {
        $barrier = sys_get_temp_dir() . '/rez-race-' . bin2hex(random_bytes(4));
        mkdir($barrier);
        $procs = [];
        foreach ($jobs as [$start, $party, $key]) {
            $cmd = array_merge([PHP_BINARY], TestDb::phpArgs(), [
                __DIR__ . '/worker.php', (string) $this->tenant, (string) $this->loc, $start, (string) $party,
                $barrier, $lock ? '1' : '0', $key ?? '-',
            ]);
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                throw new RuntimeException('proc_open ni uspel');
            }
            $procs[] = [$p, $pipes];
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier . '/ready-*') ?: []) < count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('tekmeci se niso povezali v 30 s (max_user_connections?)');
            }
            usleep(2000);
        }
        touch($barrier . '/go');

        $results = [];
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $lines = array_values(array_filter(explode("\n", (string) $out), fn($l) => str_starts_with($l, '{')));
            $results[] = $lines ? json_decode(end($lines), true) : ['ok' => false, 'code' => 'no_output', 'error' => trim((string) $err)];
        }
        array_map('unlink', glob($barrier . '/*') ?: []);
        rmdir($barrier);
        return $results;
    }

    private function clearReservations(): void
    {
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['table_slots', 'reservation_tables', 'reservation_events', 'reservations'] as $t) {
            $this->db->run("TRUNCATE TABLE {$t}");
        }
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param list<array<string, mixed>> $results */
    private function assertOutcome(array $results, int $expectedOk, string $label): void
    {
        $ok = array_filter($results, fn($r) => $r['ok'] === true);
        foreach ($results as $r) {
            if ($r['ok'] !== true && !in_array($r['code'], ['slot_taken'], true)) {
                throw new AssertionError("{$label}: nepričakovan izid " . json_encode($r));
            }
        }
        $this->assertSame($expectedOk, count($ok), "{$label}: število uspehov");
        $this->assertSame($expectedOk, $this->fx->count('reservations'), "{$label}: vrstic v bazi");
        // Neodvisno preverjanje prekrivanja iz reservations, ne iz table_slots.
        $overlaps = $this->db->one(
            'SELECT COUNT(*) AS n FROM reservations a
               JOIN reservation_tables ta ON ta.reservation_id = a.id
               JOIN reservation_tables tb ON tb.table_id = ta.table_id AND tb.reservation_id > a.id
               JOIN reservations b ON b.id = tb.reservation_id
              WHERE a.starts_at < b.ends_at AND b.starts_at < a.ends_at
                AND a.status IN (\'confirmed\', \'arrived\') AND b.status IN (\'confirmed\', \'arrived\')'
        );
        $this->assertSame(0, (int) $overlaps['n'], "{$label}: prekrivajoči se rezervaciji na isti mizi");
    }

    public function testManyRacersOneLastTable(): void
    {
        $this->fx->table($this->tenant, $this->loc, 'Zadnja', 1, 4);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->clearReservations();
            $jobs = array_fill(0, $this->workers, [$this->date . 'T19:00', 2, null]);
            $this->assertOutcome($this->race($jobs), 1, "iteracija {$i}");
        }
    }

    public function testPartialOverlapOnlyOneWins(): void
    {
        $this->fx->table($this->tenant, $this->loc, 'Ena', 1, 4);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->clearReservations();
            $jobs = [];
            for ($w = 0; $w < $this->workers; $w++) {
                // 19:00-20:30 in 20:00-21:30 se prekrivata.
                $jobs[] = [$this->date . ($w % 2 ? 'T20:00' : 'T19:00'), 2, null];
            }
            $this->assertOutcome($this->race($jobs), 1, "iteracija {$i}");
        }
    }

    public function testTwoTablesExactlyTwoWin(): void
    {
        $this->fx->table($this->tenant, $this->loc, 'A', 1, 4);
        $this->fx->table($this->tenant, $this->loc, 'B', 1, 4);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->clearReservations();
            $jobs = array_fill(0, max(3, $this->workers), [$this->date . 'T19:00', 2, null]);
            $this->assertOutcome($this->race($jobs), 2, "iteracija {$i}");
        }
    }

    /**
     * Zaklep izklopljen: dvojno zasedbo mora preprečiti UNIQUE (table_id, slot_start).
     * Dokaz, da varnostna mreža deluje tudi ob napaki v kodi.
     */
    public function testUniqueSlotsHoldWithoutLock(): void
    {
        $this->fx->table($this->tenant, $this->loc, 'Brez zaklepa', 1, 4);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->clearReservations();
            $jobs = array_fill(0, $this->workers, [$this->date . 'T19:00', 2, null]);
            $this->assertOutcome($this->race($jobs, false), 1, "iteracija {$i}");
        }
    }

    public function testSameIdempotencyKeyCreatesOneReservation(): void
    {
        $this->fx->table($this->tenant, $this->loc, 'A', 1, 4);
        $this->fx->table($this->tenant, $this->loc, 'B', 1, 4);
        foreach ([true, false] as $lock) {
            for ($i = 0; $i < max(1, intdiv($this->iterations, 5)); $i++) {
                $this->clearReservations();
                $key = 'isti-kljuc-' . $i;
                // Isti gost, isti zahtevek (enak telefon, da je telo enako).
                $results = $this->race(array_fill(0, $this->workers, [$this->date . 'T19:00', 2, $key]), $lock);
                $label = 'zaklep ' . ($lock ? 'da' : 'ne') . ", iteracija {$i}";
                foreach ($results as $r) {
                    $this->assertTrue($r['ok'] === true, "{$label}: " . json_encode($r));
                }
                $ids = array_unique(array_map(fn($r) => $r['id'], $results));
                $this->assertCount(1, $ids, "{$label}: vsi dobijo isto rezervacijo");
                $this->assertSame(1, $this->fx->count('reservations'), "{$label}: ena vrstica");
                $this->assertSame(1, count(array_filter($results, fn($r) => $r['replayed'] === false)), "{$label}: natanko en prvi zapis");
            }
        }
    }
}
