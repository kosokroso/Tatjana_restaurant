<?php
/**
 * Skupni zagon za API, cron in teste.
 *
 * Autoload je ročni PSR-4 (App\ -> src/), ker Composerja na strežniku ni.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    // Brez '..' in podobnega: ime razreda je vedno iz kode, a pot naj bo
    // vseeno omejena na src/.
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Prebere config/config.php. Manjkajoča datoteka je napaka namestitve,
 * ne razlog za tihe privzete vrednosti.
 *
 * @return array<string, mixed>
 */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = dirname(__DIR__) . '/config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Manjka config/config.php (glej config.example.php).');
        }
        $config = require $file;
    }
    return $config;
}
