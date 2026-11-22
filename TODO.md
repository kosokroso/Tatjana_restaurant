# TODO

## Faza 0
- [x] Pregled repozitorija Tatjane (samo branje)
- [x] Prvi krog vprašanj (baza, prijava, domena, SMS, številka, obseg, pilot)
- [x] docs/ARHITEKTURA.md, sql/schema.sql, docs/API.md, docs/VARNOST.md, docs/TESTNI-NACRT.md
- [x] Luka potrdil (22. 11. 2026)

## Faza 1: jedro
- [x] `config/config.example.php`, autoload, router, Kernel, vstop `public/api/index.php`
- [x] tekač testov (`tests/run.php`)
- [x] repository sloj s `TenantContext` + strukturni test (vsak SQL ima `tenant_id`, SQL samo v repositoryjih)
- [x] `AvailabilityCalculator` + unit testi (urnik, zaprti dnevi, min_lead, okno vnaprej, rezine, poletni čas, izbira miz, kombinacije)
- [x] `ReservationService` (zaklep lokacije, rezine, idempotentnost, premik, stanja, revizijska sled)
- [x] staff API kontrolerji (zaprti z 401 do faze 2)
- [x] integracijski testi, test izolacije tenantov, test sočasnosti (napisani)
- [ ] testna baza na cPanelu + `tests/config.test.php` (Luka)
- [ ] integracijski testi zeleni na cPanel MySQL
- [ ] test sočasnosti zelen (50 iteracij), vključno z izklopljenim zaklepom

## Odprta vprašanja
- [ ] Katere PHP verzije ponuja cPanel ("Select PHP Version")? Vpiši v docs/VARNOST.md.
- [ ] MySQL ali MariaDB na cPanelu in katera verzija? (CHECK omejitve: MySQL 8.0.16+ / MariaDB 10.2+)
- [ ] `max_user_connections` na gostovanju (določa `concurrency_workers`)
- [ ] Ali se poddomena v cPanelu da usmeriti na podmapo `public/` repozitorija?
- [ ] Ime poddomene (npr. `rezervacije.tatjana.ai`); ali je domena `tatjana.ai` na istem cPanelu?
- [ ] Pilotna restavracija: ime, št. miz, trajanje obroka, max oseb, združevanje miz, čakalni seznam, kako zdaj vodijo rezervacije.
- [ ] SMS ponudnik (pred fazo 4).
- [ ] Rok hrambe kontaktov gostov (predlog 90 dni).
- [ ] Ali je repo javen (kot Tatjana) ali zaseben?

## Faza 2 (po potrditvi faze 1)
- [ ] prijava z geslom, seja v bazi, CSRF, PIN na napravi, zaklep
- [ ] `StaffAuthenticator` namesto `DenyAllAuthenticator`
- [ ] PWA zasloni

## Kasneje
- [ ] Faza 4: GDPR anonimizacija mora počistiti tudi `reservation_events.before_json/after_json` (vsebujeta ime in telefon)
- [ ] Faza 4: admin za mize/urnik zaklene vrstico lokacije; `slot_minutes` samo brez prihodnjih rezervacij
- [ ] Faza 5: `.cpanel.yml` (izvzem `tests/`, `config/config.php`, `logs/`)

## Pred pilotom
- [ ] Pogodba o obdelavi osebnih podatkov z restavracijo
- [ ] Test obnove varnostne kopije
- [ ] Opomnik: nadgradnja PHP 3 mesece pred koncem podpore izbrane verzije
