<?php
declare(strict_types=1);

const ACTIVE_RES = "('confirmed','seated')";

function dt(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }
function fmt(DateTimeImmutable $d): string { return $d->format('Y-m-d H:i:s'); }
function hm(DateTimeImmutable $d): string { return $d->format('H:i'); }

class BookingError extends RuntimeException {
    public string $codeName;
    public array $extra;
    public function __construct(string $codeName, string $message, array $extra = []) {
        parent::__construct($message);
        $this->codeName = $codeName;
        $this->extra = $extra;
    }
}

function get_restaurant(int $rid): array {
    $r = row('SELECT * FROM restaurants WHERE id = ?', [$rid]);
    if (!$r) throw new BookingError('not_found', 'Restavracija ne obstaja.');
    return $r;
}

/** Odprti intervali za dan: [[DateTimeImmutable open, close, last], ...] */
function intervals_for(int $rid, string $kind, string $date): array {
    $closed = row('SELECT * FROM special_days WHERE restaurant_id = ? AND day = ?', [$rid, $date]);
    if ($closed && (int)$closed['closed'] === 1) return [];
    $wd = (int)dt($date)->format('N');
    $out = [];
    foreach (rows('SELECT * FROM opening_hours WHERE restaurant_id = ? AND kind = ? AND weekday = ? ORDER BY open_time', [$rid, $kind, $wd]) as $h) {
        $open = dt("$date {$h['open_time']}");
        $close = dt("$date {$h['close_time']}");
        if ($close <= $open) $close = $close->modify('+1 day'); // čez polnoč
        $last = $h['last_booking'] ? dt("$date {$h['last_booking']}") : $close;
        $out[] = [$open, $close, $last];
    }
    return $out;
}

function duration_for(array $s, int $party): int {
    $rules = $s['durations'];
    usort($rules, fn($a, $b) => $a['max_party'] <=> $b['max_party']);
    foreach ($rules as $r) if ($party <= (int)$r['max_party']) return (int)$r['minutes'];
    return (int)end($rules)['minutes'];
}

function busy_table_ids(int $rid, DateTimeImmutable $start, DateTimeImmutable $end, int $buffer, ?int $excludeRes = null): array {
    $from = fmt($start->modify("-$buffer minutes"));
    $to = fmt($end->modify("+$buffer minutes"));
    $sql = "SELECT DISTINCT rt.table_id FROM reservation_tables rt JOIN reservations r ON r.id = rt.reservation_id
            WHERE r.restaurant_id = ? AND r.status IN " . ACTIVE_RES . " AND r.start_at < ? AND r.end_at > ?";
    $p = [$rid, $to, $from];
    if ($excludeRes) { $sql .= ' AND r.id <> ?'; $p[] = $excludeRes; }
    return array_map('intval', array_column(rows($sql, $p), 'table_id'));
}

/** Najde najprimernejše mize. Vrne ['tables'=>[...], 'zone_match'=>bool] ali null. */
function find_tables(int $rid, DateTimeImmutable $start, DateTimeImmutable $end, int $party, ?string $zone, int $buffer, ?int $excludeRes = null, bool $onlyReservable = true): ?array {
    $busy = busy_table_ids($rid, $start, $end, $buffer, $excludeRes);
    $all = rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND active = 1' . ($onlyReservable ? ' AND reservable = 1' : ''), [$rid]);
    $free = array_values(array_filter($all, fn($t) => !in_array((int)$t['id'], $busy, true)));

    $try = function (array $pool) use ($party) {
        // 1) ena miza, najmanjša primerna
        $single = array_filter($pool, fn($t) => $party >= (int)$t['min_seats'] && $party <= (int)$t['max_seats']);
        usort($single, fn($a, $b) => [(int)$a['max_seats'], -(int)$a['priority']] <=> [(int)$b['max_seats'], -(int)$b['priority']]);
        if ($single) return [reset($single)];
        // 2) dve združljivi mizi iz iste skupine
        $best = null;
        $n = count($pool);
        for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) {
            $a = $pool[$i]; $b = $pool[$j];
            if (!$a['combine_group'] || $a['combine_group'] !== $b['combine_group']) continue;
            $cap = (int)$a['max_seats'] + (int)$b['max_seats'];
            if ($cap < $party) continue;
            if (!$best || $cap < $best[0]) $best = [$cap, [$a, $b]];
        }
        return $best ? $best[1] : null;
    };

    if ($zone) {
        $inZone = array_values(array_filter($free, fn($t) => mb_strtolower((string)$t['zone']) === mb_strtolower($zone)));
        $r = $try($inZone);
        if ($r) return ['tables' => $r, 'zone_match' => true];
    }
    $r = $try($free);
    return $r ? ['tables' => $r, 'zone_match' => $zone ? false : true] : null;
}

function covers_in_slot(int $rid, DateTimeImmutable $start, int $slot, ?int $excludeRes = null): int {
    $sql = 'SELECT COALESCE(SUM(party_size),0) FROM reservations WHERE restaurant_id = ? AND status IN ' . ACTIVE_RES . ' AND start_at >= ? AND start_at < ?';
    $p = [$rid, fmt($start), fmt($start->modify("+$slot minutes"))];
    if ($excludeRes) { $sql .= ' AND id <> ?'; $p[] = $excludeRes; }
    return (int)val($sql, $p);
}

/** Preveri en termin. Vrne [ok(bool), reason(?string), match(?array), duration(int)] */
function evaluate_slot(int $rid, array $s, DateTimeImmutable $start, int $party, ?string $zone, ?int $excludeRes = null, bool $staff = false): array {
    $dur = duration_for($s, $party);
    $end = $start->modify("+$dur minutes");
    $date = $start->format('Y-m-d');

    if (!$staff) {
        $now = new DateTimeImmutable();
        if ($start < $now->modify('+' . (int)$s['min_notice_minutes'] . ' minutes')) return [false, 'too_soon', null, $dur];
        if ($start > $now->modify('+' . (int)$s['days_ahead'] . ' days')) return [false, 'too_far', null, $dur];
        if ($party > (int)$s['max_party_online']) return [false, 'group_too_large', null, $dur];
    }
    $ints = intervals_for($rid, 'dine', $date);
    if (!$ints) return [false, 'closed', null, $dur];
    $inside = false;
    foreach ($ints as [$open, $close, $last]) {
        if ($start >= $open && $start <= $last && $start < $close) { $inside = true; break; }
    }
    if (!$inside) return [false, 'outside_hours', null, $dur];

    $max = (int)$s['max_covers_per_slot'];
    if ($max > 0 && covers_in_slot($rid, floor_slot($start, (int)$s['slot_minutes']), (int)$s['slot_minutes'], $excludeRes) + $party > $max) {
        return [false, 'slot_full', null, $dur];
    }
    $m = find_tables($rid, $start, $end, $party, $zone, (int)$s['buffer_minutes'], $excludeRes);
    if (!$m) return [false, 'no_table', null, $dur];
    return [true, null, $m, $dur];
}

function floor_slot(DateTimeImmutable $d, int $slot): DateTimeImmutable {
    $mins = (int)$d->format('H') * 60 + (int)$d->format('i');
    $f = intdiv($mins, $slot) * $slot;
    return $d->setTime(intdiv($f, 60), $f % 60);
}

function reason_text(string $r): string {
    return [
        'too_soon' => 'Termin je prekmalu za rezervacijo.',
        'too_far' => 'Rezervacije za tako daleč vnaprej še ne sprejemamo.',
        'group_too_large' => 'Za tako veliko skupino rezervacijo uredi osebje.',
        'closed' => 'Restavracija je ta dan zaprta.',
        'outside_hours' => 'Ob tej uri ne sprejemamo rezervacij.',
        'slot_full' => 'Ob tej uri je že veliko prihodov.',
        'no_table' => 'Ob tej uri ni proste mize za to število oseb.',
    ][$r] ?? $r;
}

/** Glavna funkcija za Tatjano: preveri termin in predlaga alternative. */
function check_availability(int $rid, string $date, string $time, int $party, ?string $zone = null): array {
    $r = get_restaurant($rid);
    $s = restaurant_settings($r);
    $start = dt("$date $time");
    [$ok, $reason, $m, $dur] = evaluate_slot($rid, $s, $start, $party, $zone);
    $res = [
        'available' => $ok,
        'date' => $date,
        'time' => hm($start),
        'party_size' => $party,
        'duration_minutes' => $dur,
    ];
    if ($ok) {
        $res['tables'] = array_map(fn($t) => $t['name'], $m['tables']);
        $res['zone'] = $m['tables'][0]['zone'];
        $res['zone_match'] = $m['zone_match'];
        if ($zone && !$m['zone_match']) $res['note'] = "V coni $zone ni prostora, prosta je miza v coni {$res['zone']}.";
        return $res;
    }
    $res['reason'] = $reason;
    $res['message'] = reason_text($reason);
    if ($reason === 'group_too_large') { $res['handoff'] = true; return $res; }
    $res['alternatives'] = suggest_alternatives($rid, $s, $start, $party, $zone);
    return $res;
}

function suggest_alternatives(int $rid, array $s, DateTimeImmutable $start, int $party, ?string $zone): array {
    $slot = (int)$s['slot_minutes'];
    $alts = [];
    for ($k = 1; $k <= intdiv(180, $slot) && count($alts) < 3; $k++) {
        foreach ([$k, -$k] as $sign) {
            $t = $start->modify(($sign * $slot) . ' minutes');
            if ($t->format('Y-m-d') !== $start->format('Y-m-d')) continue;
            [$ok] = evaluate_slot($rid, $s, $t, $party, $zone);
            if ($ok) $alts[] = ['date' => $t->format('Y-m-d'), 'time' => hm($t)];
            if (count($alts) >= 3) break;
        }
    }
    usort($alts, fn($a, $b) => strcmp($a['time'], $b['time']));
    if (!$alts) {
        // isti čas v naslednjih 7 dneh
        for ($d = 1; $d <= 7; $d++) {
            $t = $start->modify("+$d day");
            [$ok] = evaluate_slot($rid, $s, $t, $party, $zone);
            if ($ok) { $alts[] = ['date' => $t->format('Y-m-d'), 'time' => hm($t)]; break; }
        }
    }
    return $alts;
}

/** Vsi prosti termini v dnevu za določeno število oseb. */
function day_slots(int $rid, string $date, int $party, ?string $zone = null): array {
    $s = restaurant_settings(get_restaurant($rid));
    $slot = (int)$s['slot_minutes'];
    $out = [];
    foreach (intervals_for($rid, 'dine', $date) as [$open, $close, $last]) {
        for ($t = $open; $t <= $last && $t < $close; $t = $t->modify("+$slot minutes")) {
            [$ok] = evaluate_slot($rid, $s, $t, $party, $zone);
            if ($ok) $out[] = hm($t);
        }
    }
    return $out;
}

function reservation_out(array $r): array {
    $tables = rows('SELECT t.id, t.name, t.zone FROM reservation_tables rt JOIN dining_tables t ON t.id = rt.table_id WHERE rt.reservation_id = ?', [$r['id']]);
    $r['tables'] = $tables;
    $r['id'] = (int)$r['id'];
    $r['party_size'] = (int)$r['party_size'];
    return $r;
}

/**
 * Ustvari rezervacijo. $d: date, time, party_size, name, phone, email, notes, zone_pref, table_ids (neobvezno, za osebje)
 */
function create_reservation(int $rid, array $d, string $source, bool $staff = false): array {
    $s = restaurant_settings(get_restaurant($rid));
    foreach (['date', 'time', 'party_size', 'name'] as $f) if (empty($d[$f])) throw new BookingError('missing_field', "Manjka polje: $f");
    $party = (int)$d['party_size'];
    if ($party < 1) throw new BookingError('invalid', 'Število oseb mora biti vsaj 1.');
    $start = dt($d['date'] . ' ' . $d['time']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // serializira hkratne klice za isto restavracijo
            q('SELECT id FROM restaurants WHERE id = ? FOR UPDATE', [$rid]);
        }
        if (!empty($d['table_ids']) && $staff) {
            $dur = !empty($d['duration_minutes']) ? (int)$d['duration_minutes'] : duration_for($s, $party);
            $end = $start->modify("+$dur minutes");
            $ids = array_map('intval', (array)$d['table_ids']);
            $busy = array_intersect($ids, busy_table_ids($rid, $start, $end, 0));
            if ($busy && empty($d['force'])) throw new BookingError('table_busy', 'Izbrana miza je v tem času že zasedena.');
            $tables = rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND id IN (' . implode(',', $ids) . ')', [$rid]);
        } else {
            [$ok, $reason, $m, $dur] = evaluate_slot($rid, $s, $start, $party, $d['zone_pref'] ?? null, null, $staff);
            if (!$ok) {
                $pdo->rollBack();
                throw new BookingError($reason, reason_text($reason), ['alternatives' => $reason === 'group_too_large' ? [] : suggest_alternatives($rid, $s, $start, $party, $d['zone_pref'] ?? null)]);
            }
            $tables = $m['tables'];
            if (!empty($d['duration_minutes']) && $staff) $dur = (int)$d['duration_minutes'];
            $end = $start->modify("+$dur minutes");
        }
        $id = insert('reservations', [
            'restaurant_id' => $rid,
            'code' => random_code(),
            'start_at' => fmt($start),
            'end_at' => fmt($end),
            'party_size' => $party,
            'name' => trim($d['name']),
            'phone' => !empty($d['phone']) ? normalize_phone($d['phone']) : null,
            'email' => $d['email'] ?? null,
            'notes' => $d['notes'] ?? null,
            'zone_pref' => $d['zone_pref'] ?? null,
            'status' => 'confirmed',
            'source' => $source,
            'created_at' => now_str(),
            'updated_at' => now_str(),
        ]);
        foreach ($tables as $t) insert('reservation_tables', ['reservation_id' => $id, 'table_id' => (int)$t['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $res = reservation_out(row('SELECT * FROM reservations WHERE id = ?', [$id]));
    if ($res['phone']) {
        queue_sms($rid, $res['phone'], sprintf('Rezervacija potrjena: %s ob %s, %d oseb. Koda %s.',
            dt($res['start_at'])->format('j. n. Y'), hm(dt($res['start_at'])), $party, $res['code']));
    }
    return $res;
}

function find_reservations(int $rid, ?string $phone, ?string $code, ?string $name = null): array {
    $sql = 'SELECT * FROM reservations WHERE restaurant_id = ? AND start_at >= ? AND status IN ' . ACTIVE_RES;
    $p = [$rid, date('Y-m-d 00:00:00')];
    if ($code) { $sql .= ' AND code = ?'; $p[] = strtoupper(trim($code)); }
    elseif ($phone) { $sql .= ' AND phone = ?'; $p[] = normalize_phone($phone); }
    elseif ($name) { $sql .= ' AND LOWER(name) LIKE ?'; $p[] = '%' . mb_strtolower(trim($name)) . '%'; }
    else return [];
    return array_map('reservation_out', rows($sql . ' ORDER BY start_at LIMIT 10', $p));
}

function cancel_reservation(int $rid, int $id): array {
    $r = row('SELECT * FROM reservations WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
    if (!$r) throw new BookingError('not_found', 'Rezervacija ne obstaja.');
    update('reservations', ['status' => 'cancelled', 'updated_at' => now_str()], 'id = ?', [$id]);
    return reservation_out(row('SELECT * FROM reservations WHERE id = ?', [$id]));
}

/** Sprememba časa ali števila oseb, ponovno dodeli mize. */
function change_reservation(int $rid, int $id, array $d, bool $staff = false): array {
    $r = row('SELECT * FROM reservations WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
    if (!$r) throw new BookingError('not_found', 'Rezervacija ne obstaja.');
    $s = restaurant_settings(get_restaurant($rid));
    $start = dt(($d['date'] ?? substr($r['start_at'], 0, 10)) . ' ' . ($d['time'] ?? substr($r['start_at'], 11, 5)));
    $party = (int)($d['party_size'] ?? $r['party_size']);
    $zone = $d['zone_pref'] ?? $r['zone_pref'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!empty($d['table_ids']) && $staff) {
            $dur = (int)round((dt($r['end_at'])->getTimestamp() - dt($r['start_at'])->getTimestamp()) / 60);
            if (!empty($d['duration_minutes'])) $dur = (int)$d['duration_minutes'];
            $end = $start->modify("+$dur minutes");
            $ids = array_map('intval', (array)$d['table_ids']);
            if (array_intersect($ids, busy_table_ids($rid, $start, $end, 0, $id)) && empty($d['force'])) throw new BookingError('table_busy', 'Izbrana miza je v tem času že zasedena.');
            $tables = rows('SELECT * FROM dining_tables WHERE restaurant_id = ? AND id IN (' . implode(',', $ids) . ')', [$rid]);
        } else {
            [$ok, $reason, $m, $dur] = evaluate_slot($rid, $s, $start, $party, $zone, $id, $staff);
            if (!$ok) throw new BookingError($reason, reason_text($reason), ['alternatives' => suggest_alternatives($rid, $s, $start, $party, $zone)]);
            if (!empty($d['duration_minutes']) && $staff) $dur = (int)$d['duration_minutes'];
            $end = $start->modify("+$dur minutes");
            $tables = $m['tables'];
        }
        $upd = ['start_at' => fmt($start), 'end_at' => fmt($end), 'party_size' => $party, 'zone_pref' => $zone, 'updated_at' => now_str()];
        foreach (['name', 'phone', 'email', 'notes'] as $f) if (array_key_exists($f, $d)) $upd[$f] = $f === 'phone' && $d[$f] ? normalize_phone($d[$f]) : $d[$f];
        update('reservations', $upd, 'id = ?', [$id]);
        q('DELETE FROM reservation_tables WHERE reservation_id = ?', [$id]);
        foreach ($tables as $t) insert('reservation_tables', ['reservation_id' => $id, 'table_id' => (int)$t['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return reservation_out(row('SELECT * FROM reservations WHERE id = ?', [$id]));
}

function queue_sms(int $rid, string $to, string $body): void {
    $s = restaurant_settings(get_restaurant($rid));
    insert('notifications', [
        'restaurant_id' => $rid, 'channel' => 'sms', 'recipient' => $to, 'body' => $body,
        'status' => $s['sms_enabled'] ? 'queued' : 'disabled', 'created_at' => now_str(),
    ]);
}
