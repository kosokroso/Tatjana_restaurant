# Tatjana Restaurant

Stanje na 22. 11. 2026: faza 1 (jedro) je napisana. Unit testi so zeleni. Integracijski testi in test sočasnosti čakajo na testno bazo na cPanelu.

## Kaj je

Sistem rezervacij za restavracije z aplikacijo za osebje (PWA). Telefonska asistentka Tatjana ga uporablja prek istega API kot osebje. Računi, blagajna in FURS niso del projekta; restavracija obdrži svoj POS.

## Zakaj ta smer

- Glavni konkurent (Microgramm) po javnih straneh nima rezervacij gostov in nima telefonije. To je vrzel; blagajna ni.
- Lastna davčna blagajna je 8-18+ mesecev in visoko tveganje.
- Tatjana telefon že obstaja. Manjka skupni rezervacijski sistem in zaslon za osebje.

## Sprejete odločitve

| Odločitev | Razlog |
|---|---|
| Vanilla PHP 8.4 (min. 8.3), MySQL InnoDB, brez ogrodja | shared cPanel brez SSH in Composerja; ista pot kot Tatjana |
| Ločena baza | izolacija, ločena kopija, GDPR brisanje brez vpliva na WordPress |
| Poddomena za PWA | svoj izvor: piškotki, service worker in CSP niso deljeni z drugo stranjo |
| Prijava: e-pošta + geslo, nato PIN na napravi | tablica v lokalu, hitra menjava izmene; PIN brez žetona naprave ne velja |
| SMS: vmesnik + ponudnik "log" | ponudnik ni izbran; izbere se pred fazo 4 |
| Nova DIDWW številka na restavracijo | ista pot kot Tatjana, čista izolacija; API številke ne pozna, pozna ključ lokacije |
| Pričakovano 5-30 restavracij v letu | cPanel in polling zadostujeta; ETag/304 na dnevnem pregledu zmanjša obremenitev |
| Dvojna zasedba: zaklep vrstice lokacije + UNIQUE na rezinah mize | zaklep serializira pisce ene lokacije brez deadlockov, UNIQUE je varnostna mreža, ki ne zavisi od kode |
| READ COMMITTED v pisalnih transakcijah | pri REPEATABLE READ bi branje po čakanju na zaklep lahko videlo star posnetek |
| Integracijski testi na `*_test` bazi na cPanelu (Remote MySQL) | lokalno ni MySQL ne Dockerja; tako testi tečejo na isti verziji kot produkcija |
| Lasten tekač testov namesto PHPUnit | brez Composerja in brez nove odvisnosti |
| Potrditev pri Tatjani prek dvofaznega klica z žetonom | potrditev mora biti v kodi; v Tatjani je vsako varovalo v promptu vsaj enkrat odpovedalo |

## Kaj ponovno uporabimo iz Tatjane

Kopiramo vzorec in ga prepišemo za PHP 8.4 (tipizirane lastnosti, `readonly`, `strict_types`). Tatjanine kode ne spreminjamo in nanjo ne vežemo.

| Iz Tatjane | Uporaba tu | Sprememba |
|---|---|---|
| `tools/core/Endpoint.php` (POST, HTTPS, `hash_equals`, omejitev telesa, enoten JSON) | middleware za `/agent` | skrivnost na lokacijo iz baze namesto ene `TOOL_SECRET` |
| `ToolResponse` / `ToolRegistry::call` (napaka -> enoten odgovor, log z `request_id`, trajanje) | ovoj kontrolerjev | |
| `AdapterInterface` (edina pot do podatkov) | repository sloj | dodan obvezen `TenantContext` |
| `Logger` (JSON Lines, maskiranje PII, hramba) | skoraj enak | ključi `guest_*`, `notes` |
| `RateLimiter` (datoteka + `flock`) | enak | |
| `ai/call-guard.php` (meje klicev na strežniku) | ostane v Tatjani; agent ga kliče kot zdaj | |
| `AppointmentTool` (prosto iz urnika, `spoken`, "zaseden med pogovorom" ni napaka) | vzorec za `preveri_razpolozljivost` | dodan zaklep in rezine; Tatjanin termin preveri dvakrat, a brez zaklepa |
| `OrderTool` (dva ujemajoča se podatka, enak odgovor ob neujemanju) | sprememba/odpoved s kodo + telefon | |
| `SlovenianDate` | izgovorjava datumov | |
| `.cpanel.yml` (rsync, `--chmod`, izvzemi) | enak vzorec | izvzem `tests/`, `docker/` |
| `scenarios-napadi.yaml` | oblika napadov | novi napadi za rezervacije |

Česa ne prenesemo: globalnih konstant iz `config.php` za poslovna pravila (tu so v bazi), PHP 7 sloga (netipizirane lastnosti), ene skupne skrivnosti za vse.

## Faze

| Faza | Vsebina | Končano, ko |
|---|---|---|
| 0 | specifikacija, shema, API, varnost, testi | Luka potrdi dokumente |
| 1 | jedro: router, repository, service, staff API brez UI | vsi testi zeleni, test sočasnosti dokaže 0 dvojnih zasedb |
| 2 | prijava, vloge, CSRF, PWA | PWA lokalno deluje na telefonu in tablici |
| 3 | orodja za Tatjano, predlog sprememb agenta, scenariji | agent rezervira prek API, napadi zavrnjeni |
| 4 | SMS, cron, admin | potrditve in opomniki tečejo |
| 5 | cPanel, varnostni pregled, pilot | pilotna restavracija |

## Odprto

Glej `TODO.md`, razdelek "Odprta vprašanja".
