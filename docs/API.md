# API pogodba (v1)

Stanje: predlog v fazi 0. Osnova: `https://<poddomena>/api/v1`. Samo HTTPS. JSON v UTF-8.

## Splošno

Uspeh:
```json
{ "ok": true, "data": { ... }, "request_id": "a1b2c3d4e5f6" }
```
Napaka:
```json
{ "ok": false, "error": { "code": "slot_taken", "message": "Termin je zaseden." }, "request_id": "..." }
```

| HTTP | `code` | Pomen |
|---|---|---|
| 400 | `invalid_input` | manjka ali neveljavno polje; `error.fields` pove katero |
| 401 | `unauthorized` | ni seje / napačen ključ |
| 403 | `forbidden`, `csrf_failed` | vloga ne dovoli / CSRF |
| 404 | `not_found` | ne obstaja ali je tuj tenant (namerno enako) |
| 405 | `method_not_allowed` | |
| 409 | `slot_taken` | termin zaseden ob zapisu |
| 409 | `idempotency_conflict` | isti ključ, drugačno telo |
| 409 | `invalid_transition` | npr. prihod na odpovedano rezervacijo |
| 413 | `too_large` | telo > 16 KB |
| 422 | `outside_hours`, `closed`, `party_too_large`, `too_soon`, `too_far` | poslovno pravilo |
| 422 | `no_table_for_party` | skupina ne gre za nobeno mizo niti v prazni restavraciji (zato ni `slot_taken`) |
| 422 | `table_too_small` | osebje je izbralo mize z manj sedeži, kot je oseb |
| 429 | `rate_limited` | glava `Retry-After` |
| 500 | `internal_error` | brez podrobnosti |
| 503 | `unavailable` | baza nedosegljiva; Tatjana preveže na restavracijo |

Časi: vhod in izhod v lokalnem času lokacije, ISO 8601 z odmikom (`2026-12-05T19:30:00+01:00`). Strežnik pretvori v UTC. Za Tatjano izhod vsebuje še `spoken` ("v soboto, 5. decembra, ob pol osmih zvečer").

Idempotentnost: vsak `POST`, ki ustvari rezervacijo, zahteva glavo `Idempotency-Key` (8-64 znakov `[A-Za-z0-9_-]`). Ponovitev z istim ključem in istim telesom vrne prvi odgovor; z drugačnim telesom `409 idempotency_conflict`.

## Osebje (`/staff`)

Avtentikacija: piškotek seje. Vsi ne-GET zahtevajo `X-CSRF-Token`.

| Metoda in pot | Vloga | Opis |
|---|---|---|
| `POST /staff/login` | - | `{email, password}` -> seja, `csrf_token`. Rate limit, zaklep. |
| `POST /staff/login/pin` | - | `{pin}` + piškotek naprave -> seja |
| `POST /staff/device/pin` | staff | nastavi PIN na tej napravi `{pin}` (4-6 števk) |
| `POST /staff/logout` | staff | |
| `GET  /staff/me` | staff | uporabnik, vloga, lokacije |
| `GET  /staff/day?location=&date=` | staff | rezervacije dneva + zasedenost miz; podpira `If-None-Match` (ETag) -> 304 |
| `GET  /staff/availability?location=&date=&party=` | staff | prosti začetki |
| `POST /staff/reservations` | staff | ustvari; `{location_id, starts_at, party_size, guest_name, guest_phone, guest_email?, notes?, table_ids?, duration_min?}` |
| `GET  /staff/reservations/{id}` | staff | podrobnosti + sled dogodkov |
| `PATCH /staff/reservations/{id}` | staff | sprememba časa, osebe, mize, kontakt; ponovno preveri razpoložljivost atomarno |
| `POST /staff/reservations/{id}/status` | staff | `{status: arrived|completed|no_show|cancelled, reason?}` |
| `GET  /staff/reservations?phone=&q=` | staff | iskanje |
| `GET/POST/PATCH /staff/admin/tables` | manager | mize, kombinacije |
| `GET/PUT /staff/admin/hours` | manager | urnik |
| `GET/POST/DELETE /staff/admin/closures` | manager | zaprti dnevi |
| `GET/PATCH /staff/admin/location` | manager | pravila (trajanje, max oseb, ...) |
| `GET/POST/PATCH /staff/admin/users` | manager | uporabniki, preklic naprav |
| `GET  /staff/admin/sms-log` | manager | |

`409 slot_taken` vsebuje `error.alternatives`: do 3 najbližji prosti začetki istega dne (`[{ "starts_at": "..." }]`). Uspešno ustvarjanje vrne 201; ponovitev z istim `Idempotency-Key` vrne 200 in isto rezervacijo. Za osebje je `Idempotency-Key` neobvezen (PWA ga pošilja), za Tatjano obvezen.

Stanje faze 1: poti `/staff` obstajajo, a so zaprte (401), dokler faza 2 ne doda prijave.

`table_ids` je izbira osebja; brez nje izbere koda. Osebje sme ročno prekoračiti `default_duration_min` in `min_lead_minutes` (gost je v lokalu), ne sme pa dvojne zasedbe ali kapacitete mize.

Prehodi stanj: `confirmed -> arrived -> completed`; `confirmed -> cancelled|no_show`; `arrived -> completed`. Drugo je `409 invalid_transition`.

## Tatjana (`/agent`)

Avtentikacija: `X-Api-Key-Id` in `X-Api-Secret`. Ključ določa tenanta in lokacijo; telo jih ne vsebuje. Imena orodij so slovenska, ker jih tako vidi model.

### `POST /agent/preveri_razpolozljivost`
Vhod: `{ "datum": "2026-12-05", "ura": "19:30"?, "osebe": 4 }`
Izhod: `{ "na_voljo": true|false, "termini": [{ "zacetek": "...", "spoken": "..." }], "opomba": "..." }`
Brez `ura` vrne do 6 prostih začetkov tega dne. Z `ura` pove, ali je točno ta prost, in do 3 najbližje alternative. `opomba` je navodilo modelu ("Ne obljubljaj termina, ki ni na seznamu.").

### `POST /agent/ustvari_rezervacijo` (dve fazi)
Potrditev izvaja koda, ne prompt.

1. `{ "faza": "predlog", "zacetek": "...", "osebe": 4, "ime": "...", "telefon": "+386...", "opombe"? }`
   -> preveri vse brez zapisa, vrne `{ "povzetek": "...", "potrditveni_zeton": "..." }`. Žeton je HMAC nad (key_id, zacetek, osebe, ime, telefon, opombe), velja 5 minut.
2. `{ "faza": "potrditev", "potrditveni_zeton": "...", ...ista polja }` + `Idempotency-Key`
   -> atomaren zapis; `{ "rezervirano": true, "koda": "K7P2QX", "spoken": "..." }` ali `{ "rezervirano": false, "razlog": "slot_taken", "alternative": [...] }`.

Brez veljavnega žetona ali z drugačnimi polji ni zapisa. Model mora gostu povzetek prebrati in dobiti "da", preden pokliče fazo 2; če to preskoči, nima žetona. (To ne dokaže, da je gost res rekel "da"; prepreči pa zapis brez predhodnega povzetka in zapis z drugimi podatki, kot so bili povzeti.)

`telefon` agent privzeto vzame iz SIP (`sip.phoneNumber`); kontrola formata je v PHP.

### `POST /agent/spremeni_rezervacijo` (dve fazi)
Identifikacija: `koda` IN `telefon` se morata ujemati. Ob neujemanju enak odgovor kot za neobstoječo (vzorec iz Tatjanin `OrderTool`). Spremenljivo: `zacetek`, `osebe`. Faza `predlog` vrne povzetek in žeton, faza `potrditev` zapiše atomarno.

### `POST /agent/odpovej_rezervacijo` (dve fazi)
Identifikacija kot zgoraj. `predlog` -> povzetek, `potrditev` -> stanje `cancelled`, sproščene rezine, SMS o odpovedi.

### Zaščite orodij
- Max oseb, okno vnaprej, urnik: v PHP. Nad `max_party_size` vrne `party_too_large` z opombo "povej, da za večje skupine pokliče restavracijo".
- Orodja nikoli ne vrnejo podatkov druge rezervacije razen tiste, ki je identificirana s kodo IN telefonom.
- Seznam rezervacij po telefonu je dovoljen samo, če se telefon ujema s številko klicatelja (agent pošlje `X-Caller-Phone` iz SIP; skrita številka = ni seznama).

## Javno: odpoved gosta
`GET /r/{cancel_token}` -> stran s povzetkom in gumbom. `POST /r/{cancel_token}` (z obrazcem in CSRF žetonom strani) -> odpoved. Žeton ima 256 bitov, velja do začetka rezervacije. GET nikoli ne spremeni stanja (predogledi povezav v SMS aplikacijah bi sicer odpovedovali rezervacije).

## Omejitve hitrosti (začetne, nastavljive v config)

| Kaj | Ključ | Meja |
|---|---|---|
| prijava z geslom | IP | 10 / 15 min |
| prijava z geslom | e-pošta | 5 napačnih -> zaklep 15 min, nato podvojitev |
| PIN | naprava | 5 napačnih -> preklic naprave |
| staff API | seja | 300 / min |
| agent API | key_id | 60 / min, 2000 / dan |
| `/r/*` | IP | 20 / min |
