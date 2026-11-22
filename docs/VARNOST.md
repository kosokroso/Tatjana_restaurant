# Varnost

Stanje: model groženj v fazi 0. Vsaka grožnja ima zaščito in test. Kar nima testa, ni pokrito.

## Verzije programske opreme

| Komponenta | Izbira | Varnostna podpora do | Vir |
|---|---|---|---|
| PHP | 8.4 (minimum 8.3) | 8.3: 31. 12. 2027; 8.4: 31. 12. 2028; 8.5: 31. 12. 2029 | php.net/supported-versions.php, preverjeno 22. 11. 2026 |
| MySQL / MariaDB | odvisno od cPanela | preveri pred fazo 1 | |

Opombi:
- php.net navaja tudi novo vejo, izdano 19. 11. 2026 (podpora do 31. 12. 2030). Ob izdaji je cPanel običajno še ne ponuja; prehod nanjo je smiseln po prvih popravkih.
- Kaj ponuja cPanel ("Select PHP Version"), **ni preverjeno**: tega iz lokalnega računalnika ne vidim. Luka preveri in vpiše sem.

Odgovornost: nadgradnjo PHP opravi Luka v cPanelu najkasneje 3 mesece pred koncem varnostne podpore. Pred nadgradnjo teste požene lokalno na novi verziji. Opomnik v `TODO.md`.

## Model groženj

### 1. Izolacija tenantov
Grožnja: restavracija A vidi ali spremeni podatke B (napaka v kodi, podtaknjen ID).
Zaščita:
- `tenant_id` pride samo iz avtentikacije (`TenantContext`), nikoli iz zahtevka.
- Repository ga doda v vsak SQL; ni metode brez `TenantContext`.
- Sestavljeni tuji ključi `(tenant_id, id)`: baza zavrne mešanje.
- Tuj ID vrne 404, enako kot neobstoječ.
Test: integracijski test ustvari tenanta A in B, nato za vsak endpoint poskusi z ID-jem B pod sejo/ključem A (branje, sprememba, status, mize, admin). Pričakovano 404 in nespremenjena baza. Seznam endpointov se bere iz routerja, da nov endpoint brez testa pade.

### 2. Prevzem seje
Zaščita: ID seje 256 bitov, v bazi hash; piškotek `HttpOnly; Secure; SameSite=Lax; Path=/`; nov ID ob prijavi; absolutni potek (12 h, izmena) in neaktivnost (2 h); odjava briše v bazi; zaklep računa izbriše vse seje. CSRF žeton na sejo v glavi `X-CSRF-Token`. CSP brez `unsafe-inline`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, HSTS.
Test: CSRF manjka -> 403; stara seja po odjavi -> 401; ID seje se ob prijavi spremeni; glave prisotne.

### 3. Brute force (geslo, PIN)
Zaščita: omejitev po IP in po e-pošti, zaklep z naraščajočim časom, enak odgovor za neobstoječ račun in napačno geslo, `password_verify` tudi za neobstoječ račun (enak čas). PIN velja samo z žetonom naprave; 5 napak preklice napravo.
Test: 6. napačna prijava -> zaklep; prava prijava med zaklepom zavrnjena; PIN brez piškotka naprave zavrnjen.

### 4. Napad prek govora na AI (Tatjana)
Grožnja: klicatelj prepriča model v dvojno rezervacijo, preseganje kapacitete, rezervacijo pri tuji restavraciji, razkritje tujih rezervacij, "obljubo" mize, ki je ni.
Zaščita:
- Model nima SQL in nima tenant_id. Ključ je vezan na lokacijo.
- Vsa pravila v PHP (razpoložljivost, kapaciteta, urnik, okno vnaprej).
- Zapis samo z žetonom iz faze `predlog`, vezanim na ista polja.
- Sprememba in odpoved zahtevata kodo IN telefon.
- Odgovori orodij vsebujejo samo, kar model sme povedati.
Test: `scenarios-restavracija.yaml` z napadi (glej TESTNI-NACRT.md) + PHP testi, ki pokličejo orodja z zlonamernim vhodom neposredno.

### 5. Uhajanje skrivnosti
Zaščita: `config.php` ni v gitu (`.gitignore` + izvzem v `.cpanel.yml`); API skrivnosti v bazi samo kot hash, prikazane enkrat ob ustvarjanju; logi ne vsebujejo glav `X-Api-Secret`, piškotkov, gesel; `tests/` in `docker/` se ne deployata. Repo bo verjetno javen (kot Tatjana): ne sme vsebovati poti hosting računa ali domen strank.
Test: skripta `tests/check-repo.php` išče vzorce ključev, BOM in `config.php` v drevesu; teče pred vsakim commitom.

### 6. GDPR
Podatki: ime, telefon, e-pošta gosta, opombe (lahko zdravstveni podatek: alergija).
- Restavracija je upravljavec, Luka/Kreativni Splet obdelovalec. Pogodba o obdelavi pred pilotom (ni naloga kode, je v TODO).
- Hramba: kontakt se anonimizira 90 dni po rezervaciji (cron; nastavljivo na tenanta). Vrstica ostane za statistiko.
- Pravica do izbrisa: manager v adminu anonimizira gosta po telefonu.
- SMS ponudnik je podobdelovalec; izbere se ponudnik v EU ali z ustrezno pogodbo.
- Logi maskirani, 14 dni.
Test: cron anonimizacije na testnih podatkih; po njej v bazi ni imena, telefona, e-pošte, opomb.

### 7. Drugo
- SQL injection: samo pripravljeni stavki PDO, `ATTR_EMULATE_PREPARES=false`. Imena stolpcev za razvrščanje iz belega seznama.
- XSS v PWA: samo `textContent`, nikoli `innerHTML` s podatki; CSP.
- Odpoved prek SMS povezave: GET ne spreminja stanja.
- SMS zloraba (pošiljanje na tuje številke prek lažnih rezervacij): meja agent API na dan, SMS samo za rezervacije, največ en SMS na tip na rezervacijo.
- Izpad: če API vrne 5xx ali ne odgovori v 5 s, agent klic preveže na `locations.phone_public`.
- Varnostna kopija: dnevni `mysqldump` prek crona izven `public_html`, 14 dni hrambe, test obnove pred pilotom.
- Odvisnosti: nobenih tretjih knjižnic v v1. Nova odvisnost zahteva utemeljitev in Lukovo potrditev.
