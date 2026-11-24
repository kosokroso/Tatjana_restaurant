<?php
declare(strict_types=1);

// Ena shema za MySQL in SQLite. {PK} in {ENGINE} se zamenjata glede na gonilnik.
function schema_sql(string $driver): array {
    $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $fk = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
    $engine = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $text = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';

    $t = [];
    $t[] = "CREATE TABLE IF NOT EXISTS restaurants (
        id $pk,
        name VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        address VARCHAR(255) NULL,
        api_key VARCHAR(80) NOT NULL,
        settings $text NULL,
        created_at DATETIME NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS users (
        id $pk,
        restaurant_id $fk NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'admin',
        created_at DATETIME NOT NULL,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS dining_tables (
        id $pk,
        restaurant_id $fk NOT NULL,
        name VARCHAR(40) NOT NULL,
        min_seats INT NOT NULL DEFAULT 1,
        max_seats INT NOT NULL DEFAULT 4,
        zone VARCHAR(60) NULL,
        shape VARCHAR(20) NOT NULL DEFAULT 'square',
        x INT NOT NULL DEFAULT 40,
        y INT NOT NULL DEFAULT 40,
        w INT NOT NULL DEFAULT 80,
        h INT NOT NULL DEFAULT 80,
        combine_group VARCHAR(40) NULL,
        reservable TINYINT NOT NULL DEFAULT 1,
        features VARCHAR(255) NULL,
        priority INT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS opening_hours (
        id $pk,
        restaurant_id $fk NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'dine',
        weekday INT NOT NULL,
        open_time VARCHAR(5) NOT NULL,
        close_time VARCHAR(5) NOT NULL,
        last_booking VARCHAR(5) NULL,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS special_days (
        id $pk,
        restaurant_id $fk NOT NULL,
        day DATE NOT NULL,
        closed TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS reservations (
        id $pk,
        restaurant_id $fk NOT NULL,
        code VARCHAR(12) NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        party_size INT NOT NULL,
        name VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        email VARCHAR(190) NULL,
        notes VARCHAR(1000) NULL,
        zone_pref VARCHAR(60) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE INDEX idx_res_time ON reservations (restaurant_id, start_at)";
    $t[] = "CREATE TABLE IF NOT EXISTS reservation_tables (
        reservation_id $fk NOT NULL,
        table_id $fk NOT NULL,
        PRIMARY KEY (reservation_id, table_id),
        FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
        FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS menu_categories (
        id $pk,
        restaurant_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS menu_items (
        id $pk,
        restaurant_id $fk NOT NULL,
        category_id $fk NULL,
        name VARCHAR(190) NOT NULL,
        description VARCHAR(1000) NULL,
        price_cents INT NOT NULL DEFAULT 0,
        allergens VARCHAR(255) NULL,
        available TINYINT NOT NULL DEFAULT 1,
        pickup TINYINT NOT NULL DEFAULT 1,
        prep_minutes INT NULL,
        sort INT NOT NULL DEFAULT 0,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE SET NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS option_groups (
        id $pk,
        item_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        required TINYINT NOT NULL DEFAULT 0,
        max_select INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        FOREIGN KEY (item_id) REFERENCES menu_items(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS item_options (
        id $pk,
        group_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        price_cents INT NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0,
        FOREIGN KEY (group_id) REFERENCES option_groups(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS orders (
        id $pk,
        restaurant_id $fk NOT NULL,
        code VARCHAR(12) NOT NULL,
        name VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        pickup_at DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        total_cents INT NOT NULL DEFAULT 0,
        notes VARCHAR(1000) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE INDEX idx_ord_time ON orders (restaurant_id, pickup_at)";
    $t[] = "CREATE TABLE IF NOT EXISTS order_items (
        id $pk,
        order_id $fk NOT NULL,
        item_id $fk NULL,
        name VARCHAR(190) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        unit_cents INT NOT NULL DEFAULT 0,
        options_text VARCHAR(500) NULL,
        notes VARCHAR(500) NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS notifications (
        id $pk,
        restaurant_id $fk NOT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT 'sms',
        recipient VARCHAR(190) NOT NULL,
        body VARCHAR(1000) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        created_at DATETIME NOT NULL
    )$engine";
    return $t;
}

function run_schema(): void {
    $driver = config()['driver'];
    foreach (schema_sql($driver) as $sql) {
        try { db()->exec($sql); }
        catch (PDOException $e) {
            // indeks že obstaja ob ponovnem zagonu
            if (stripos($sql, 'CREATE INDEX') === 0) continue;
            throw $e;
        }
    }
}
