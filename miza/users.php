<?php
declare(strict_types=1);
// Upravljanje uporabnikov restavracije (prijava v aplikacijo).
require __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
function jout($d, int $c = 200): never { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (empty($_SESSION['uid'])) jout(['ok' => false, 'message' => 'Prijava potrebna.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_TM'] ?? '') !== '1') jout(['ok' => false, 'message' => 'Neveljavna zahteva.'], 403);

$rid = (int)$_SESSION['rid'];
$me = (int)$_SESSION['uid'];
$b = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$list = fn() => rows('SELECT id, email, name, role, created_at FROM users WHERE restaurant_id = ? ORDER BY id', [$rid]);

try {
    switch ($_GET['r'] ?? '') {
        case 'list':
            jout(['ok' => true, 'me' => $me, 'users' => $list()]);
        case 'create':
            $email = strtolower(trim((string)($b['email'] ?? '')));
            $pass = (string)($b['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'message' => 'Neveljaven e-naslov.'], 400);
            if (strlen($pass) < 8) jout(['ok' => false, 'message' => 'Geslo naj ima vsaj 8 znakov.'], 400);
            if (val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) jout(['ok' => false, 'message' => 'Ta e-naslov že obstaja.'], 409);
            insert('users', ['restaurant_id' => $rid, 'email' => $email, 'name' => trim((string)($b['name'] ?? '')) ?: null,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'admin', 'created_at' => now_str()]);
            jout(['ok' => true, 'me' => $me, 'users' => $list()]);
        case 'password':
            $id = (int)($b['id'] ?? 0);
            $pass = (string)($b['password'] ?? '');
            if (strlen($pass) < 8) jout(['ok' => false, 'message' => 'Geslo naj ima vsaj 8 znakov.'], 400);
            if (!row('SELECT id FROM users WHERE id = ? AND restaurant_id = ?', [$id, $rid])) jout(['ok' => false, 'message' => 'Uporabnik ne obstaja.'], 404);
            update('users', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)], 'id = ?', [$id]);
            jout(['ok' => true, 'me' => $me, 'users' => $list()]);
        case 'delete':
            $id = (int)($b['id'] ?? 0);
            if ($id === $me) jout(['ok' => false, 'message' => 'Samega sebe ne moreš izbrisati.'], 400);
            q('DELETE FROM users WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
            jout(['ok' => true, 'me' => $me, 'users' => $list()]);
    }
    jout(['ok' => false, 'message' => 'Neznana pot.'], 404);
} catch (Throwable $e) {
    error_log('[tatjana-miza users] ' . $e->getMessage());
    jout(['ok' => false, 'message' => 'Napaka na strežniku.'], 500);
}