# Arhitektura

Stanje: predlog v fazi 0. Ni še kode aplikacije.

## Cilji in omejitve

- Gostovanje: shared cPanel. Brez SSH, brez Composerja, brez procesov v ozadju razen cron.
- Odločitve: vanilla PHP (min. 8.3, ciljno 8.4+), MySQL/MariaDB InnoDB, brez ogrodja, ločena baza.
- Posledice: lasten tanek router, ročni PSR-4 autoload, polling v PWA, cron za SMS in čiščenje.
- Obseg v1: rezervacije, PWA za osebje, orodja za Tatjano, SMS. Brez naročil, računov, plačil, FURS.

## Plasti

```
Klicatelj -> LiveKit agent (Python)
                | HTTPS, X-Api-Key-Id + X-Api-Secret (na lokacijo)
                v
         public/api/index.php  <-- PWA (seja + CSRF)
                |
           Router -> Controller (tanek: vhod, izhod, status)
                |
           Service (Availability, Reservations, Tables, Sms, Auth)
                |
           Repository (PDO; vsaka metoda zahteva TenantContext)
                |
              MySQL
```

Pravila med plastmi:

1. Controller ne vsebuje poslovne logike in ne piše SQL.
2. Service ne pozna HTTP (ne bere `$_SERVER`, ne piše headerjev).
3. Repository je edino mesto s SQL. Konstruktor zahteva `TenantContext`; vsak `WHERE` in `INSERT` vsebuje `tenant_id` iz njega, nikoli iz vhoda zahtevka.
4. Staff in Tatjana kličeta iste service razrede. Razlikuje ju samo avtentikacija (kdo je `TenantContext`) in `source` (`staff` / `phone`).

## Mape

```
public/                  <- document root poddomene (edino javno)
  index.html             PWA lupina
  manifest.json
  sw.js                  service worker (v korenu, da ima obseg celotnega izvora)
  app/                   ES moduli, CSS, ikone (brez build koraka)
  api/index.php          edini vstop za API
  r/index.php            javna povezava za odpoved gosta (/r/<cancel_token>)
src/                     PSR-4 "App\" (izven public/)
  Http/  Router, Request, Response, Controllers/
  Service/  Repository/  Domain/  Support/
config/
  config.example.php     v gitu
  config.php             NI v gitu
cron/                    skripte za cron (SMS, čiščenje, GDPR)
sql/                     schema.sql, migrations/NNNN_*.sql
tests/                   unit/, integration/, concurrency/
docs/
```

Document root kaže na `public/`. Če cPanel poddomene ni mogoče usmeriti na podmapo, se `src/`, `config/` in `sql/` zaščitijo z `.htaccess` (`Require all denied`) in z lokacijo izven `public_html`. To je odprta točka za fazo 5 (odvisna od cPanel nastavitev).

## Router

Ročni: tabela `[metoda, vzorec, razred, metoda, zahtevana avtentikacija]`. Neznana pot 404, napačna metoda 405 z `Allow`. Verzija v poti (`/api/v1`). Brez regularnih izrazov iz uporabnikovega vnosa; parametri poti so samo `\d+` ali žeton fiksne dolžine.

## Avtentikacija

Dve ločeni poti, vsaka ima svoj middleware. Prekrivanja ni.

| Pot | Preverjanje | Rezultat |
|---|---|---|
| `/api/v1/staff/*` | seja (piškotek `HttpOnly; Secure; SameSite=Lax`), `X-CSRF-Token` pri vseh ne-GET | `TenantContext(tenant, user, role)` |
| `/api/v1/agent/*` | `X-Api-Key-Id` + `X-Api-Secret`, primerjava `hash_equals` nad sha256 | `TenantContext(tenant, location, actor=agent)` |

- Seja je v tabeli `sessions` (ne v datotečnih PHP sejah): odpoved seje (odjava, zaklep računa) velja takoj. V bazi je samo hash ID-ja.
- PIN: naprava po prijavi z geslom dobi naključen žeton (hash v `staff_devices`) in uporabnik nastavi PIN. PIN brez žetona naprave ne velja. Po 5 napačnih PIN-ih je naprava preklicana in zahteva geslo.
- Prazna ali manjkajoča skrivnost pomeni zaprto, ne odprto (enako kot `TOOL_SECRET` v Tatjani).
- Ključ za Tatjano je vezan na eno lokacijo. Telo zahtevka ne vsebuje `tenant_id`; ker ga ne sprejmemo, ga napadalec ne more podtakniti.

## Obravnava napak

- Domenske izjeme (`SlotTaken`, `ValidationFailed`, `NotFound`, `Forbidden`) se v kontrolerju prevedejo v kodo in JSON obliko iz `docs/API.md`.
- `Throwable` na vrhu: zapiše `error_log` z `request_id`, odjemalcu vrne `500 internal_error` brez podrobnosti.
- Napaka baze se nikoli ne pokaže v odgovoru.
- `display_errors=0` v produkciji; preverja se v setupu.
- Pri `NotFound` in `Forbidden` na tujem tenantu je odgovor enak (404), da se ne razkrije obstoj.

## Logiranje

- JSON Lines v `logs/` izven `public/`, dnevne datoteke, hramba 14 dni (po vzoru Tatjanin `Logger`).
- Vsak zahtevek ima `request_id`, ki je tudi v odgovoru (`X-Request-Id`).
- Maskiranje: telefon `***123`, e-pošta `a***@domena`, ime kot inicialke, `notes` in prosto besedilo `[skrito]`. Maskira se v `Logger`, ne na mestu klica, da ga nihče ne pozabi.
- V log gre vedno tenant_id, nikoli gesla, PIN-i, seje, `X-Api-Secret`, `cancel_token`.
- Revizijska sled rezervacij (`reservation_events`) je v bazi in je ločena od logov.

## Konfiguracija

`config/config.php` (ni v gitu, deploy ga ne povozi). Vsebuje samo okolje: DB poverilnice, `APP_ENV`, `LOG_DIR`, `SMS_PROVIDER` + ključi, `SESSION_LIFETIME`, `REQUIRE_HTTPS`. Vse, kar je specifično za restavracijo (urnik, trajanje obroka, max oseb, združevanje), je v bazi (`locations`, `opening_hours`, `closures`). V kodi ni nobenega imena ali nastavitve restavracije.

## Zaklepanje

Vsak, ki piše rezervacije lokacije (ustvari, spremeni, stanje), najprej zaklene vrstico te lokacije (`SELECT ... FOR UPDATE`) v transakciji READ COMMITTED. Pisci iste lokacije gredo zato v vrsto. En zaklep pomeni en vrstni red, zato med njimi ni deadlocka. Varnostna mreža je `UNIQUE (table_id, slot_start)` v `table_slots`. Podrobnosti so v `sql/schema.sql`, razdelek ZAKLEPANJE.

Spreminjanje miz, urnika in `slot_minutes` (faza 4) mora zakleniti isto vrstico. `slot_minutes` se sme spremeniti samo, ko lokacija nima prihodnjih rezervacij: obstoječe rezine so poravnane na staro mrežo.

## Čas

Shranjujemo UTC. `locations.timezone` je edini vir lokalnega časa. Vse primerjave urnika se izračunajo v lokalnem času lokacije, rezultat pretvori v UTC. Prehod na poletni čas ima poseben test (rezervacija ob 02:30 v noči prehoda, urnik čez polnoč).

## Deploy

cPanel Git: **Update from Remote**, nato **Deploy HEAD Commit**. `.cpanel.yml` kopira z `rsync` s `--chmod=D755,F644` in izvzema `.git`, `config.php`, `logs`, `tests`, `docker`. Frontend je commitan kot je (brez builda). PWA `sw.js` ima verzijo predpomnilnika, ki se dvigne ob vsaki spremembi lupine; brez tega stari odjemalci ostanejo na stari kodi.

## Frontend (brez builda)

ES moduli, ena datoteka na zaslon, majhen skupni `api.js` (fetch, CSRF, obravnava 401/409/offline). Polling 5 s samo ko je zaslon viden (`visibilitychange`); ob izgubi povezave pas z napisom "Podatki so zastareli od HH:MM", gumbi za pisanje onemogočeni. Pisanje brez povezave se ne spravi v čakalno vrsto: tiho zakasnjena rezervacija bi bila nevarnejša od zavrnjene.

## Tehnične omejitve in tveganja

- Ni preverjeno: PHP/MySQL verzije na ciljnem cPanelu (preveri se v fazi 5 in pred prvo kodo).
- Datotečni rate limiter ne deluje čez več strežnikov; pri enem cPanel strežniku je v redu.
- Polling pri 5-30 restavracijah z nekaj napravami: red velikosti 10-100 zahtevkov/s ob konicah; zato lahek endpoint `since=<version>` (glej API.md), ki vrne 304, če se dan ni spremenil.
