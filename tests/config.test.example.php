<?php
/**
 * Kopiraj v tests/config.test.php (ni v gitu) in izpolni.
 *
 * Testna baza je LOČENA od produkcijske. Ime se mora končati s "_test";
 * testi brišejo vse tabele in brez tega pogoja nočejo teči.
 *
 * cPanel: MySQL Databases -> nova baza <predpona>_rez_test in uporabnik
 * samo s pravicami na to bazo; Remote MySQL -> dodaj svoj javni IP.
 */

declare(strict_types=1);

return [
    'db' => [
        'host' => '',          // ime strežnika iz cPanela (ne "localhost")
        'port' => 3306,
        'name' => '',          // npr. predpona_rez_test
        'user' => '',
        'pass' => '',
    ],

    // Argumenti za PHP podprocese v testu sočasnosti. Lokalni php.ini ne
    // nalaga pdo_mysql, zato ga vklopimo tu.
    'php_args' => ['-d', 'extension=pdo_mysql'],

    // Deljeno gostovanje omejuje sočasne povezave (max_user_connections).
    'concurrency_workers' => 8,
    'concurrency_iterations' => 50,
];
