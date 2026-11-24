<?php
// Kopiraj v config.php in izpolni.
return [
    // 'mysql' na cPanelu, 'sqlite' za lokalni test
    'driver'   => 'mysql',
    'mysql'    => [
        'host'    => 'localhost',
        'dbname'  => 'uporabnik_tatjanamiza',
        'user'    => 'uporabnik_tm',
        'pass'    => 'GESLO',
        'charset' => 'utf8mb4',
    ],
    'sqlite_path' => __DIR__ . '/data/tatjana.sqlite',
    'timezone'    => 'Europe/Ljubljana',
    // Geslo za enkratno namestitev (install.php). Po namestitvi izbriši install.php.
    'install_key' => 'spremeni-me',
];
