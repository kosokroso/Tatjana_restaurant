<?php
declare(strict_types=1);
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/booking.php';
require __DIR__ . '/lib/orders.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $code, string $msg, int $http = 400, array $extra = []): never {
    out(['ok' => false, 'error' => $code, 'message' => $msg] + $extra, $http);
}
function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw ? (json_decode($raw, true) ?: []) : [];
        $b = array_merge($_GET, $_POST, $b);
    }
    return $b;
}
function need(string $k) {
    $b = body();
    if (!isset($b[$k]) || $b[$k] === '') fail('missing_field', "Manjka polje: $k");
    return $b[$k];
}

$route = trim((string)($_GET['r'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ---------------- Tatjana (API ključ) ----------------
    if (str_starts_with($route, 'ai/')) {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!$key && preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $key = $m[1];
        $rest = $key ? row('SELECT * FROM restaurants WHERE api_key = ?', [$key]) : null;
        if (!$rest) fail('unauthorized', 'Neveljaven API ključ.', 401);
        $rid = (int)$rest['id'];
        $b = body();

        switch ($route) {
            case 'ai/info':
                $s = restaurant_settings($rest);
                $days = ['', 'ponedeljek', 'torek', 'sreda', 'četrtek', 'petek', 'sobota', 'nedelja'];
                $hours = [];
                foreach (rows('SELECT * FROM opening_hours WHERE restaurant_id = ? ORDER BY weekday, open_time', [$rid]) as $h) {
                    $hours[$h['kind']][$days[(int)$h['weekday']]][] = $h['open_time'] . ' do ' . $h['close_time'] . ($h['last_booking'] && $h['kind'] === 'dine' ? " (zadnja rezervacija {$h['last_booking']})" : '');
                }
                $special = rows('SELECT day, closed, note FROM special_days WHERE restaurant_id = ? AND day >= ? ORDER BY day LIMIT 20', [$rid, date('Y-m-d')]);
                out(['ok' => true,
                    'restaurant' => $rest['name'], 'phone' => $rest['phone'], 'address' => $rest['address'],
                    'now' => date('Y-m-d H:i'), 'weekday' => $days[(int)date('N')],
                    'greeting' => $s['ai_greeting'],
                    'zones' => $s['zones'], 'max_party_online' => $s['max_party_online'],
                    'pickup_enabled' => $s['pickup_enabled'],
                    'opening_hours' => $hours['dine'] ?? [], 'pickup_hours' => $hours['pickup'] ?? [],
                    'special_days' => $special,
                ]);
            case 'ai/availability':
                out(['ok' => true] + check_availability($rid, need('date'), need('time'), (int)need('party_size'), $b['zone'] ?? null));
            case 'ai/day_slots':
                out(['ok' => true, 'date' => need('date'), 'times' => day_slots($rid, need('date'), (int)need('party_size'), $b['zone'] ?? null)]);
            case 'ai/reservations':
                // Vir je vedno 'tatjana': telo zahtevka sestavi model in statistike
                // virov ne sme moči ponarediti. Brez telefona v telesu vzamemo
                // številko klicatelja, ki jo agent prebere iz SIP.
                $d = $b;
                if (empty($d['phone']) && !empty($b['caller_phone'])) $d['phone'] = $b['caller_phone'];
                unset($d['table_ids'], $d['force'], $d['duration_minutes']);
                $r = create_reservation($rid, $d, 'tatjana');
                out(['ok' => true, 'reservation' => ai_res($r)]);
            case 'ai/reservations/find':
                out(['ok' => true, 'reservations' => array_map('ai_res', ai_find($rid, $b))]);
            case 'ai/reservations/cancel':
                $r = ai_lookup($rid, $b);
                out(['ok' => true, 'reservation' => ai_res(cancel_reservation($rid, (int)$r['id']))]);
            case 'ai/reservations/change':
                $r = ai_lookup($rid, $b);
                out(['ok' => true, 'reservation' => ai_res(change_reservation($rid, (int)$r['id'], array_intersect_key($b, array_flip(['date', 'time', 'party_size', 'zone_pref', 'notes']))))]);
            case 'ai/menu':
                out(['ok' => true, 'menu' => menu_for_ai($rid)]);
            case 'ai/orders/quote':
                $desired = !empty($b['pickup_time']) ? (strlen($b['pickup_time']) <= 5 ? date('Y-m-d') . ' ' . $b['pickup_time'] : $b['pickup_time']) : null;
                out(['ok' => true] + quote_order($rid, $b['items'] ?? [], $desired));
            case 'ai/orders/find':
                $code = strtoupper(trim((string)($b['code'] ?? '')));
                $caller = trim((string)($b['caller_phone'] ?? ''));
                if ($code !== '') $found = find_orders_for_caller($rid, null, $code);
                elseif ($caller !== '' && preg_match('/\d{6,}/', $caller)) $found = find_orders_for_caller($rid, $caller, null);
                else throw new BookingError('missing_field', 'Povej številko naročila.');
                out(['ok' => true, 'orders' => $found]);
            case 'ai/orders':
                $d = $b;
                if (empty($d['phone']) && !empty($b['caller_phone'])) $d['phone'] = $b['caller_phone'];
                $o = create_order($rid, $d, 'tatjana');
                out(['ok' => true, 'order' => ['code' => $o['code'], 'pickup_at' => $o['pickup_at'], 'total_eur' => $o['total_cents'] / 100, 'items' => $o['items']]]);
        }
        fail('not_found', 'Neznana pot.', 404);
    }

    // ---------------- Admin (seja) ----------------
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();

    if ($route === 'auth/login') {
        $u = row('SELECT * FROM users WHERE email = ?', [strtolower(trim((string)need('email')))]);
        if (!$u || !password_verify((string)need('password'), $u['password_hash'])) fail('bad_login', 'Napačen e-naslov ali geslo.', 401);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['rid'] = (int)$u['restaurant_id'];
        out(['ok' => true]);
    }
    if (empty($_SESSION['uid'])) fail('unauthorized', 'Prijava potrebna.', 401);
    if ($method === 'POST' && ($_SERVER['HTTP_X_TM'] ?? '') !== '1') fail('csrf', 'Neveljavna zahteva.', 403);
    $rid = (int)$_SESSION['rid'];
    $b = body();

    switch ($route) {
        case 'auth/logout':
            session_destroy();
            out(['ok' => true]);
        case 'auth/me':
            $r = get_restaurant($rid);
            $u = row('SELECT id, email, name, role FROM users WHERE id = ?', [$_SESSION['uid']]);
            out(['ok' => true, 'user' => $u, 'restaurant' => ['id' => $rid, 'name' => $r['name']], 'settings' => restaurant_settings($r)]);

        // ---- mize ----
        case 'tables':
            out(['ok' => true, 'tables' => rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND active = 1 ORDER BY id', [$rid])]);
        case 'tables/save':
            // celoten tloris naenkrat; mize brez id so nove, manjkajoče se deaktivirajo
            $keep = [];
            foreach ((array)($b['tables'] ?? []) as $t) {
                $data = [
                    'name' => mb_substr(trim((string)($t['name'] ?? '?')), 0, 40),
                    'min_seats' => max(1, (int)($t['min_seats'] ?? 1)),
                    'max_seats' => max(1, (int)($t['max_seats'] ?? 4)),
                    'zone' => $t['zone'] ?? null,
                    'shape' => in_array($t['shape'] ?? '', ['round', 'square', 'rect'], true) ? $t['shape'] : 'square',
                    'x' => (int)($t['x'] ?? 0), 'y' => (int)($t['y'] ?? 0),
                    'w' => max(30, (int)($t['w'] ?? 80)), 'h' => max(30, (int)($t['h'] ?? 80)),
                    'combine_group' => ($t['combine_group'] ?? '') !== '' ? $t['combine_group'] : null,
                    'reservable' => !empty($t['reservable']) ? 1 : 0,
                    'features' => $t['features'] ?? null,
                    'priority' => (int)($t['priority'] ?? 0),
                ];
                if ($data['min_seats'] > $data['max_seats']) $data['min_seats'] = $data['max_seats'];
                if (!empty($t['id']) && row('SELECT id FROM dining_tables WHERE id = ? AND restaurant_id = ?', [(int)$t['id'], $rid])) {
                    update('dining_tables', $data, 'id = ?', [(int)$t['id']]);
                    $keep[] = (int)$t['id'];
                } else {
                    $keep[] = insert('dining_tables', $data + ['restaurant_id' => $rid, 'active' => 1]);
                }
            }
            $sql = 'UPDATE dining_tables SET active = 0 WHERE restaurant_id = ?';
            if ($keep) $sql .= ' AND id NOT IN (' . implode(',', $keep) . ')';
            q($sql, [$rid]);
            out(['ok' => true, 'tables' => rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND active = 1 ORDER BY id', [$rid])]);

        // ---- dnevni pregled ----
        case 'day':
            $date = (string)($b['date'] ?? date('Y-m-d'));
            $res = rows('SELECT * FROM reservations WHERE restaurant_id = ? AND start_at >= ? AND start_at < ? ORDER BY start_at', [$rid, "$date 00:00:00", dt($date)->modify('+1 day')->format('Y-m-d 00:00:00')]);
            $ord = rows('SELECT * FROM orders WHERE restaurant_id = ? AND pickup_at >= ? AND pickup_at < ? ORDER BY pickup_at', [$rid, "$date 00:00:00", dt($date)->modify('+1 day')->format('Y-m-d 00:00:00')]);
            $ints = array_map(fn($i) => [hm($i[0]), hm($i[1])], intervals_for($rid, 'dine', $date));
            out(['ok' => true, 'date' => $date, 'hours' => $ints,
                'tables' => rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND active = 1 ORDER BY id', [$rid]),
                'reservations' => array_map('reservation_out', $res),
                'orders' => array_map('order_out', $ord)]);

        // ---- rezervacije ----
        case 'availability':
            $s = restaurant_settings(get_restaurant($rid));
            $start = dt(need('date') . ' ' . need('time'));
            [$ok, $reason, $m, $dur] = evaluate_slot($rid, $s, $start, (int)need('party_size'), $b['zone'] ?? null, isset($b['exclude_id']) ? (int)$b['exclude_id'] : null, true);
            out(['ok' => true, 'available' => $ok, 'reason' => $reason, 'message' => $reason ? reason_text($reason) : null, 'duration_minutes' => $dur,
                'tables' => $m ? array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $m['tables']) : []]);
        case 'reservations/create':
            out(['ok' => true, 'reservation' => create_reservation($rid, $b, $b['source'] ?? 'manual', true)]);
        case 'reservations/update':
            out(['ok' => true, 'reservation' => change_reservation($rid, (int)need('id'), $b, true)]);
        case 'reservations/status':
            $st = (string)need('status');
            if (!in_array($st, ['confirmed', 'seated', 'completed', 'no_show', 'cancelled'], true)) fail('invalid', 'Neveljaven status.');
            $r = row('SELECT * FROM reservations WHERE id = ? AND restaurant_id = ?', [(int)need('id'), $rid]);
            if (!$r) fail('not_found', 'Rezervacija ne obstaja.', 404);
            $upd = ['status' => $st, 'updated_at' => now_str()];
            // ko gostje odidejo, sprosti mizo takoj
            if ($st === 'completed' && dt($r['end_at']) > new DateTimeImmutable()) $upd['end_at'] = now_str();
            update('reservations', $upd, 'id = ?', [(int)$r['id']]);
            out(['ok' => true, 'reservation' => reservation_out(row('SELECT * FROM reservations WHERE id = ?', [(int)$r['id']]))]);
        case 'reservations/new':
            // Nove rezervacije za obvestilo osebju (vse dni, ne samo prikazanega).
            // Brez after_id vrne samo trenutni najvišji id: ob prvem nalaganju
            // aplikacija ne sme oznanjati starih rezervacij kot novih.
            $max = (int)val('SELECT COALESCE(MAX(id),0) FROM reservations WHERE restaurant_id = ?', [$rid]);
            if (!isset($b['after_id'])) out(['ok' => true, 'max_id' => $max, 'reservations' => []]);
            $new = rows('SELECT * FROM reservations WHERE restaurant_id = ? AND id > ? ORDER BY id LIMIT 20', [$rid, (int)$b['after_id']]);
            out(['ok' => true, 'max_id' => $max, 'reservations' => array_map('reservation_out', $new)]);
        case 'reservations/list':
            $from = (string)($b['from'] ?? date('Y-m-d'));
            $to = (string)($b['to'] ?? dt($from)->modify('+30 days')->format('Y-m-d'));
            $sql = 'SELECT * FROM reservations WHERE restaurant_id = ? AND start_at >= ? AND start_at < ?';
            $p = [$rid, "$from 00:00:00", dt($to)->modify('+1 day')->format('Y-m-d 00:00:00')];
            if (!empty($b['q'])) { $sql .= ' AND (LOWER(name) LIKE ? OR phone LIKE ? OR code = ?)'; $qq = '%' . mb_strtolower($b['q']) . '%'; array_push($p, $qq, '%' . preg_replace('/\D/', '', $b['q']) . '%', strtoupper($b['q'])); }
            out(['ok' => true, 'reservations' => array_map('reservation_out', rows($sql . ' ORDER BY start_at LIMIT 500', $p))]);

        // ---- meni ----
        case 'menu':
            out(['ok' => true, 'menu' => get_menu($rid)]);
        case 'menu/category':
            if (!empty($b['delete'])) { q('DELETE FROM menu_categories WHERE id = ? AND restaurant_id = ?', [(int)$b['id'], $rid]); out(['ok' => true]); }
            $data = ['name' => trim((string)need('name')), 'sort' => (int)($b['sort'] ?? 0)];
            if (!empty($b['id'])) update('menu_categories', $data, 'id = ? AND restaurant_id = ?', [(int)$b['id'], $rid]);
            else insert('menu_categories', $data + ['restaurant_id' => $rid]);
            out(['ok' => true, 'menu' => get_menu($rid)]);
        case 'menu/item':
            if (!empty($b['delete'])) { q('DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?', [(int)$b['id'], $rid]); out(['ok' => true, 'menu' => get_menu($rid)]); }
            $data = [
                'category_id' => !empty($b['category_id']) ? (int)$b['category_id'] : null,
                'name' => trim((string)need('name')),
                'description' => $b['description'] ?? null,
                'price_cents' => (int)round(((float)str_replace(',', '.', (string)($b['price_eur'] ?? 0))) * 100),
                'allergens' => $b['allergens'] ?? null,
                'available' => !empty($b['available']) ? 1 : 0,
                'pickup' => !empty($b['pickup']) ? 1 : 0,
                'prep_minutes' => ($b['prep_minutes'] ?? '') !== '' ? (int)$b['prep_minutes'] : null,
                'sort' => (int)($b['sort'] ?? 0),
            ];
            if (!empty($b['id'])) {
                if (!row('SELECT id FROM menu_items WHERE id = ? AND restaurant_id = ?', [(int)$b['id'], $rid])) fail('not_found', 'Jed ne obstaja.', 404);
                update('menu_items', $data, 'id = ?', [(int)$b['id']]);
                $iid = (int)$b['id'];
            } else $iid = insert('menu_items', $data + ['restaurant_id' => $rid]);
            if (isset($b['option_groups'])) {
                q('DELETE FROM option_groups WHERE item_id = ?', [$iid]);
                foreach ((array)$b['option_groups'] as $gi => $g) {
                    if (trim((string)($g['name'] ?? '')) === '') continue;
                    $gid = insert('option_groups', ['item_id' => $iid, 'name' => trim($g['name']), 'required' => !empty($g['required']) ? 1 : 0, 'max_select' => max(1, (int)($g['max_select'] ?? 1)), 'sort' => $gi]);
                    foreach ((array)($g['options'] ?? []) as $oi => $o) {
                        if (trim((string)($o['name'] ?? '')) === '') continue;
                        insert('item_options', ['group_id' => $gid, 'name' => trim($o['name']), 'price_cents' => (int)round(((float)str_replace(',', '.', (string)($o['price_eur'] ?? 0))) * 100), 'sort' => $oi]);
                    }
                }
            }
            out(['ok' => true, 'menu' => get_menu($rid)]);
        case 'menu/toggle':
            $i = row('SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?', [(int)need('id'), $rid]);
            if (!$i) fail('not_found', 'Jed ne obstaja.', 404);
            update('menu_items', ['available' => (int)$i['available'] ? 0 : 1], 'id = ?', [(int)$i['id']]);
            out(['ok' => true, 'menu' => get_menu($rid)]);

        // ---- naročila ----
        case 'orders':
            $date = (string)($b['date'] ?? date('Y-m-d'));
            $ord = rows('SELECT * FROM orders WHERE restaurant_id = ? AND pickup_at >= ? AND pickup_at < ? ORDER BY pickup_at', [$rid, "$date 00:00:00", dt($date)->modify('+1 day')->format('Y-m-d 00:00:00')]);
            out(['ok' => true, 'orders' => array_map('order_out', $ord), 'latest_id' => (int)val('SELECT MAX(id) FROM orders WHERE restaurant_id = ?', [$rid])]);
        case 'orders/status':
            $st = (string)need('status');
            if (!in_array($st, ['new', 'preparing', 'ready', 'picked_up', 'cancelled'], true)) fail('invalid', 'Neveljaven status.');
            $o = row('SELECT * FROM orders WHERE id = ? AND restaurant_id = ?', [(int)need('id'), $rid]);
            if (!$o) fail('not_found', 'Naročilo ne obstaja.', 404);
            update('orders', ['status' => $st, 'updated_at' => now_str()], 'id = ?', [(int)$o['id']]);
            if ($st === 'ready' && $o['phone']) queue_sms($rid, $o['phone'], "Vaše naročilo {$o['code']} je pripravljeno za prevzem.");
            out(['ok' => true]);
        case 'orders/create':
            out(['ok' => true, 'order' => create_order($rid, $b, 'manual', true)]);
        case 'orders/quote':
            out(['ok' => true] + quote_order($rid, $b['items'] ?? [], null));

        // ---- nastavitve ----
        case 'settings':
            $r = get_restaurant($rid);
            out(['ok' => true,
                'restaurant' => ['name' => $r['name'], 'phone' => $r['phone'], 'address' => $r['address'], 'api_key' => $r['api_key']],
                'settings' => restaurant_settings($r),
                'hours' => rows('SELECT kind, weekday, open_time, close_time, last_booking FROM opening_hours WHERE restaurant_id = ? ORDER BY kind, weekday, open_time', [$rid]),
                'special_days' => rows('SELECT id, day, closed, note FROM special_days WHERE restaurant_id = ? AND day >= ? ORDER BY day', [$rid, date('Y-m-d')])]);
        case 'settings/save':
            if (isset($b['restaurant'])) update('restaurants', ['name' => trim((string)$b['restaurant']['name']), 'phone' => $b['restaurant']['phone'] ?? null, 'address' => $b['restaurant']['address'] ?? null], 'id = ?', [$rid]);
            if (isset($b['settings'])) {
                $allowed = array_keys(default_settings());
                $new = array_merge(restaurant_settings(get_restaurant($rid)), array_intersect_key((array)$b['settings'], array_flip($allowed)));
                update('restaurants', ['settings' => json_encode($new, JSON_UNESCAPED_UNICODE)], 'id = ?', [$rid]);
            }
            if (isset($b['hours'])) {
                q('DELETE FROM opening_hours WHERE restaurant_id = ?', [$rid]);
                foreach ((array)$b['hours'] as $h) {
                    if (!preg_match('/^\d\d:\d\d$/', $h['open_time'] ?? '') || !preg_match('/^\d\d:\d\d$/', $h['close_time'] ?? '')) continue;
                    insert('opening_hours', ['restaurant_id' => $rid, 'kind' => $h['kind'] === 'pickup' ? 'pickup' : 'dine', 'weekday' => (int)$h['weekday'],
                        'open_time' => $h['open_time'], 'close_time' => $h['close_time'], 'last_booking' => preg_match('/^\d\d:\d\d$/', $h['last_booking'] ?? '') ? $h['last_booking'] : null]);
                }
            }
            if (isset($b['special_days'])) {
                q('DELETE FROM special_days WHERE restaurant_id = ?', [$rid]);
                foreach ((array)$b['special_days'] as $d) {
                    if (!preg_match('/^\d{4}-\d\d-\d\d$/', $d['day'] ?? '')) continue;
                    insert('special_days', ['restaurant_id' => $rid, 'day' => $d['day'], 'closed' => !empty($d['closed']) ? 1 : 0, 'note' => $d['note'] ?? null]);
                }
            }
            out(['ok' => true]);
        case 'settings/new_key':
            $k = 'tm_' . bin2hex(random_bytes(20));
            update('restaurants', ['api_key' => $k], 'id = ?', [$rid]);
            out(['ok' => true, 'api_key' => $k]);
        case 'notifications':
            out(['ok' => true, 'notifications' => rows('SELECT * FROM notifications WHERE restaurant_id = ? ORDER BY id DESC LIMIT 50', [$rid])]);
    }
    fail('not_found', 'Neznana pot.', 404);
} catch (BookingError $e) {
    fail($e->codeName, $e->getMessage(), 409, $e->extra);
} catch (Throwable $e) {
    error_log('[tatjana-miza] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('server_error', 'Napaka na strežniku.', 500);
}

// ---- pomožne za AI ----
function ai_res(array $r): array {
    return [
        'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'],
        'date' => substr($r['start_at'], 0, 10), 'time' => substr($r['start_at'], 11, 5),
        'party_size' => $r['party_size'], 'status' => $r['status'],
        'tables' => array_column($r['tables'], 'name'), 'notes' => $r['notes'],
    ];
}
/**
 * Rezervacije, ki jih sme videti klicatelj: po kodi ali po njegovi številki.
 *
 * Nikoli po imenu in nikoli po telefonu, ki ga izreče klicatelj. 'caller_phone'
 * vpiše agent iz SIP povezave (sip.phoneNumber), model ga ne more nastaviti.
 * Brez tega bi klicatelj rekel "rezervacije na ime Novak" ali "na številko
 * 041 ..." in slišal ali odpovedal tuje rezervacije. Koda ima 6 naključnih
 * znakov (32^6), ugibanje po telefonu ni izvedljivo.
 */
function ai_find(int $rid, array $b): array {
    $code = strtoupper(trim((string)($b['code'] ?? '')));
    $caller = trim((string)($b['caller_phone'] ?? ''));
    if ($code !== '') return find_reservations($rid, null, $code);
    // Skrita številka ali "anonymous": brez kode ni iskanja.
    if ($caller !== '' && preg_match('/\d{6,}/', $caller)) return find_reservations($rid, $caller, null);
    throw new BookingError('missing_field', 'Povej kodo rezervacije iz SMS sporočila.');
}

function ai_lookup(int $rid, array $b): array {
    $found = ai_find($rid, $b);
    if (count($found) > 1 && !empty($b['original_date'])) {
        $found = array_values(array_filter($found, fn($r) => substr($r['start_at'], 0, 10) === $b['original_date']));
    }
    if (count($found) > 1) throw new BookingError('ambiguous', 'Na to številko je več rezervacij, povej datum ali kodo.', ['reservations' => array_map('ai_res', $found)]);
    $r = $found[0] ?? null;
    if (!$r) throw new BookingError('not_found', 'Rezervacije ne najdem.');
    return $r;
}
