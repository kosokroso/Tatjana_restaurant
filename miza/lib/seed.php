<?php
declare(strict_types=1);

function create_restaurant(string $name, string $email, string $password, bool $demo): int {
    $rid = insert('restaurants', [
        'name' => $name,
        'phone' => null,
        'address' => null,
        'api_key' => 'tm_' . bin2hex(random_bytes(20)),
        'settings' => json_encode(default_settings(), JSON_UNESCAPED_UNICODE),
        'created_at' => now_str(),
    ]);
    insert('users', [
        'restaurant_id' => $rid,
        'email' => strtolower(trim($email)),
        'name' => 'Admin',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => now_str(),
    ]);
    // privzet delovni čas: tor do ned 12:00 do 22:00, prevzem 12:00 do 21:00
    for ($d = 2; $d <= 7; $d++) {
        insert('opening_hours', ['restaurant_id' => $rid, 'kind' => 'dine', 'weekday' => $d, 'open_time' => '12:00', 'close_time' => '22:00', 'last_booking' => '20:30']);
        insert('opening_hours', ['restaurant_id' => $rid, 'kind' => 'pickup', 'weekday' => $d, 'open_time' => '12:00', 'close_time' => '21:00', 'last_booking' => null]);
    }
    if ($demo) seed_demo($rid);
    return $rid;
}

function seed_demo(int $rid): void {
    $tables = [
        // name, min, max, zone, shape, x, y, w, h, group, features
        ['1', 1, 2, 'Notri', 'round', 60, 60, 70, 70, null, 'ob oknu'],
        ['2', 1, 2, 'Notri', 'round', 180, 60, 70, 70, null, 'ob oknu'],
        ['3', 2, 4, 'Notri', 'square', 60, 190, 90, 90, 'A', null],
        ['4', 2, 4, 'Notri', 'square', 170, 190, 90, 90, 'A', null],
        ['5', 4, 6, 'Notri', 'rect', 320, 60, 160, 80, null, 'separe'],
        ['6', 2, 4, 'Notri', 'square', 320, 200, 90, 90, null, 'dostop z vozičkom'],
        ['7', 6, 10, 'Notri', 'rect', 60, 330, 240, 90, null, 'dolga miza'],
        ['T1', 2, 4, 'Terasa', 'square', 560, 60, 90, 90, 'T', null],
        ['T2', 2, 4, 'Terasa', 'square', 670, 60, 90, 90, 'T', null],
        ['T3', 1, 2, 'Terasa', 'round', 560, 200, 70, 70, null, null],
        ['T4', 2, 4, 'Terasa', 'square', 670, 200, 90, 90, null, null],
    ];
    foreach ($tables as $i => $t) {
        insert('dining_tables', [
            'restaurant_id' => $rid, 'name' => $t[0], 'min_seats' => $t[1], 'max_seats' => $t[2], 'zone' => $t[3],
            'shape' => $t[4], 'x' => $t[5], 'y' => $t[6], 'w' => $t[7], 'h' => $t[8], 'combine_group' => $t[9],
            'features' => $t[10], 'reservable' => 1, 'priority' => 0, 'active' => 1,
        ]);
    }
    $cats = ['Predjedi' => [], 'Glavne jedi' => [], 'Pice' => [], 'Sladice' => []];
    $catIds = [];
    $s = 0;
    foreach (array_keys($cats) as $c) $catIds[$c] = insert('menu_categories', ['restaurant_id' => $rid, 'name' => $c, 'sort' => $s++]);

    $items = [
        ['Predjedi', 'Pršut in sir', 'Kraški pršut, domači sir, olive', 1150, 'mleko', null],
        ['Predjedi', 'Goveja juha', 'Z domačimi rezanci', 450, 'gluten, jajca, zelena', null],
        ['Glavne jedi', 'Njoki s tartufi', 'Domači krompirjevi njoki, smetanova omaka s tartufi', 1600, 'gluten, mleko, jajca', 15],
        ['Glavne jedi', 'Burger Goriška', 'Goveji burger, cheddar, karamelizirana čebula, krompirček', 1450, 'gluten, mleko, gorčica', 18],
        ['Glavne jedi', 'Rižota z jurčki', null, 1500, 'mleko, zelena', 20],
        ['Pice', 'Margerita', 'Paradižnik, mozzarella, bazilika', 900, 'gluten, mleko', 12],
        ['Pice', 'Pršut', 'Paradižnik, mozzarella, pršut, rukola', 1250, 'gluten, mleko', 12],
        ['Sladice', 'Tiramisu', null, 550, 'gluten, mleko, jajca', null],
        ['Sladice', 'Gibanica', 'Prekmurska', 600, 'gluten, mleko, jajca, oreški', null],
    ];
    $s = 0;
    foreach ($items as $it) {
        $iid = insert('menu_items', [
            'restaurant_id' => $rid, 'category_id' => $catIds[$it[0]], 'name' => $it[1], 'description' => $it[2],
            'price_cents' => $it[3], 'allergens' => $it[4], 'available' => 1, 'pickup' => 1, 'prep_minutes' => $it[5], 'sort' => $s++,
        ]);
        if ($it[1] === 'Burger Goriška') {
            $g = insert('option_groups', ['item_id' => $iid, 'name' => 'Pečenost', 'required' => 1, 'max_select' => 1, 'sort' => 0]);
            foreach (['Srednje pečen', 'Dobro pečen'] as $k => $o) insert('item_options', ['group_id' => $g, 'name' => $o, 'price_cents' => 0, 'sort' => $k]);
            $g = insert('option_groups', ['item_id' => $iid, 'name' => 'Dodatki', 'required' => 0, 'max_select' => 3, 'sort' => 1]);
            foreach ([['Slanina', 150], ['Dodaten sir', 100], ['Jalapeño', 80]] as $k => $o) insert('item_options', ['group_id' => $g, 'name' => $o[0], 'price_cents' => $o[1], 'sort' => $k]);
        }
        if ($it[0] === 'Pice') {
            $g = insert('option_groups', ['item_id' => $iid, 'name' => 'Dodatki', 'required' => 0, 'max_select' => 4, 'sort' => 0]);
            foreach ([['Šampinjoni', 100], ['Pekoča salama', 150], ['Brez glutena (testo)', 250]] as $k => $o) insert('item_options', ['group_id' => $g, 'name' => $o[0], 'price_cents' => $o[1], 'sort' => $k]);
        }
    }
}
