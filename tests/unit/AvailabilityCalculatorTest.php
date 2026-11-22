<?php

declare(strict_types=1);

use App\Domain\AppException;
use App\Domain\ClosurePeriod;
use App\Domain\DiningTable;
use App\Domain\Location;
use App\Domain\OpeningWindow;
use App\Domain\TableCombo;
use App\Service\AvailabilityCalculator;
use App\Support\Clock;

final class AvailabilityCalculatorTest extends TestCase
{
    private function location(array $o = []): Location
    {
        return new Location(
            id: 1, tenantId: 1, name: 'Test', timezone: 'Europe/Ljubljana', phonePublic: null,
            slotMinutes: $o['slot'] ?? 15,
            defaultDurationMin: $o['duration'] ?? 90,
            maxPartySize: $o['max_party'] ?? 10,
            minLeadMinutes: $o['lead'] ?? 60,
            maxDaysAhead: $o['ahead'] ?? 60,
            allowTableMerge: $o['merge'] ?? false,
            phoneCountryCode: '386',
        );
    }

    /** Sreda 2. 12. 2026, 10:00 lokalno (CET = UTC+1). */
    private function calc(array $o = [], string $nowUtc = '2026-12-02 09:00:00'): AvailabilityCalculator
    {
        return new AvailabilityCalculator($this->location($o), Clock::fixed($nowUtc));
    }

    /** @return list<OpeningWindow> vsak dan 12:00-15:00 in 18:00-22:00, zadnji začetek 14:00 in 21:00 */
    private function windows(): array
    {
        $w = [];
        for ($d = 1; $d <= 7; $d++) {
            $w[] = new OpeningWindow($d, '12:00', '14:00', '15:00');
            $w[] = new OpeningWindow($d, '18:00', '21:00', '22:00');
        }
        return $w;
    }

    private function tables(): array
    {
        return [
            new DiningTable(1, 'A', 1, 2),
            new DiningTable(2, 'B', 2, 4),
            new DiningTable(3, 'C', 4, 6),
        ];
    }

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function testDurationRoundsUpToSlot(): void
    {
        $c = $this->calc();
        $this->assertSame(90, $c->durationFor(null));
        $this->assertSame(105, $c->durationFor(100));
        $this->expectThrows(AppException::class, fn() => $c->durationFor(5), 'invalid_input');
        $this->expectThrows(AppException::class, fn() => $c->durationFor(600), 'invalid_input');
    }

    public function testSlotsCoverHalfOpenInterval(): void
    {
        $slots = $this->calc()->slotsFor($this->utc('2026-12-05 18:00:00'), 45);
        $this->assertSame(['2026-12-05 18:00:00', '2026-12-05 18:15:00', '2026-12-05 18:30:00'], $slots);
    }

    public function testParseLocalTimeConvertsToUtc(): void
    {
        $c = $this->calc();
        // December: CET, UTC+1
        $this->assertSame('2026-12-05 18:30:00', $c->parseStart('2026-12-05T19:30')->format('Y-m-d H:i:s'));
        // Julij: CEST, UTC+2
        $this->assertSame('2026-07-05 17:30:00', $c->parseStart('2026-07-05 19:30')->format('Y-m-d H:i:s'));
        // Z odmikom: odmik velja, ne lokalni čas.
        $this->assertSame('2026-12-05 19:30:00', $c->parseStart('2026-12-05T19:30:00Z')->format('Y-m-d H:i:s'));
        $this->expectThrows(AppException::class, fn() => $c->parseStart('5. 12. ob 19h'), 'invalid_input');
        $this->expectThrows(AppException::class, fn() => $c->parseStart('2026-02-30T19:30'), 'invalid_input');
    }

    public function testNonexistentDstTimeIsRejected(): void
    {
        // 29. 3. 2026 ob 02:00 ure skočijo na 03:00; 02:30 ne obstaja.
        $this->expectThrows(AppException::class,
            fn() => $this->calc()->parseStart('2026-03-29T02:30'), 'invalid_input');
    }

    public function testRulesAcceptValidStart(): void
    {
        // Sobota 5. 12. 19:00 lokalno = 18:00 UTC
        $this->calc()->checkRules($this->utc('2026-12-05 18:00:00'), 2, 90, $this->windows(), []);
        $this->assertTrue(true);
    }

    public function testOutsideHoursAndAfterLastSeating(): void
    {
        $c = $this->calc();
        // 16:00 lokalno: med okni
        $this->expectThrows(AppException::class,
            fn() => $c->checkRules($this->utc('2026-12-05 15:00:00'), 2, 90, $this->windows(), []), 'outside_hours');
        // 21:15 lokalno: po zadnjem začetku
        $this->expectThrows(AppException::class,
            fn() => $c->checkRules($this->utc('2026-12-05 20:15:00'), 2, 90, $this->windows(), []), 'outside_hours');
        // 21:00 lokalno: zadnji začetek je dovoljen
        $c->checkRules($this->utc('2026-12-05 20:00:00'), 2, 90, $this->windows(), []);
    }

    public function testStaffCannotBypassHours(): void
    {
        $this->expectThrows(AppException::class,
            fn() => $this->calc()->checkRules($this->utc('2026-12-05 15:00:00'), 2, 90, $this->windows(), [], true),
            'outside_hours');
    }

    public function testMinLeadAndStaffOverride(): void
    {
        $c = $this->calc(); // zdaj 10:00 lokalno, min_lead 60
        // 12:00 lokalno je 2 h vnaprej: v redu
        $c->checkRules($this->utc('2026-12-02 11:00:00'), 2, 90, $this->windows(), []);
        // 10:45 bi bil premalo vnaprej, a je tudi izven urnika; preizkus z odprtim urnikom:
        $c2 = $this->calc([], '2026-12-02 11:30:00'); // 12:30 lokalno
        $this->expectThrows(AppException::class,
            fn() => $c2->checkRules($this->utc('2026-12-02 12:00:00'), 2, 90, $this->windows(), []), 'too_soon');
        // Osebje sme takoj (gost je prišel)
        $c2->checkRules($this->utc('2026-12-02 11:30:00'), 2, 90, $this->windows(), [], true);
        // A ne v preteklost pred tekočo rezino
        $this->expectThrows(AppException::class,
            fn() => $c2->checkRules($this->utc('2026-12-02 11:00:00'), 2, 90, $this->windows(), [], true), 'too_soon');
    }

    public function testTooFarAhead(): void
    {
        $c = $this->calc(['ahead' => 30]);
        $this->expectThrows(AppException::class,
            fn() => $c->checkRules($this->utc('2027-01-05 18:00:00'), 2, 90, $this->windows(), []), 'too_far');
        $c->checkRules($this->utc('2027-01-05 18:00:00'), 2, 90, $this->windows(), [], true);
    }

    public function testPartyTooLargeForPhoneButNotStaff(): void
    {
        $c = $this->calc(['max_party' => 6]);
        $this->expectThrows(AppException::class,
            fn() => $c->checkRules($this->utc('2026-12-05 18:00:00'), 7, 90, $this->windows(), []), 'party_too_large');
        $c->checkRules($this->utc('2026-12-05 18:00:00'), 7, 90, $this->windows(), [], true);
        $this->expectThrows(AppException::class,
            fn() => $c->checkRules($this->utc('2026-12-05 18:00:00'), 0, 90, $this->windows(), []), 'invalid_input');
    }

    public function testStartMustBeOnSlotGrid(): void
    {
        $this->expectThrows(AppException::class,
            fn() => $this->calc()->checkRules($this->utc('2026-12-05 18:10:00'), 2, 90, $this->windows(), []),
            'invalid_input');
    }

    public function testClosureOverlapBlocks(): void
    {
        // Zaprto od 20:00 lokalno; rezervacija 19:00-20:30 se prekriva.
        $closure = new ClosurePeriod($this->utc('2026-12-05 19:00:00'), $this->utc('2026-12-06 00:00:00'));
        $this->expectThrows(AppException::class,
            fn() => $this->calc()->checkRules($this->utc('2026-12-05 18:00:00'), 2, 90, $this->windows(), [$closure]),
            'closed');
        // 18:00-19:30 lokalno se konča pred zaprtjem.
        $this->calc()->checkRules($this->utc('2026-12-05 17:00:00'), 2, 90, $this->windows(), [$closure]);
    }

    public function testChoosesSmallestFittingTable(): void
    {
        $c = $this->calc();
        $s = $this->utc('2026-12-05 18:00:00');
        $this->assertSame([1], $c->chooseTables($s, 90, 2, $this->tables(), [], []));
        $this->assertSame([2], $c->chooseTables($s, 90, 3, $this->tables(), [], []));
        $this->assertSame([3], $c->chooseTables($s, 90, 5, $this->tables(), [], []));
        $this->assertSame(null, $c->chooseTables($s, 90, 7, $this->tables(), [], []));
    }

    public function testMinSeatsKeepsBigTableForBigGroups(): void
    {
        // Miza C ima min 4: dvojica je ne dobi, tudi če sta A in B zasedeni.
        $c = $this->calc();
        $s = $this->utc('2026-12-05 18:00:00');
        $busy = [1 => ['2026-12-05 18:30:00' => true], 2 => ['2026-12-05 19:00:00' => true]];
        $this->assertSame(null, $c->chooseTables($s, 90, 2, $this->tables(), [], $busy));
    }

    public function testPartialOverlapMakesTableBusy(): void
    {
        $c = $this->calc();
        // A zaseden od 19:15 (zadnja rezina rezervacije 18:00-19:30 je 19:15)
        $busy = [1 => ['2026-12-05 19:15:00' => true]];
        $this->assertSame([2], $c->chooseTables($this->utc('2026-12-05 18:00:00'), 90, 2, $this->tables(), [], $busy));
        // Rezervacija, ki se konča točno ob 19:15, ni v sporu.
        $this->assertSame([1], $c->chooseTables($this->utc('2026-12-05 18:00:00'), 75, 2, $this->tables(), [], $busy));
    }

    public function testInactiveTableIsIgnored(): void
    {
        $tables = [new DiningTable(1, 'A', 1, 2, false), new DiningTable(2, 'B', 2, 4)];
        $this->assertSame([2], $this->calc()->chooseTables($this->utc('2026-12-05 18:00:00'), 90, 2, $tables, [], []));
    }

    public function testCombosOnlyWhenAllowed(): void
    {
        $combo = new TableCombo(1, 8, [3, 2]);
        $s = $this->utc('2026-12-05 18:00:00');
        $this->assertSame(null, $this->calc()->chooseTables($s, 90, 8, $this->tables(), [$combo], []));
        $this->assertSame([2, 3], $this->calc(['merge' => true])->chooseTables($s, 90, 8, $this->tables(), [$combo], []));
        // Posamezna miza ima prednost pred kombinacijo.
        $this->assertSame([3], $this->calc(['merge' => true])->chooseTables($s, 90, 6, $this->tables(), [$combo], []));
        // Kombinacija z zasedenim članom ne pride v poštev.
        $busy = [2 => ['2026-12-05 18:00:00' => true]];
        $this->assertSame(null, $this->calc(['merge' => true])->chooseTables($s, 90, 8, $this->tables(), [$combo], $busy));
    }

    public function testRequestedTablesByStaff(): void
    {
        $c = $this->calc();
        $s = $this->utc('2026-12-05 18:00:00');
        $this->assertSame([1, 2], $c->chooseTables($s, 90, 6, $this->tables(), [], [], [2, 1]));
        $this->expectThrows(AppException::class,
            fn() => $c->chooseTables($s, 90, 3, $this->tables(), [], [], [1]), 'table_too_small');
        $this->expectThrows(AppException::class,
            fn() => $c->chooseTables($s, 90, 2, $this->tables(), [], [], [99]), 'invalid_input');
        $busy = [1 => ['2026-12-05 18:45:00' => true]];
        $this->assertSame(null, $c->chooseTables($s, 90, 2, $this->tables(), [], $busy, [1]));
    }

    public function testFreeStartsForDay(): void
    {
        $c = $this->calc();
        $free = $c->freeStarts('2026-12-05', 2, 90, $this->windows(), [], $this->tables(), [], []);
        // 12:00-14:00 in 18:00-21:00 po 15 min: 9 + 13 = 22
        $this->assertCount(22, $free);
        $this->assertSame('2026-12-05T12:00:00+01:00', $c->toLocalIso($free[0]));
        $this->assertSame('2026-12-05T21:00:00+01:00', $c->toLocalIso($free[21]));
    }

    public function testFreeStartsSkipsBusyAndPastTimes(): void
    {
        // Ena miza, zasedena 19:00-20:30 lokalno.
        $tables = [new DiningTable(1, 'A', 1, 4)];
        $busy = [1 => []];
        foreach (['18:00', '18:15', '18:30', '18:45', '19:00', '19:15'] as $t) {
            $busy[1]['2026-12-05 ' . $t . ':00'] = true;
        }
        $c = $this->calc();
        $free = array_map(fn($d) => substr($c->toLocalIso($d), 11, 5),
            $c->freeStarts('2026-12-05', 2, 90, $this->windows(), [], $tables, [], $busy));
        $this->assertFalse(in_array('18:00', $free, true), '18:00 se prekriva (do 19:30 > 19:00)');
        $this->assertFalse(in_array('20:15', $free, true), '20:15 je še zasedeno');
        $this->assertTrue(in_array('17:30', $free, true) === false, 'izven urnika');
        $this->assertTrue(in_array('20:30', $free, true), '20:30 je prosto');

        // Danes ob 13:30 lokalno: opoldanski termini do 14:30 so premalo vnaprej.
        $c2 = $this->calc([], '2026-12-02 12:30:00');
        $today = array_map(fn($d) => substr($c2->toLocalIso($d), 11, 5),
            $c2->freeStarts('2026-12-02', 2, 90, $this->windows(), [], $this->tables(), [], []));
        $this->assertSame('18:00', $today[0]);
    }

    public function testFreeStartsOnDstChangeDay(): void
    {
        // Noč 25. 10. 2026: 03:00 CEST -> 02:00 CET. Okno 00:00-04:00 ima podvojeno uro.
        $w = [new OpeningWindow(7, '00:00', '04:00', '05:00')];
        $c = $this->calc(['lead' => 0], '2026-10-20 10:00:00');
        $free = $c->freeStarts('2026-10-25', 2, 30, $w, [], $this->tables(), [], []);
        $utc = array_map(fn($d) => $d->format('H:i'), $free);
        // Vsak UTC začetek največ enkrat.
        $this->assertSame(count($utc), count(array_unique($utc)));
        // 29. 3. 2026: 02:00-02:45 lokalno ne obstaja in ga ni v seznamu.
        $w2 = [new OpeningWindow(7, '01:00', '04:00', '05:00')];
        $c2 = $this->calc(['lead' => 0], '2026-03-20 10:00:00');
        $local = array_map(fn($d) => substr($c2->toLocalIso($d), 11, 5),
            $c2->freeStarts('2026-03-29', 2, 30, $w2, [], $this->tables(), [], []));
        $this->assertFalse(in_array('02:30', $local, true));
        $this->assertTrue(in_array('03:00', $local, true));
    }

    public function testNearestAlternatives(): void
    {
        $free = array_map(fn($t) => $this->utc('2026-12-05 ' . $t), ['17:00', '18:00', '19:30', '20:00', '20:30']);
        $near = AvailabilityCalculator::nearest($free, $this->utc('2026-12-05 19:00'), 3);
        $this->assertSame(['18:00', '19:30', '20:00'], array_map(fn($d) => $d->format('H:i'), $near));
    }
}
