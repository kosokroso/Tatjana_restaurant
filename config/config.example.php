<?php
/**
 * Primer konfiguracije. Kopiraj v config/config.php (ni v gitu) in izpolni.
 *
 * Tu je samo okolje: poverilnice, poti, stikala. Pravila restavracij
 * (urnik, trajanje obroka, max oseb) so v bazi, ne tu.
 */

declare(strict_types=1);

return [
    // 'production' izklopi podrobnosti napak v odgovorih in v izpisu.
    'env' => 'production',

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Izven public/. Mapa mora biti zapisljiva za PHP.
    'log_dir' => __DIR__ . '/../logs',
    'log_mask_pii' => true,
    'log_retention_days' => 14,

    // Zahtevaj HTTPS za API. Lokalno lahko false.
    'require_https' => true,
];
