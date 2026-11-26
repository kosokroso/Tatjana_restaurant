# Načrt: naročila za prevzem po telefonu

Stanje 26. 11. 2026. Dostava ni naša naloga: gost vedno prevzame sam.

## Potek (kot ga je določil Luka)

1. Gost pokliče, Tatjana sprejme naročilo, v `/miza` se pojavi **Nova** (zvok + obvestilo).
2. Natakar naročilo preda kuharju in ga premakne v **V pripravi** (gumb "Predano kuhinji").
3. Kuhinja konča: **Opravljeno** (gumb "Opravljeno"). Naročilo ostane v tem stolpcu, dokler ga nekdo ne prevzame.
4. Gost pride: **Prevzeto** (gumb "Prevzeto"). Možen je tudi **Preklicano**.

V bazi so statusi `new`, `preparing`, `ready`, `picked_up`, `cancelled`. Spremenjene so bile samo oznake v vmesniku (`ready` = "Opravljeno"); shema se ne spreminja. Narejeno 26. 11.

## Kaj že obstaja (preverjeno v kodi, ne v pravem klicu)

- `/miza` API: `ai/menu`, `ai/orders/quote`, `ai/orders` (`api.php`, `lib/orders.php`).
- Cene in razpoložljivost jedi vzame strežnik iz baze (`resolve_order_items`), ne model. Zavrne: prazno naročilo, jed, ki je ni na meniju, jed, ki je izklopljena ali samo za v lokalu, manjkajočo obvezno izbiro, preveč izbir.
- Čas prevzema: strežnik preveri ure prevzema, čas priprave, termine kuhinje in največ naročil na termin (`suggest_pickup`); ob zavrnitvi vrne predlog.
- Osebje: kanban stolpci, zvok in obvestilo ob novem naročilu, ročno naročilo (`orders/create`), SMS ob "Opravljeno" (`queue_sms`, zdaj brez ponudnika, torej samo zapis).
- **Agent `tatjana-miza` teh orodij še ne uporablja.** Ima samo rezervacije in `meni`. Naročila po telefonu so torej še neizdelana.

## Faze

### Faza A: agent zna naročati (glavno delo)
Datoteke: `tatjana_ai/voice-agent/restavracija.py`, `miza_pravila.py`, `test_miza.py`.
- Orodja: `ponudba_prevzema` (pokliče `ai/orders/quote`, vrne ceno in najzgodnejši čas), `naroci` v **dveh korakih** kot rezervacija: povzetek z jedmi, izbirami, skupno ceno in uro prevzema, nato zapis šele po jasni gostovi potrditvi.
- Varovala v kodi, ne v pozivu (enako kot pri rezervacijah): `povzetek_naroceno_izgovorjen` preveri, da je asistentka res prebrala vsako jed in skupno ceno; `je_cista_potrditev` za potrditev; cena samo iz odgovora strežnika; telefon samo iz SIP ali izrecno izgovorjen in ponovljen.
- Alergeni: če gost omeni alergijo, orodje `meni` vrne alergene jedi, Tatjana jih prebere in ne zagotavlja ničesar ("preverite z osebjem"). Opomba o alergiji gre v `notes` naročila in je vidna na kartici.
- Poziv: Tatjana ne obljubi jedi, ki je ni v odgovoru `meni`; ne sprejme popustov; za nenavadne želje reče, naj pokličejo restavracijo.

### Faza B: zaščita pred zlorabo (pred javno številko)
Naročilo brez plačila je lahko šala ali spam. Predlog, po pomembnosti:
1. Zgornja meja: največ N jedi in največ X € na naročilo (nastavitev restavracije); nad mejo Tatjana odkloni.
2. Največ 2 odprti naročili na številko klicatelja hkrati (strežnik).
3. Skrita številka: naročilo samo, če gost izgovori številko in jo potrdi.
4. Osebje vidi vir ("Tatjana") in številko; gumb "Preklicano" ostane na kartici.
5. Preklic naročila po telefonu samo po kodi ali številki klicatelja (enako kot rezervacije).

### Faza C: izboljšave vmesnika za naročila (UX)
- Kartica pokaže, koliko minut je do prevzema in koliko je naročilo zamujeno (rdeče); že obstaja `late`, dodati čas.
- Stolpec "Opravljeno": števec in opozorilo, če naročilo čaka na prevzem več kot 20 min.
- Zvok je zdaj globalen (stikalo v tirnici); preveriti na tablici v kuhinji.
- Telefon: stolpci že drsijo vodoravno; preveriti na pravi napravi.
- Tisk ali zaslon za kuhinjo (samo "V pripravi", velika pisava) kot kasnejša ideja.

### Faza D: SMS
Potrditev naročila in "Opravljeno" kot SMS. Čaka na izbiro ponudnika SMS (odprto od faze 0). Do takrat Tatjana kodo in uro prevzema pove po telefonu.

## Testi
- `test_miza.py`: zavrnitev nepotrjenega naročila, spremenjene cene med korakoma, jedi, ki ni na meniju, naročila brez prebranega povzetka.
- Lokalno na SQLite: `create_order` z neveljavnimi jedmi in čas prevzema izven ur.
- Pravi klic: najprej en čisti potek, potem napadi (ukazi v imenu ali opombi, tuja številka, nesmiselna količina).

## Odločitve (Luka, 26. 11. 2026)
1. Tatjana **pove ceno** (skupni znesek je v povzetku, ki ga mora prebrati).
2. Plačilo: mogoče ob prevzemu ali vnaprej. Spletnega plačila ni, zato Tatjana plačila po telefonu ne sprejema in pove samo, da gost plača v restavraciji ob prevzemu; za drugo osebje.
3. **Zgornje meje naročila ni** (točka B1 odpade).
4. Stolpec "Prevzeto" se za pretekle dni skrije (za pretekle dni se pokaže samo število). Narejeno.

## Stanje faz (26. 11. 2026, lokalno, še ni deployano)
- **Faza A: narejeno v kodi.** Orodji `naroci` (dva koraka) in `stanje_narocila` v `restavracija.py`; `miza_pravila.py`: `povzetek_narocila`, `narocilo_izgovorjeno`, `cena_besedilo`; razlogi zavrnitve za naročila. Strežnik: `ai/menu` vrača izbire z id, nova pot `ai/orders/find` (po številki iz SIP ali kodi; vrne samo stanje, uro, jedi, znesek). Preverjeno: `python test_miza.py` (vse ok), lokalni klici API (ponudba, napake, zapis, iskanje po številki, skrita številka 409, tuja številka prazno). **Ni preverjeno po telefonu.**
- Faza B: odpade B1; ostalo (omejitev odprtih naročil na številko, skrita številka, preklic po kodi) še ni narejeno.
- Faza C in D: ni začeto.
- Preklic ali sprememba naročila prek Tatjane ni mogoča (pove, naj pokličejo restavracijo).

## Vrstni red deploya (pomembno)
1. cPanel: Update from Remote, Deploy HEAD Commit (`/miza`: nov `ai/menu` in `ai/orders/find`).
2. Šele nato agent (GitHub Actions `miza-agent.yml`, action `deploy`). Stari agent s starim `meni` je kompatibilen, novi agent s starim `/miza` ni (izbire brez id-jev).

## Ocene (ugibanje)
Faza A: približno en dan z lokalnimi testi in enim pravim klicem. Faza B: pol dneva. Faza C: pol dneva. Faza D: odvisna od izbire ponudnika.
