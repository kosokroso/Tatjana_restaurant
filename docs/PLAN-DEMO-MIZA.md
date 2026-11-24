# Plan: demo restavracija s Tatjano na +386 5 7774121

Stanje na 24. 11. 2026. Predlog, ni še potrjen.

## Kaj obstaja

### Kolegova aplikacija `kreativnisplet.si/miza/` (`public_html/miza`)

Deluje: prijava, pregled dneva s tlorisom in časovnico, rezervacije, naročila za prevzem, meni, tloris, nastavitve, uporabniki. Prijavljena je "Demo restavracija" z 11 mizami (1-7, T1-T4).

Zgradba: vanilla PHP (`api.php?r=...`, `lib/booking.php`, `orders.php`, `db.php`, `schema.php`), vanilla JS (`assets/app.js`, 53 KB). Brez build koraka, MySQL. Ustreza omejitvam cPanela.

**Že ima API za Tatjano** (`api.php?r=ai/...`, ključ `X-Api-Key` na restavracijo):

| Pot | Kaj |
|---|---|
| `ai/info` | ime, naslov, urnik, cone, zaprti dnevi, pozdrav |
| `ai/availability` | preveri termin, vrne mize ali razlog + alternative |
| `ai/day_slots` | prosti termini dneva |
| `ai/reservations` | ustvari rezervacijo |
| `ai/reservations/find` | poišče po kodi, telefonu ali **imenu** |
| `ai/reservations/change`, `/cancel` | po kodi ali telefonu |
| `ai/menu`, `ai/orders/quote`, `ai/orders` | meni in naročila za prevzem |

Preverjeno od zunaj: `config.php`, `lib/`, `data/`, `error_log`, `.htaccess` vrnejo 403. `error_log` ima en vnos (23. 11., napačno geslo baze, odpravljeno).

### Kar manjka ali je tvegano, preden številka postane javna

| # | Problem | Posledica ob klicu | Popravek |
|---|---|---|---|
| 1 | `ai/reservations/find` išče po delu imena | klicatelj reče "rezervacije na ime Novak" in sliši tuje termine in imena | iskanje samo po kodi ali po številki klicatelja |
| 2 | `cancel`/`change` po telefonu iz telesa zahtevka | model lahko vpiše katero koli številko in odpove tujo rezervacijo | telefon vpiše agent iz SIP (`sip.phoneNumber`), ne model; skrita številka = samo s kodo |
| 3 | `ai/reservations` zapiše takoj | model lahko rezervira brez potrjenega povzetka | potrditev v kodi: agent zapiše šele po pritrditvi na prebran povzetek (vzorec iz Tatjane) |
| 4 | `change_reservation` ne zaklene restavracije | dve hkratni spremembi lahko dasta isto mizo | `SELECT ... FOR UPDATE` kot v `create_reservation` |
| 5 | ni varnostne mreže v bazi proti dvojni zasedbi | napaka v kodi = dvojna rezervacija | za demo sprejemljivo; za produkcijo rezine z UNIQUE (kot v tatjana_restaurant) |
| 6 | `source` pride iz telesa | statistika se da ponarediti | fiksno `tatjana` pri `ai/*` |
| 7 | prijava brez omejitve poskusov | ugibanje gesla | omejitev po IP in e-pošti |
| 8 | koda ni v gitu (urejanje v File Managerju) | ni zgodovine, ni povratka | repo pred prvo spremembo |

1-3 so pogoj za javno številko. 4-8 pred pravo stranko.

### Kar imamo mi

- `tatjana_ai/voice-agent/agent.py` (2062 vrstic): telefon, prepis, glas, VAD, tišina, meje klicev (`call-guard.php`), dnevnik pogovora, varovala ponavljanja. Orodja so za agencijo (storitve, povpraševanje, termin).
- `tatjana_restaurant`: preizkušeno jedro rezervacij (zaklep, rezine z UNIQUE, izolacija), brez UI. Integracijski testi še niso tekli.
- Številka +386 5 7774121, identiteta odobrena, brez trunka.

## Odločitev, ki je pred vsem drugim

Dva backenda delata isto. Za demo predlagam **kolegovo aplikacijo**, ker ima UI in AI poti že narejene. Iz `tatjana_restaurant` prenesemo samo varovala (točke 1-4). Kateri backend ostane za produkcijo, odločimo po demu. Dva vzporedna backenda dolgoročno ne smeta ostati.

## Koraki (vsak se konča preizkušen, po eno)

### 1. Koda v git
Narejeno 24. 11. 2026: `public_html/miza` je v tem repozitoriju v mapi `miza/`, brez `config.php`. Spletni naslov ostane `kreativnisplet.si/miza/`.
Deploy iz gita deluje od 24. 11. 2026: GitHub `kosokroso/Tatjana_restaurant` (zaseben), cPanel klon v `~/repositories/tatjana_restaurant` prek SSH aliasa `github-tatjana-restaurant` (`~/.ssh/config`, ključ `tatjana_restaurant_deploy`, na GitHubu read-only deploy key). Lasten ključ je potreben, ker GitHub istega deploy ključa ne sprejme za dva repozitorija (`id_ed25519` je ključ za KS_KurivoGorica).
Postopek: push, nato v cPanelu Git Version Control -> Update from Remote -> Deploy HEAD Commit. `.cpanel.yml` naloži samo `miza/`, brez `--delete`.

**Kolega naj od zdaj ureja kodo v gitu, ne v File Managerju**, sicer jo naslednji deploy prepiše.

### 2. Varovala v `/miza` API (točke 1, 2, 4, 6) — narejeno, na strežniku 24. 11. 2026
Preizkušeno lokalno s SQLite. Na produkciji preverjeno: aplikacija dela, `ai/*` brez ključa vrne 401, zaščitene poti 403. `ai/*` s ključem na produkcijski bazi še ni preizkušen.
- `find`: samo `code` ali `caller_phone`; brez iskanja po imenu za `ai/*`.
- `cancel`/`change`: `caller_phone` mora biti enak telefonu rezervacije, ali pa je podana koda in se ujema tudi telefon.
- `change_reservation`: zaklep restavracije.
- `source` fiksen.
Končano, ko: curl z napačno številko ne vrne in ne spremeni tuje rezervacije.

### 3. Demo podatki — narejeno 24. 11. 2026
Odločitev: ime ostane "Demo restavracija", brez naslova in brez preveze na človeka (Tatjana ob veliki skupini ali napaki reče, naj pokličejo kasneje). Urnik, mize in meni so iz seed podatkov. Obstoječi API ključ ostane.
Preverjeno na produkciji s ključem: `ai/info`, `ai/availability` (4 osebe = miza 3, 105 min), 12 oseb = `group_too_large` + `handoff`, `ai/day_slots` (35 terminov 12:00-20:30), iskanje po imenu in po izgovorjeni številki zavrnjeno, napačen ključ 401.

Prvotni predlog:
V aplikaciji: ime, naslov, urnik, zaprti dnevi, meni z alergeni, cone, `max_party_online`. Nov API ključ v Nastavitvah. Ključ gre samo v LiveKit secrets, ne v klepet in ne v repozitorij.
Končano, ko: `ai/info` vrne pravilne podatke.

### 4. Restavracijski agent
Ločen agent (`AGENT_IME=tatjana-miza`), ista infrastruktura kot Tatjana. Predlog zgradbe: nov modul `voice-agent/restavracija.py` z razredom `RestavracijskiAsistent` in orodji, ki kličejo `/miza/api.php?r=ai/...`. Prepis, glas, VAD, tišino in meje klicev uvozi iz `agent.py`. Tako se obstoječa Tatjana ne spremeni.

Orodja: `podatki_restavracije`, `preveri_termin`, `prosti_termini`, `rezerviraj` (z vgrajeno potrditvijo), `moje_rezervacije`, `spremeni_rezervacijo`, `odpovej_rezervacijo`, `preveza` (na telefon restavracije). Naročila za prevzem v drugem koraku, po delujočih rezervacijah.

Varovala v kodi agenta: telefon iz SIP, potrditev pred zapisom, ob napaki API (5xx, časovna omejitev) preveza na telefon restavracije, meje klicev prek obstoječega `call-guard.php`.
Končano, ko: `lk agent console` (brez telefona) opravi rezervacijo, spremembo in odpoved, in vse se vidijo v `/miza`.

### 5. Scenariji
`scenarios-miza.yaml`: normalni (rezervacija, polno ob 19h + alternativa, sprememba, odpoved, velika skupina = preveza, zaprt dan) in napadi (tuje ime, tuja številka, rezervacija brez potrditve, "direktor je obljubil mizo", prompt injection v imenu). Preverja se tudi stanje v bazi, ne samo pogovor.
Končano, ko: vsi zeleni.

### 6. Telefonija
- DIDWW: številki 7774121 dodeli obstoječi trunk "Kreativni Splet" (ta že pošilja na LiveKit SIP naslov z `{DID}`), ali nov trunk z istim ciljem.
- LiveKit: nov inbound trunk samo za `+38657774121`, z `allowed_addresses` na signalne naslove DIDWW; dispatch pravilo na agenta `tatjana-miza`.
- `lk agent deploy` za novega agenta z ločenimi secrets.
Končano, ko: pravi klic na 05 777 41 21 ustvari rezervacijo, ki se pojavi v pregledu dneva; klic na obstoječi številki še vedno dobi staro Tatjano.

### 7. Demo
Kratek scenarij za predstavitev (rezervacija, zasedeno + alternativa, odpoved), preizkušen trikrat zapored s pravim klicem.

## Česa ta plan ne pokriva
UX izboljšave (`/miza` ni PWA: ni manifesta ne service workerja; polling 15 s; ni pasu "zastarelo"), SMS, GDPR rok hrambe, združitev backendov. To pride po demu.
