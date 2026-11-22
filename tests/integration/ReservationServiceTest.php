<?php

declare(strict_types=1);

use App\Domain\AppException;
use App\Domain\TenantContext;
use App\Service\ReservationService;
use App\Support\Clock;
use App\Support\Database;

final class ReservationServiceTest extends TestCase
{
    private Database $db;
    private Fixtures $fx;
    private int $tenant;
    private int $loc;
    private int $t2;   // miza 2-4
    private int $t6;   // miza 4-6
    private string $date;

    public function setUp(): void
    {
        if (!TestDb::available()) {
            $this->skip(TestDb::whyUnavailable());
        }
        $this->db = TestDb::fresh();
        $this->fx = new Fixtures($this->db);
        $this->tenant = $this->fx->tenant();
        $this->loc = $this->fx->location($this->tenant);
        $this->t2 = $this->fx->table($this->tenant, $this->loc, 'T2', 1, 4);
        $this->t6 = $this->fx->table($this->tenant, $this->loc, 'T6', 4, 6);
        $this->date = Fixtures::dateIn(7);
    }

    private function staff(): ReservationService
    {
        return new ReservationService($this->db, TenantContext::staff($this->tenant, 1, 'staff'), new Clock());
    }

    private function agent(?int $location = null): ReservationService
    {
        return new ReservationService($this->db, TenantContext::agent($this->tenant, 1, $location ?? $this->loc), new Clock());
    }

    private function create(array $o = [], ?string $key = null, ?ReservationService $s = null): array
    {
        return ($s ?? $this->staff())->create(Fixtures::guest($o + [
            'location_id' => $this->loc,
            'starts_at'   => $this->date . 'T19:00',
        ]), $key)['reservation'];
    }

    public function testCreateWritesReservationSlotsAndEvent(): void
    {
        $r = $this->create();
        $this->assertSame('confirmed', $r['status']);
        $this->assertSame('staff', $r['source']);
        $this->assertSame([$this->t2], $r['table_ids']);
        $this->assertSame('+38641234567', $r['guest_phone']);
        $this->assertSame(1, preg_match('/^' . $this->date . 'T19:00:00\+0[12]:00$/', $r['starts_at']), $r['starts_at']);
        $this->assertSame(6, $this->fx->count('table_slots', 'reservation_id = ?', [$r['id']]), '90 min = 6 rezin');
        $this->assertSame(1, $this->fx->count('reservation_events', "reservation_id = ? AND event = 'created'", [$r['id']]));
        $this->assertSame(8, strlen($r['code']));
    }

    public function testSecondGuestGetsOtherTableThenSlotTaken(): void
    {
        $a = $this->create(['party_size' => 4]);
        $b = $this->create(['party_size' => 4]);
        $this->assertSame([$this->t2], $a['table_ids']);
        $this->assertSame([$this->t6], $b['table_ids']);
        $e = $this->expectThrows(AppException::class, fn() => $this->create(['party_size' => 4]), 'slot_taken');
        $this->assertTrue(count($e->extra['alternatives']) > 0, 'pričakovane alternative');
        foreach ($e->extra['alternatives'] as $alt) {
            $this->assertFalse(str_contains($alt['starts_at'], 'T19:00'), 'alternativa ne sme biti zasedeni termin');
        }
    }

    public function testPartyLargerThanAnyTable(): void
    {
        $this->expectThrows(AppException::class, fn() => $this->create(['party_size' => 7]), 'no_table_for_party');
    }

    public function testAgentRulesAreStricter(): void
    {
        $this->db->run('UPDATE locations SET max_party_size = 4 WHERE id = ?', [$this->loc]);
        $this->expectThrows(AppException::class,
            fn() => $this->create(['party_size' => 5], 'kljuc-agent-1', $this->agent()), 'party_too_large');
        // Osebje sme.
        $this->create(['party_size' => 5]);
        // Agent brez ključa idempotentnosti ni sprejet.
        $this->expectThrows(AppException::class, fn() => $this->create([], null, $this->agent()), 'invalid_input');
        // Agent ne izbira miz in trajanja: polji se ignorirata.
        $r = $this->create(['table_ids' => [$this->t6], 'duration_min' => 300], 'kljuc-agent-2', $this->agent());
        $this->assertSame('phone', $r['source']);
        $this->assertSame([$this->t2], $r['table_ids']);
        $this->assertSame(6, $this->fx->count('table_slots', 'reservation_id = ?', [$r['id']]));
    }

    public function testAgentIsBoundToItsLocation(): void
    {
        $other = $this->fx->location($this->tenant, ['name' => 'Druga']);
        $this->fx->table($this->tenant, $other, 'X', 1, 4);
        // location_id v telesu se pri agentu ignorira; dela se na njegovi lokaciji.
        $r = $this->create(['location_id' => $other], 'kljuc-lok-01', $this->agent());
        $this->assertSame($this->loc, $r['location_id']);
        $this->expectThrows(AppException::class, fn() => $this->agent()->day($other, $this->date), 'not_found');
        $foreign = $this->create(['location_id' => $other]);
        $this->expectThrows(AppException::class, fn() => $this->agent()->get($foreign['id']), 'not_found');
        $this->expectThrows(AppException::class, fn() => $this->agent()->changeStatus($foreign['id'], 'cancelled'), 'not_found');
    }

    public function testHoursAndClosures(): void
    {
        $this->expectThrows(AppException::class, fn() => $this->create(['starts_at' => $this->date . 'T22:00']), 'outside_hours');
        $this->fx->closure($this->tenant, $this->loc, $this->date . ' 18:00', $this->date . ' 23:59');
        $this->expectThrows(AppException::class, fn() => $this->create(), 'closed');
        $this->assertSame(0, $this->fx->count('reservations'));
    }

    public function testIdempotency(): void
    {
        $s = $this->staff();
        $first = $s->create(Fixtures::guest(['location_id' => $this->loc, 'starts_at' => $this->date . 'T19:00']), 'ponovi-123');
        $again = $s->create(Fixtures::guest(['location_id' => $this->loc, 'starts_at' => $this->date . 'T19:00']), 'ponovi-123');
        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['reservation']['id'], $again['reservation']['id']);
        $this->assertSame(1, $this->fx->count('reservations'));

        $this->expectThrows(AppException::class, fn() => $s->create(
            Fixtures::guest(['location_id' => $this->loc, 'starts_at' => $this->date . 'T20:00']), 'ponovi-123'
        ), 'idempotency_conflict');
        $this->expectThrows(AppException::class, fn() => $s->create(
            Fixtures::guest(['location_id' => $this->loc, 'starts_at' => $this->date . 'T20:00']), 'kratek'
        ), 'invalid_input');
    }

    public function testMoveReleasesOldSlotsAndKeepsTable(): void
    {
        $r = $this->create();
        $moved = $this->staff()->update($r['id'], ['starts_at' => $this->date . 'T20:00']);
        $this->assertSame([$this->t2], $moved['table_ids']);
        $slots = array_column($this->db->all('SELECT slot_start FROM table_slots WHERE reservation_id = ? ORDER BY slot_start', [$r['id']]), 'slot_start');
        $this->assertCount(6, $slots);
        // Stari termin je spet prost za novega gosta za isto mizo.
        $other = $this->create(['table_ids' => [$this->t2], 'starts_at' => $this->date . 'T18:00', 'duration_min' => 120]);
        $this->assertSame([$this->t2], $other['table_ids']);
        $events = $this->staff()->get($r['id'])['events'];
        $this->assertSame('moved', $events[1]['event']);
        $this->assertTrue(str_contains($events[1]['after']['starts_at'], 'T20:00'));
    }

    public function testFailedMoveRestoresOriginalSlots(): void
    {
        $a = $this->create(['party_size' => 2]);                                   // T2 19:00
        $this->create(['party_size' => 5, 'starts_at' => $this->date . 'T21:00']); // T6 21:00
        $this->create(['party_size' => 3, 'starts_at' => $this->date . 'T21:00']); // T2 21:00
        $this->expectThrows(AppException::class,
            fn() => $this->staff()->update($a['id'], ['starts_at' => $this->date . 'T21:00']), 'slot_taken');
        $this->assertSame(6, $this->fx->count('table_slots', 'reservation_id = ?', [$a['id']]));
        $this->assertSame([$this->t2], $this->staff()->get($a['id'])['table_ids']);
    }

    public function testGrowingPartyMovesToBiggerTable(): void
    {
        $r = $this->create(['party_size' => 3]);
        $u = $this->staff()->update($r['id'], ['party_size' => 6]);
        $this->assertSame([$this->t6], $u['table_ids']);
        $this->assertSame(6, $u['party_size']);
    }

    public function testContactUpdateIsValidatedAndAudited(): void
    {
        $r = $this->create();
        $this->expectThrows(AppException::class, fn() => $this->staff()->update($r['id'], ['guest_phone' => 'abc']), 'invalid_input');
        $u = $this->staff()->update($r['id'], ['notes' => "Alergija:\noreščki"]);
        $this->assertSame('Alergija: oreščki', $u['notes']);
        $events = $this->staff()->get($r['id'])['events'];
        $this->assertSame('updated', $events[1]['event']);
        $this->assertSame(['notes'], array_keys($events[1]['after']));
    }

    public function testStatusTransitionsAndSlotRelease(): void
    {
        $r = $this->create(['party_size' => 4]);
        $this->create(['party_size' => 4]); // T6
        $this->staff()->changeStatus($r['id'], 'cancelled', 'gost poklical');
        $this->assertSame(0, $this->fx->count('table_slots', 'reservation_id = ?', [$r['id']]));
        // Miza je spet prosta.
        $this->assertSame([$this->t2], $this->create(['party_size' => 4])['table_ids']);
        $this->expectThrows(AppException::class, fn() => $this->staff()->changeStatus($r['id'], 'arrived'), 'invalid_transition');
        $this->expectThrows(AppException::class, fn() => $this->staff()->update($r['id'], ['party_size' => 3]), 'invalid_transition');
        $this->expectThrows(AppException::class, fn() => $this->staff()->changeStatus($r['id'], 'izmisljeno'), 'invalid_input');
    }

    public function testArrivedThenCompletedFreesTable(): void
    {
        $r = $this->create();
        $s = $this->staff();
        $s->changeStatus($r['id'], 'arrived');
        $this->assertSame(6, $this->fx->count('table_slots', 'reservation_id = ?', [$r['id']]));
        $s->changeStatus($r['id'], 'completed');
        $this->assertSame(0, $this->fx->count('table_slots', 'reservation_id = ?', [$r['id']]));
    }

    public function testDayAndAvailability(): void
    {
        $this->create(['party_size' => 4]);
        $this->create(['party_size' => 4]);
        $day = $this->staff()->day($this->loc, $this->date);
        $this->assertCount(2, $day['reservations']);
        $this->assertCount(2, $day['tables']);

        $starts = $this->staff()->availability($this->loc, $this->date, 4);
        $times = array_map(fn($s) => substr($s, 11, 5), $starts);
        $this->assertFalse(in_array('19:00', $times, true));
        $this->assertFalse(in_array('17:45', $times, true), '17:45-19:15 se prekriva');
        $this->assertTrue(in_array('17:30', $times, true), '17:30-19:00 se konča pravočasno');
        $this->assertTrue(in_array('20:30', $times, true));
    }

    public function testSearchByPhoneAndName(): void
    {
        $this->create();
        $this->create(['guest_name' => 'Bojan Kos', 'guest_phone' => '+386 31 111 222', 'starts_at' => $this->date . 'T12:00']);
        $this->assertCount(1, $this->staff()->search('031 111 222', null));
        $this->assertCount(1, $this->staff()->search(null, 'Boj'));
        $this->assertCount(0, $this->staff()->search(null, '%%'));
        $this->expectThrows(AppException::class, fn() => $this->staff()->search(null, 'a'), 'invalid_input');
    }

    public function testCompositeForeignKeyRejectsCrossTenantRows(): void
    {
        $r = $this->create();
        $otherTenant = $this->fx->tenant('B');
        $otherLoc = $this->fx->location($otherTenant);
        $foreignTable = $this->fx->table($otherTenant, $otherLoc, 'B1', 1, 4);
        // Rezervacija tenanta A z mizo tenanta B: baza zavrne ne glede na kodo.
        $e = $this->expectThrows(PDOException::class, fn() => $this->db->run(
            'INSERT INTO table_slots (table_id, slot_start, tenant_id, reservation_id) VALUES (?, ?, ?, ?)',
            [$foreignTable, '2030-01-01 10:00:00', $this->tenant, $r['id']]
        ));
        $this->assertSame(1452, Database::errorNumber($e));
    }
}
