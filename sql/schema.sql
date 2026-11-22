-- Tatjana Restaurant: shema v0 (predlog, faza 0)
-- MySQL 8.0+ / MariaDB 10.6+ (InnoDB, utf8mb4). Ni preverjeno proti pravi bazi.
--
-- Pravila:
--  1. Vsaka tabela s podatki restavracije ima tenant_id.
--  2. Otroške tabele kazejo na starsa s sestavljenim tujim ključem
--     (tenant_id, id). Baza sama zavrne zapis, ki bi mešal tenante.
--  3. Časi so v UTC (DATETIME). Lokalni čas izračuna PHP iz locations.timezone.
--  4. Dvojno zasedbo preprečuje UNIQUE (table_id, slot_start) v table_slots,
--     ne samo koda. Glej razdelek "Zaklepanje" na koncu.

SET NAMES utf8mb4;

CREATE TABLE tenants (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(160) NOT NULL,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Ena restavracija ima lahko več lokacij. V v1 UI uporablja eno,
-- model pa ne zapira poti naprej.
CREATE TABLE locations (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             INT UNSIGNED NOT NULL,
  name                  VARCHAR(160) NOT NULL,
  timezone              VARCHAR(64)  NOT NULL DEFAULT 'Europe/Ljubljana',
  phone_public          VARCHAR(40)  NULL,        -- številka za preusmeritev ob izpadu
  -- Pravila rezervacij (konfiguracija, ne koda):
  slot_minutes          SMALLINT UNSIGNED NOT NULL DEFAULT 15,  -- zrnatost zasedbe
  default_duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  max_party_size        SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  min_lead_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 60,   -- najmanj vnaprej
  max_days_ahead        SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  allow_table_merge     TINYINT(1) NOT NULL DEFAULT 0,
  waitlist_enabled      TINYINT(1) NOT NULL DEFAULT 0,           -- v1: samo zastavica
  phone_country_code    VARCHAR(4) NOT NULL DEFAULT '386',       -- za številke brez +, npr. 041 ...
  PRIMARY KEY (id),
  UNIQUE KEY uq_loc_tenant (tenant_id, id),
  CONSTRAINT fk_loc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE dining_tables (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NOT NULL,
  label         VARCHAR(40) NOT NULL,             -- "12", "Terasa 3"
  min_seats     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_seats     SMALLINT UNSIGNED NOT NULL,
  zone          VARCHAR(40) NULL,                 -- "notri", "terasa"
  active        TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tbl_tenant (tenant_id, id),
  UNIQUE KEY uq_tbl_label (location_id, label),
  KEY ix_tbl_loc (tenant_id, location_id, active),
  CONSTRAINT fk_tbl_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id),
  CONSTRAINT ck_tbl_seats CHECK (min_seats <= max_seats)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Dovoljene kombinacije miz (le če locations.allow_table_merge = 1).
-- Skupina = več vrstic z istim combo_id. Kapaciteta skupine je ročno podana,
-- ker dve mizi po 4 ne dasta vedno 8 sedežev.
CREATE TABLE table_combos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NOT NULL,
  max_seats     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_combo_tenant (tenant_id, id),
  CONSTRAINT fk_combo_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE table_combo_members (
  tenant_id     INT UNSIGNED NOT NULL,
  combo_id      INT UNSIGNED NOT NULL,
  table_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (combo_id, table_id),
  CONSTRAINT fk_cm_combo FOREIGN KEY (tenant_id, combo_id) REFERENCES table_combos (tenant_id, id),
  CONSTRAINT fk_cm_table FOREIGN KEY (tenant_id, table_id) REFERENCES dining_tables (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Redni urnik. Več vrstic na dan = odmor (npr. 12-15 in 18-22).
CREATE TABLE opening_hours (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NOT NULL,
  weekday       TINYINT UNSIGNED NOT NULL,        -- 1 = ponedeljek ... 7 = nedelja
  opens_at      TIME NOT NULL,                    -- lokalni čas
  last_seating  TIME NOT NULL,                    -- zadnji dovoljen začetek rezervacije
  closes_at     TIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_oh (tenant_id, location_id, weekday),
  CONSTRAINT fk_oh_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id),
  CONSTRAINT ck_oh_wd CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Izjeme: praznik, zasebna zabava, dopust. closes cel dan ali del dneva.
CREATE TABLE closures (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NOT NULL,
  starts_at     DATETIME NOT NULL,                -- UTC
  ends_at       DATETIME NOT NULL,                -- UTC
  reason        VARCHAR(160) NULL,
  PRIMARY KEY (id),
  KEY ix_cl (tenant_id, location_id, starts_at, ends_at),
  CONSTRAINT fk_cl_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE reservations (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        INT UNSIGNED NOT NULL,
  location_id      INT UNSIGNED NOT NULL,
  code             CHAR(8) NOT NULL,              -- kratka koda za gosta (brez 0/O/1/I)
  status           ENUM('confirmed','arrived','completed','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
  source           ENUM('phone','staff','web') NOT NULL,
  party_size       SMALLINT UNSIGNED NOT NULL,
  starts_at        DATETIME NOT NULL,             -- UTC
  ends_at          DATETIME NOT NULL,             -- UTC, izračuna koda
  guest_name       VARCHAR(160) NOT NULL,
  guest_phone      VARCHAR(40)  NOT NULL,         -- E.164
  guest_email      VARCHAR(190) NULL,
  notes            VARCHAR(500) NULL,             -- alergije, otroški stol
  cancel_token     CHAR(43) NOT NULL,             -- naključen, za povezavo za odpoved
  idempotency_key  VARCHAR(64) NULL,
  idempotency_hash CHAR(64) NULL,                 -- sha256 telesa prvega zahtevka; drugačno telo = 409
  created_by       INT UNSIGNED NULL,             -- staff_users.id, NULL pri Tatjani
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  anonymized_at    DATETIME NULL,                 -- GDPR: kontakt izbrisan, vrstica ostane za statistiko
  PRIMARY KEY (id),
  UNIQUE KEY uq_res_tenant (tenant_id, id),
  UNIQUE KEY uq_res_code (tenant_id, code),
  UNIQUE KEY uq_res_idem (tenant_id, idempotency_key),   -- več NULL je dovoljenih
  KEY ix_res_day (tenant_id, location_id, starts_at),     -- dnevni pregled
  KEY ix_res_phone (tenant_id, guest_phone),              -- iskanje po gostu
  KEY ix_res_remind (status, starts_at),                  -- cron za opomnike
  CONSTRAINT fk_res_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id),
  CONSTRAINT ck_res_time CHECK (starts_at < ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Katere mize rezervacija drži (ena ali več ob združevanju).
CREATE TABLE reservation_tables (
  tenant_id       INT UNSIGNED NOT NULL,
  reservation_id  BIGINT UNSIGNED NOT NULL,
  table_id        INT UNSIGNED NOT NULL,
  PRIMARY KEY (reservation_id, table_id),
  CONSTRAINT fk_rt_res FOREIGN KEY (tenant_id, reservation_id) REFERENCES reservations (tenant_id, id),
  CONSTRAINT fk_rt_tbl FOREIGN KEY (tenant_id, table_id) REFERENCES dining_tables (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Zasedenost po rezinah. Edini vir resnice za "je miza prosta".
-- Ena vrstica = ena miza v eni rezini (slot_minutes). Aktivna rezervacija
-- (confirmed, arrived) ima vrstice; ob cancelled/no_show/completed se brišejo.
-- UNIQUE (table_id, slot_start) pomeni: baza zavrne drugega, ki hoče isto
-- mizo v isti rezini, tudi če koda ali zaklep odpovesta.
CREATE TABLE table_slots (
  table_id        INT UNSIGNED NOT NULL,
  slot_start      DATETIME NOT NULL,              -- UTC, poravnano na slot_minutes
  tenant_id       INT UNSIGNED NOT NULL,
  reservation_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (table_id, slot_start),
  KEY ix_slot_res (tenant_id, reservation_id),
  CONSTRAINT fk_slot_tbl FOREIGN KEY (tenant_id, table_id) REFERENCES dining_tables (tenant_id, id),
  CONSTRAINT fk_slot_res FOREIGN KEY (tenant_id, reservation_id) REFERENCES reservations (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Revizijska sled. Samo dodajanje; aplikacija nima UPDATE/DELETE na tej tabeli.
CREATE TABLE reservation_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  reservation_id  BIGINT UNSIGNED NOT NULL,
  event           VARCHAR(32) NOT NULL,           -- created, moved, status_changed, cancelled, sms_sent...
  actor_type      ENUM('staff','agent','guest','system') NOT NULL,
  actor_id        INT UNSIGNED NULL,
  before_json     JSON NULL,
  after_json      JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ev_res (tenant_id, reservation_id, created_at),
  CONSTRAINT fk_ev_res FOREIGN KEY (tenant_id, reservation_id) REFERENCES reservations (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE staff_users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,          -- password_hash(), PASSWORD_DEFAULT
  role            ENUM('staff','manager') NOT NULL DEFAULT 'staff',
  name            VARCHAR(120) NOT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_tenant (tenant_id, id),
  UNIQUE KEY uq_staff_email (email),               -- globalno enolična: prijava brez izbire restavracije
  CONSTRAINT fk_staff_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Naprava, na kateri je bil nastavljen PIN. PIN velja samo tam, kjer je bil
-- nastavljen po prijavi z geslom. Brez gesla se PIN ne da uporabiti drugje.
CREATE TABLE staff_devices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  device_token_hash CHAR(64) NOT NULL,            -- sha256 naključnega žetona v napravi
  pin_hash        VARCHAR(255) NOT NULL,          -- password_hash() PIN-a
  failed_pins     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  revoked_at      DATETIME NULL,
  last_used_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dev_token (device_token_hash),
  CONSTRAINT fk_dev_user FOREIGN KEY (tenant_id, user_id) REFERENCES staff_users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE sessions (
  id_hash         CHAR(64) NOT NULL,              -- sha256 ID-ja seje
  tenant_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  csrf_token      CHAR(43) NOT NULL,
  expires_at      DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_hash),
  KEY ix_sess_exp (expires_at),
  CONSTRAINT fk_sess_user FOREIGN KEY (tenant_id, user_id) REFERENCES staff_users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Ključ na tenanta za Tatjano. Javni key_id + skrivnost; v bazi samo hash.
CREATE TABLE api_keys (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  location_id     INT UNSIGNED NOT NULL,          -- ključ je vezan na lokacijo
  key_id          CHAR(16) NOT NULL,
  secret_hash     CHAR(64) NOT NULL,              -- sha256 (skrivnost ima 256 bitov entropije)
  label           VARCHAR(80) NOT NULL,
  revoked_at      DATETIME NULL,
  last_used_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_key_id (key_id),
  CONSTRAINT fk_key_loc FOREIGN KEY (tenant_id, location_id) REFERENCES locations (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

CREATE TABLE sms_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  reservation_id  BIGINT UNSIGNED NULL,
  kind            ENUM('confirmation','reminder','cancellation','change') NOT NULL,
  to_phone        VARCHAR(40) NOT NULL,
  status          ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  provider        VARCHAR(32) NOT NULL DEFAULT 'log',
  provider_ref    VARCHAR(80) NULL,
  error           VARCHAR(255) NULL,
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME NULL,
  PRIMARY KEY (id),
  -- Isti tip SMS za isto rezervacijo gre največ enkrat (cron je varen pri ponovnem zagonu).
  UNIQUE KEY uq_sms_once (reservation_id, kind),
  KEY ix_sms_queue (status, created_at),
  CONSTRAINT fk_sms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- Omejitev hitrosti prijave in API. Datotečni števci (kot v Tatjani) tečejo
-- tudi tu; tabela je za zaklep računov, ki mora preživeti brisanje datotek.
CREATE TABLE login_attempts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash      CHAR(64) NOT NULL,
  ip_hash         CHAR(64) NOT NULL,
  success         TINYINT(1) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_la_email (email_hash, created_at),
  KEY ix_la_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_slovenian_ci;

-- ---------------------------------------------------------------------------
-- ZAKLEPANJE (kako ustvaritev rezervacije ostane atomarna)
--
-- V eni transakciji (READ COMMITTED, InnoDB):
--   1. SELECT ... FROM locations WHERE tenant_id = ? AND id = ? FOR UPDATE.
--      Vrstica lokacije je mutex: vsi, ki pišejo rezervacije te lokacije
--      (ustvari, spremeni, stanje), čakajo v vrsti. En zaklep, en vrstni red,
--      zato deadlock med pisci ni mogoč. Restavracija ima nekaj zapisov na
--      minuto, ne na sekundo; serializacija na lokacijo nič ne stane.
--      READ COMMITTED, ker pri REPEATABLE READ branje po čakanju na zaklep
--      lahko vidi posnetek izpred čakanja in spregleda tekmečevo rezervacijo.
--   2. Preveri urnik, zaprte dni, kapaciteto, min_lead, max_days_ahead.
--   3. SELECT iz table_slots za kandidate v [starts_at, ends_at): kdor je zaseden, izpade.
--   4. Izberi mizo (ali kombinacijo) z najmanjšo odvečnostjo sedežev.
--   5. INSERT reservations, reservation_tables, table_slots (ena vrstica na rezino),
--      INSERT reservation_events.
--   6. COMMIT.
-- Varnostna mreža: če bi korak 1 izpadel (napaka v kodi, drug pot pisanja),
-- UNIQUE (table_id, slot_start) vrže 1062 in transakcija se razveljavi.
-- Koda 1062 prevede v "termin je zaseden", ne v 500.
--
-- Idempotentnost: ponovni zahtevek z istim idempotency_key vrne obstoječo
-- rezervacijo (UNIQUE uq_res_idem), ne ustvari druge.
--
-- Deadlock/lock wait timeout: koda ponovi transakcijo največ 2-krat, nato vrne
-- napako "poskusi znova". Ne ugiba.
-- ---------------------------------------------------------------------------
