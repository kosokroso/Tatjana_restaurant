<?php
/**
 * Edini vstop za API. Vse poti /api/v1/* pridejo sem (public/.htaccess).
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use App\Domain\TenantContext;
use App\Http\DenyAllAuthenticator;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routes;
use App\Service\ReservationService;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Logger;

ini_set('display_errors', '0');

try {
    $config = app_config();
} catch (Throwable $e) {
    error_log('api: ' . $e->getMessage());
    Response::error(500, 'internal_error', 'Sistem ni nastavljen.')->send();
    exit;
}

// Povezava na bazo šele, ko jo kontroler potrebuje: 404 in 401 ne odpirata povezave.
$db = null;
$getDb = static function () use (&$db, $config): Database {
    return $db ??= Database::connect($config['db']);
};

$clock = new Clock();
$services = static fn(TenantContext $ctx): ReservationService => new ReservationService($getDb(), $ctx, $clock);

$kernel = new Kernel(
    Routes::build($services(...)),
    new DenyAllAuthenticator(),   // faza 2: seja osebja
    new DenyAllAuthenticator(),   // faza 3: ključ za Tatjano
    new Logger((string) $config['log_dir'], (bool) ($config['log_mask_pii'] ?? true), (int) ($config['log_retention_days'] ?? 14)),
    (bool) ($config['require_https'] ?? true),
);

$kernel->handle(Request::fromGlobals())->send();
