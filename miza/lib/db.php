<?php
declare(strict_types=1);

function config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        if (!file_exists($file)) $file = __DIR__ . '/../config.sample.php';
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Ljubljana');
    }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = config();
    if ($c['driver'] === 'sqlite') {
        $dir = dirname($c['sqlite_path']);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $c['sqlite_path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $m = $c['mysql'];
        $pdo = new PDO("mysql:host={$m['host']};dbname={$m['dbname']};charset={$m['charset']}", $m['user'], $m['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function insert(string $table, array $data): int {
    $cols = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $wp = []): void {
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $wp));
}

function now_str(): string { return date('Y-m-d H:i:s'); }

function default_settings(): array {
    return [
        'slot_minutes'         => 15,
        'buffer_minutes'       => 15,
        'durations'            => [
            ['max_party' => 2,  'minutes' => 90],
            ['max_party' => 4,  'minutes' => 105],
            ['max_party' => 6,  'minutes' => 120],
            ['max_party' => 99, 'minutes' => 150],
        ],
        'max_covers_per_slot'  => 0,   // 0 = brez omejitve
        'max_party_online'     => 8,   // večje skupine Tatjana preveže na osebje
        'days_ahead'           => 60,
        'min_notice_minutes'   => 30,
        'zones'                => ['Notri', 'Terasa'],
        'pickup_enabled'       => true,
        'pickup_prep_minutes'  => 20,
        'pickup_slot_minutes'  => 15,
        'max_orders_per_slot'  => 4,
        'sms_enabled'          => false,
        'ai_greeting'          => 'Pozdravljeni, tukaj Tatjana, digitalna asistentka restavracije.',
    ];
}

function restaurant_settings(array $restaurant): array {
    $s = json_decode($restaurant['settings'] ?? '{}', true) ?: [];
    return array_merge(default_settings(), $s);
}

function random_code(int $len = 6): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function normalize_phone(string $p): string {
    $p = preg_replace('/[^\d+]/', '', $p);
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if (str_starts_with($p, '0')) $p = '+386' . substr($p, 1);
    return $p;
}
