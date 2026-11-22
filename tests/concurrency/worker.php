<?php
/**
 * En tekmec v testu sočasnosti. Zažene ga ConcurrencyTest kot ločen proces.
 *
 *   php worker.php <tenant> <location> <starts_at> <party> <barrier_dir> <lock 1|0> <idempotency_key|->
 *
 * Najprej odpre povezavo in javi "ready", nato čaka na datoteko "go", da vsi
 * tekmeci udarijo hkrati. Izpiše eno vrstico JSON.
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../lib/TestDb.php';

use App\Domain\AppException;
use App\Domain\TenantContext;
use App\Service\ReservationService;
use App\Support\Clock;

[$_, $tenant, $location, $startsAt, $party, $barrier, $lock, $key] = $argv;

try {
    $db = TestDb::connect();
    $service = new ReservationService($db, TenantContext::staff((int) $tenant, 1, 'staff'), new Clock(), $lock === '1');

    touch($barrier . '/ready-' . getmypid());
    $deadline = microtime(true) + 30;
    while (!is_file($barrier . '/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('ni signala go');
        }
        usleep(500);
    }

    // Z ključem idempotentnosti simuliramo ponovljen isti zahtevek: telo mora biti enako.
    $same = $key !== '-';
    $r = $service->create([
        'location_id' => (int) $location,
        'starts_at'   => $startsAt,
        'party_size'  => (int) $party,
        'guest_name'  => $same ? 'Isti gost' : 'Tekmec ' . getmypid(),
        'guest_phone' => $same ? '041 000 000' : '041 000 ' . str_pad((string) (getmypid() % 1000), 3, '0', STR_PAD_LEFT),
    ], $same ? $key : null);

    echo json_encode(['ok' => true, 'id' => $r['reservation']['id'], 'replayed' => $r['replayed']]), "\n";
} catch (AppException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
