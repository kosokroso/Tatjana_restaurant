<?php

declare(strict_types=1);

use App\Support\Database;

/**
 * Povezava na testno bazo in nalaganje sheme.
 *
 * Varovalo: ime baze se mora končati s "_test". Testi brišejo vse tabele;
 * brez tega pogoja bi napačna konfiguracija izbrisala produkcijo.
 */
final class TestDb
{
    private static ?array $config = null;

    /** @return array<string, mixed>|null */
    public static function config(): ?array
    {
        if (self::$config === null) {
            $file = __DIR__ . '/../config.test.php';
            self::$config = is_file($file) ? require $file : [];
        }
        return self::$config === [] ? null : self::$config;
    }

    public static function available(): bool
    {
        return self::config() !== null && extension_loaded('pdo_mysql');
    }

    public static function whyUnavailable(): string
    {
        if (!extension_loaded('pdo_mysql')) {
            return 'ni razširitve pdo_mysql (zaženi s -d extension=pdo_mysql)';
        }
        return 'ni tests/config.test.php (glej tests/config.test.example.php)';
    }

    public static function connect(): Database
    {
        $c = self::config();
        if ($c === null) {
            throw new RuntimeException(self::whyUnavailable());
        }
        if (!str_ends_with((string) $c['db']['name'], '_test')) {
            throw new RuntimeException('Testna baza se mora končati s "_test". Ustavljeno, da ne izbrišem prave baze.');
        }
        return Database::connect($c['db']);
    }

    /** Izbriše vse tabele testne baze in naloži sql/schema.sql. */
    public static function reset(Database $db): void
    {
        $db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
            $db->run('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $db->run('SET FOREIGN_KEY_CHECKS = 1');

        foreach (self::statements((string) file_get_contents(__DIR__ . '/../../sql/schema.sql')) as $sql) {
            $db->run($sql);
        }
    }

    private static bool $schemaLoaded = false;

    /**
     * Prazna baza z naloženo shemo. Shema se naloži enkrat na zagon tekača,
     * nato samo prazni tabele (hitreje prek oddaljene povezave).
     */
    public static function fresh(): Database
    {
        $db = self::connect();
        if (!self::$schemaLoaded) {
            self::reset($db);
            self::$schemaLoaded = true;
            return $db;
        }
        $db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
            $db->run('TRUNCATE TABLE `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $db->run('SET FOREIGN_KEY_CHECKS = 1');
        return $db;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql),
            static fn(string $l): bool => !str_starts_with(ltrim($l), '--')
        );
        $out = [];
        foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) as $stmt) {
            // Komentar na koncu vrstice (za "--") ni problem za MySQL.
            if (trim($stmt) !== '') {
                $out[] = trim($stmt);
            }
        }
        return $out;
    }

    /** @return list<string> argumenti za zagon PHP podprocesov (test sočasnosti) */
    public static function phpArgs(): array
    {
        return self::config()['php_args'] ?? [];
    }
}
