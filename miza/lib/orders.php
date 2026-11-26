<?php
declare(strict_types=1);

const ACTIVE_ORD = "('new','preparing','ready')";

function get_menu(int $rid, bool $onlyAvailable = false): array {
    $cats = rows('SELECT id, name FROM menu_categories WHERE restaurant_id = ? ORDER BY sort, id', [$rid]);
    $items = rows('SELECT * FROM menu_items WHERE restaurant_id = ?' . ($onlyAvailable ? ' AND available = 1 AND pickup = 1' : '') . ' ORDER BY sort, id', [$rid]);
    $groups = [];
    if ($items) {
        $ids = implode(',', array_map(fn($i) => (int)$i['id'], $items));
        foreach (rows("SELECT * FROM option_groups WHERE item_id IN ($ids) ORDER BY sort, id") as $g) {
            $g['options'] = rows('SELECT id, name, price_cents FROM item_options WHERE group_id = ? ORDER BY sort, id', [$g['id']]);
            $groups[$g['item_id']][] = $g;
        }
    }
    foreach ($items as &$i) {
        $i['option_groups'] = $groups[$i['id']] ?? [];
        foreach (['id', 'price_cents', 'available', 'pickup', 'sort'] as $k) $i[$k] = (int)$i[$k];
        $i['category_id'] = $i['category_id'] !== null ? (int)$i['category_id'] : null;
        $i['prep_minutes'] = $i['prep_minutes'] !== null ? (int)$i['prep_minutes'] : null;
    }
    unset($i);
    foreach ($cats as &$c) {
        $c['id'] = (int)$c['id'];
        $c['items'] = array_values(array_filter($items, fn($i) => $i['category_id'] === $c['id']));
    }
    unset($c);
    $loose = array_values(array_filter($items, fn($i) => $i['category_id'] === null));
    if ($loose) $cats[] = ['id' => null, 'name' => 'Ostalo', 'items' => $loose];
    return $cats;
}

/** Kratek meni v besedilu za LLM (manj žetonov). */
function menu_for_ai(int $rid): array {
    $out = [];
    foreach (get_menu($rid, true) as $c) {
        foreach ($c['items'] as $i) {
            $o = ['id' => $i['id'], 'kategorija' => $c['name'], 'jed' => $i['name'], 'cena_eur' => $i['price_cents'] / 100];
            if ($i['description']) $o['opis'] = $i['description'];
            if ($i['allergens']) $o['alergeni'] = $i['allergens'];
            foreach ($i['option_groups'] as $g) {
                $o['izbire'][] = [
                    'skupina' => $g['name'],
                    'obvezno' => (bool)$g['required'],
                    'najvec' => (int)$g['max_select'],
                    'moznosti' => array_map(fn($x) => ['id' => (int)$x['id'], 'ime' => $x['name'], 'doplacilo_eur' => $x['price_cents'] / 100], $g['options']),
                ];
            }
            $out[] = $o;
        }
    }
    return $out;
}

function orders_in_slot(int $rid, DateTimeImmutable $slotStart, int $slot): int {
    return (int)val('SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status IN ' . ACTIVE_ORD . ' AND pickup_at >= ? AND pickup_at < ?',
        [$rid, fmt($slotStart), fmt($slotStart->modify("+$slot minutes"))]);
}

function ceil_to(DateTimeImmutable $d, int $step): DateTimeImmutable {
    $d = $d->setTime((int)$d->format('H'), (int)$d->format('i'));
    $mins = (int)$d->format('H') * 60 + (int)$d->format('i');
    $c = (int)ceil($mins / $step) * $step;
    return $d->setTime(0, 0)->modify("+$c minutes");
}

/**
 * Najzgodnejši možen prevzem od želenega časa naprej (ali od zdaj).
 * Upošteva čas priprave, ure prevzema in kapaciteto kuhinje na termin.
 */
function suggest_pickup(int $rid, int $prepMinutes, ?string $desired = null): ?array {
    $s = restaurant_settings(get_restaurant($rid));
    if (!$s['pickup_enabled']) return null;
    $slot = (int)$s['pickup_slot_minutes'];
    $max = (int)$s['max_orders_per_slot'];
    $earliest = ceil_to((new DateTimeImmutable())->modify("+$prepMinutes minutes"), 5);
    $from = $earliest;
    if ($desired) {
        $want = dt($desired);
        if ($want > $from) $from = $want;
    }
    for ($day = 0; $day < 7; $day++) {
        $date = $from->modify("+$day day")->format('Y-m-d');
        foreach (intervals_for($rid, 'pickup', $date) as [$open, $close]) {
            $t = $day === 0 ? max($from, $open) : $open;
            for (; $t <= $close; $t = $t->modify("+5 minutes")) {
                if ($max > 0 && orders_in_slot($rid, floor_slot($t, $slot), $slot) >= $max) continue;
                return [
                    'pickup_at' => fmt($t),
                    'date' => $t->format('Y-m-d'),
                    'time' => hm($t),
                    'exact' => $desired ? fmt($t) === fmt(dt($desired)) : true,
                ];
            }
        }
    }
    return null;
}

/** Razreši postavke naročila: id ali ime jedi, izbire po id ali imenu. */
function resolve_order_items(int $rid, array $items): array {
    if (!$items) throw new BookingError('empty_order', 'Naročilo nima jedi.');
    $menu = [];
    foreach (get_menu($rid) as $c) foreach ($c['items'] as $i) $menu[$i['id']] = $i;
    $byName = [];
    foreach ($menu as $i) $byName[mb_strtolower($i['name'])] = $i;

    $lines = [];
    $prep = 0;
    foreach ($items as $it) {
        $m = null;
        if (!empty($it['item_id']) && isset($menu[(int)$it['item_id']])) $m = $menu[(int)$it['item_id']];
        elseif (!empty($it['name'])) {
            $n = mb_strtolower(trim($it['name']));
            $m = $byName[$n] ?? null;
            if (!$m) foreach ($byName as $k => $v) if (str_contains($k, $n) || str_contains($n, $k)) { $m = $v; break; }
        }
        if (!$m) throw new BookingError('unknown_item', 'Jedi ni na meniju: ' . ($it['name'] ?? $it['item_id'] ?? '?'));
        if (!$m['available'] || !$m['pickup']) throw new BookingError('unavailable_item', "Jed {$m['name']} trenutno ni na voljo.");

        $chosen = array_map(fn($x) => is_array($x) ? ($x['id'] ?? $x['name'] ?? '') : $x, (array)($it['options'] ?? []));
        $unit = $m['price_cents'];
        $names = [];
        foreach ($m['option_groups'] as $g) {
            $picked = [];
            foreach ($g['options'] as $o) {
                foreach ($chosen as $c) {
                    if ((is_numeric($c) && (int)$c === (int)$o['id']) || (is_string($c) && mb_strtolower(trim($c)) === mb_strtolower($o['name']))) { $picked[] = $o; break; }
                }
            }
            if ($g['required'] && !$picked) throw new BookingError('missing_option', "Za {$m['name']} je treba izbrati: {$g['name']} (" . implode(', ', array_column($g['options'], 'name')) . ')');
            if (count($picked) > (int)$g['max_select']) throw new BookingError('too_many_options', "Za {$m['name']} lahko izberete največ {$g['max_select']} pri {$g['name']}.");
            foreach ($picked as $o) { $unit += (int)$o['price_cents']; $names[] = $o['name']; }
        }
        $qty = max(1, (int)($it['qty'] ?? 1));
        $lines[] = ['item_id' => $m['id'], 'name' => $m['name'], 'qty' => $qty, 'unit_cents' => $unit, 'options_text' => $names ? implode(', ', $names) : null, 'notes' => $it['notes'] ?? null];
        $prep = max($prep, (int)($m['prep_minutes'] ?? 0));
    }
    return [$lines, $prep];
}

function quote_order(int $rid, array $items, ?string $desired = null): array {
    $s = restaurant_settings(get_restaurant($rid));
    [$lines, $prep] = resolve_order_items($rid, $items);
    $prep = max($prep, (int)$s['pickup_prep_minutes']);
    $total = array_sum(array_map(fn($l) => $l['qty'] * $l['unit_cents'], $lines));
    return ['lines' => $lines, 'total_cents' => $total, 'total_eur' => $total / 100, 'prep_minutes' => $prep, 'pickup' => suggest_pickup($rid, $prep, $desired)];
}

/**
 * Odprta naročila, ki jih sme videti klicatelj: po kodi ali po njegovi številki
 * (iz SIP, ne iz ust). Vrne samo stanje, uro, jedi in znesek; imena in telefona
 * ne razkrije, ker ima koda naročila samo 4 znake.
 */
function find_orders_for_caller(int $rid, ?string $phone, ?string $code): array {
    $sql = 'SELECT * FROM orders WHERE restaurant_id = ? AND status IN ' . ACTIVE_ORD . ' AND pickup_at >= ?';
    $p = [$rid, date('Y-m-d 00:00:00')];
    if ($code) { $sql .= ' AND code = ?'; $p[] = strtoupper(trim($code)); }
    elseif ($phone) { $sql .= ' AND phone = ?'; $p[] = normalize_phone($phone); }
    else return [];
    $out = [];
    foreach (rows($sql . ' ORDER BY pickup_at LIMIT 10', $p) as $o) {
        $o = order_out($o);
        $out[] = [
            'status' => $o['status'], 'pickup_at' => $o['pickup_at'], 'total_eur' => $o['total_cents'] / 100,
            'items' => array_map(fn($i) => ['name' => $i['name'], 'qty' => (int)$i['qty']], $o['items']),
        ];
    }
    return $out;
}

function order_out(array $o): array {
    $o['id'] = (int)$o['id'];
    $o['total_cents'] = (int)$o['total_cents'];
    $o['items'] = rows('SELECT name, qty, unit_cents, options_text, notes FROM order_items WHERE order_id = ?', [$o['id']]);
    return $o;
}

/** $d: name, phone, items[], pickup_time (Y-m-d H:i ali H:i za danes), notes */
function create_order(int $rid, array $d, string $source, bool $staff = false): array {
    if (empty($d['name'])) throw new BookingError('missing_field', 'Manjka ime.');
    $desired = null;
    if (!empty($d['pickup_time'])) {
        $desired = strlen($d['pickup_time']) <= 5 ? date('Y-m-d') . ' ' . $d['pickup_time'] : $d['pickup_time'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') q('SELECT id FROM restaurants WHERE id = ? FOR UPDATE', [$rid]);
        $quote = quote_order($rid, $d['items'] ?? [], $desired);
        if ($staff && $desired) {
            $pickupAt = fmt(dt($desired));
        } else {
            if (!$quote['pickup']) throw new BookingError('pickup_closed', 'Prevzem trenutno ni mogoč.');
            if ($desired && !$quote['pickup']['exact'] && empty($d['accept_suggested'])) {
                throw new BookingError('pickup_time_unavailable', 'Ob želeni uri prevzem ni mogoč.', ['suggested' => $quote['pickup']]);
            }
            $pickupAt = $quote['pickup']['pickup_at'];
        }
        $id = insert('orders', [
            'restaurant_id' => $rid, 'code' => random_code(4), 'name' => trim($d['name']),
            'phone' => !empty($d['phone']) ? normalize_phone($d['phone']) : null,
            'pickup_at' => $pickupAt, 'status' => 'new', 'total_cents' => $quote['total_cents'],
            'notes' => $d['notes'] ?? null, 'source' => $source, 'created_at' => now_str(), 'updated_at' => now_str(),
        ]);
        foreach ($quote['lines'] as $l) insert('order_items', ['order_id' => $id] + $l);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $o = order_out(row('SELECT * FROM orders WHERE id = ?', [$id]));
    if ($o['phone']) queue_sms($rid, $o['phone'], sprintf('Naročilo %s prejeto. Prevzem ob %s. Skupaj %s €.', $o['code'], hm(dt($o['pickup_at'])), number_format($o['total_cents'] / 100, 2, ',', '')));
    return $o;
}
