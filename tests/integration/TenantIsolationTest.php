<?php

declare(strict_types=1);

use App\Domain\TenantContext;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Routes;
use App\Http\DenyAllAuthenticator;
use App\Service\ReservationService;
use App\Support\Clock;
use App\Support\Database;

/**
 * Restavracija A nikoli ne vidi ali spremeni podatkov B.
 *
 * Preizkus gre skozi pravi Kernel in vse poti iz Routes. Za vsako pot mora
 * obstajati zahtevek v $requests; nova pot brez njega test podre.
 */
final class TenantIsolationTest extends TestCase
{
    private Database $db;
    private Fixtures $fx;

    public function setUp(): void
    {
        if (!TestDb::available()) {
            $this->skip(TestDb::whyUnavailable());
        }
        $this->db = TestDb::fresh();
        $this->fx = new Fixtures($this->db);
    }

    public function testTenantACannotReachTenantBThroughAnyRoute(): void
    {
        $a = $this->fx->tenant('A');
        $locA = $this->fx->location($a);
        $this->fx->table($a, $locA, 'A1', 1, 4);

        $b = $this->fx->tenant('B');
        $locB = $this->fx->location($b);
        $this->fx->table($b, $locB, 'B1', 1, 4);

        $date = Fixtures::dateIn(7);
        $resB = (new ReservationService($this->db, TenantContext::staff($b, 99, 'manager'), new Clock()))
            ->create(Fixtures::guest(['location_id' => $locB, 'starts_at' => $date . 'T19:00', 'guest_phone' => '041 999 888']), null)['reservation'];

        $snapshot = fn() => $this->db->all('SELECT id, status, starts_at, party_size, guest_name, guest_phone FROM reservations ORDER BY id');
        $before = $snapshot();

        $ctxA = TenantContext::staff($a, 1, 'manager');
        $kernel = new Kernel(
            Routes::build(fn(TenantContext $c) => new ReservationService($this->db, $c, new Clock())),
            new FixedTenantAuth($ctxA),
            new DenyAllAuthenticator(),
            null,
            false,
        );

        $id = $resB['id'];
        $requests = [
            'GET /api/v1/staff/day'                       => ['GET', '/api/v1/staff/day', ['location' => (string) $locB, 'date' => $date], ''],
            'GET /api/v1/staff/availability'              => ['GET', '/api/v1/staff/availability', ['location' => (string) $locB, 'date' => $date, 'party' => '2'], ''],
            'GET /api/v1/staff/reservations'              => ['GET', '/api/v1/staff/reservations', ['phone' => '041 999 888'], ''],
            'POST /api/v1/staff/reservations'             => ['POST', '/api/v1/staff/reservations', [], json_encode(Fixtures::guest(['location_id' => $locB, 'starts_at' => $date . 'T20:00']))],
            'GET /api/v1/staff/reservations/{id}'         => ['GET', "/api/v1/staff/reservations/{$id}", [], ''],
            'PATCH /api/v1/staff/reservations/{id}'       => ['PATCH', "/api/v1/staff/reservations/{$id}", [], '{"party_size":3,"guest_name":"Vdor"}'],
            'POST /api/v1/staff/reservations/{id}/status' => ['POST', "/api/v1/staff/reservations/{$id}/status", [], '{"status":"cancelled"}'],
        ];

        foreach (Routes::build(fn() => null)->routes() as $route) {
            $key = $route['method'] . ' ' . $route['pattern'];
            $this->assertTrue(isset($requests[$key]), "pot {$key} nima zahtevka v testu izolacije");
            [$m, $path, $query, $body] = $requests[$key];
            $res = $kernel->handle(new Request($m, $path, $query, rawBody: $body, https: true));

            if ($key === 'GET /api/v1/staff/reservations') {
                // Iskanje ni napaka, a ne sme vrniti ničesar od B.
                $this->assertSame(200, $res->status, $key);
                $this->assertSame([], $res->body['data'], $key);
            } else {
                $this->assertSame(404, $res->status, "{$key}: " . json_encode($res->body));
            }
            $this->assertFalse(str_contains(json_encode($res->body), '41999888'), "{$key} je razkril telefon B");
        }

        $this->assertSame($before, $snapshot(), 'baza se ne sme spremeniti');
    }
}

final class FixedTenantAuth implements App\Http\Authenticator
{
    public function __construct(private readonly TenantContext $ctx)
    {
    }

    public function authenticate(Request $request): ?TenantContext
    {
        return $this->ctx;
    }
}
