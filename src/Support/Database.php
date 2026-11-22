<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Tanek ovoj okoli PDO.
 *
 * Uporabljajo ga samo repositoryji. Nastavi tri stvari, od katerih je
 * odvisna pravilnost: UTC na povezavi, strog sql_mode in pravi pripravljeni
 * stavki (ne emulirani).
 */
final class Database
{
    /** MySQL koda za podvojen unikatni ključ. */
    public const ERR_DUPLICATE = 1062;
    private const ERR_DEADLOCK = 1213;
    private const ERR_LOCK_WAIT = 1205;

    private int $depth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{host:string, port?:int, name:string, user:string, pass:string, charset?:string} $c
     */
    public static function connect(array $c): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            $c['port'] ?? 3306,
            $c['name'],
            $c['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Vsi časi v bazi so UTC; CURRENT_TIMESTAMP mora biti tudi.
        $pdo->exec("SET time_zone = '+00:00'");
        // Brez strogega načina MySQL tiho odreže predolg niz ali zapiše 0000-00-00.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return new self($pdo);
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Izvede $fn v transakciji READ COMMITTED.
     *
     * Zakaj READ COMMITTED: pisci najprej zaklenejo vrstico lokacije. Pri
     * REPEATABLE READ bi navadno branje po čakanju na zaklep lahko videlo
     * posnetek izpred čakanja in spregledalo rezervacijo, ki jo je tekmec
     * medtem zapisal. Pri READ COMMITTED vsak stavek vidi zadnje potrjeno.
     *
     * Deadlock ali potek čakanja na zaklep: ponovi največ $retries-krat.
     * Gnezdeni klic teče v zunanji transakciji.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = 2): mixed
    {
        if ($this->depth > 0) {
            return $fn();
        }

        $attempt = 0;
        while (true) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->pdo->beginTransaction();
            $this->depth = 1;
            try {
                $result = $fn();
                $this->pdo->commit();
                $this->depth = 0;
                return $result;
            } catch (Throwable $e) {
                $this->depth = 0;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt < $retries && self::isRetryable($e)) {
                    $attempt++;
                    usleep(random_int(5_000, 30_000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function errorNumber(Throwable $e): ?int
    {
        if ($e instanceof PDOException && isset($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }
        return null;
    }

    /** Ime ključa iz sporočila 1062 ("... for key 'reservations.uq_res_idem'"). */
    public static function duplicateKeyName(Throwable $e): ?string
    {
        if (self::errorNumber($e) !== self::ERR_DUPLICATE) {
            return null;
        }
        if (preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $e->getMessage(), $m)) {
            return $m[1];
        }
        return '';
    }

    private static function isRetryable(Throwable $e): bool
    {
        $n = self::errorNumber($e);
        return $n === self::ERR_DEADLOCK || $n === self::ERR_LOCK_WAIT;
    }
}
