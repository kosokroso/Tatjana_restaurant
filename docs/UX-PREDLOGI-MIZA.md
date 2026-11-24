# Portal /miza: analiza in predlogi za osebje

Stanje 24. 11. 2026. Pregledani vsi zasloni na namizju (1400 px) in telefonu (375 px), lokalna kopija z enakimi podatki.

Izhodišče: kdo uporablja portal in kako.
- **Natakar / šank** med servisom: stoji, ima tablico ali telefon, ena roka prosta, 5 sekund časa. Potrebuje: kdo pride zdaj, katera miza je prosta, en dotik za "prišli".
- **Vodja izmene**: pred servisom pregleda dan, ureja rezervacije, odgovarja na klice, ki jih Tatjana ni rešila.
- **Lastnik**: tloris, meni, urnik, uporabniki. Redko.

Portal je zdaj narejen za tretjega (namizje, veliko podatkov naenkrat). Prva dva sta pozabljena.

## Že popravljeno (lokalno, čaka na deploy)

| # | Kaj | Zakaj |
|---|---|---|
| 1 | Vsaka miza na tlorisu pokaže svojo naslednjo rezervacijo dneva (`19:00 Luka · 2 +1`), oranžen rob spodaj; v uri pred prihodom črtkan rob "kmalu" | Prej je ob 12h vse kazalo "prosto", čeprav je bila zvečer rezervacija; natakar bi mizo oddal |
| 2 | Pregled dneva se osveži vsakih 20 s (samo ko je zavihek viden) | Rezervacija iz klica se je pokazala šele po ročnem osveževanju strani |
| 3 | Obvestilo "Nova rezervacija (Tatjana): sreda 25. 11. 20:00, Ana Novak, 4 os." na vseh zaslonih, za vse dni | Tatjana rezervira večinoma vnaprej; brez tega osebje ne ve, da je bila rezervacija sploh narejena |
| 4 | Pas "Ni povezave. Prikazano stanje je od HH:MM." ob izpadu omrežja | Zastarel tloris je nevarnejši od praznega |
| 5 | Gumb "Zdaj" ob drsniku časa | En dotik nazaj na trenutni čas |

## Predlogi

## Narejeno 24. 11. 2026 (zvečer): nova podoba + P0-1 + P0-3 + del P0-4

Lokalno preizkušeno na 1440, 1024 in 390 px; čaka na deploy.
- **Podoba:** nova zasnova "rezervacijska knjiga". IBM Plex Sans in Mono (časi in kode v monospace), ena barva znamke (globoka zelena), jantar za rezervirano, rdeča samo za zamudo. Brez emojijev (črtne SVG ikone), brez senc in zaobljenih kartic. Prosta miza je tiha (bela), zasedena glasna (polna zelena).
- **P0-1:** navpična tirnica na tablici, spodnja vrstica (Dan, Rezervacije, Naročila, Več) na telefonu, obrazci čez cel zaslon, plavajoči gumb "+" za novo rezervacijo, pod 1180 px zavihki Seznam / Mize / Časovnica, pod 700 px mreža miz namesto tlorisa, tabela rezervacij kot kartice.
- **P0-3:** seznam dneva po skupinah: Zamuja, Prihaja v 60 min, Za mizo, Kasneje, Zaključeno (zloženo). Statistika dneva v eni vrstici.
- **P0-4 (del):** rezervacija brez "Prišli" po 10 min dobi oznako "zamuja N min" in klic z enim dotikom. Predlog "Ni prišel" po 30 min še ni narejen.
- "Prekliči" ni več gumb v vrstici (je v oknu Uredi), da ga ni mogoče sprožiti z naključnim dotikom.

### Časovnica (24. 11. 2026, kasneje isti večer)
Čez celo širino strani, pod tlorisom in seznamom. Preklop **Dan / 3 dni / Teden** (zapomni se na napravi). Dnevi so v isti vodoravni osi, vsak z lastnim delovnim časom, zaprt dan je ozek. Imena miz, cone in dnevi so lepljivi pri drsenju. Ob odprtju drsi na "zdaj"; gumb "Zdaj". Glava dne kaže število rezervacij in gostov; klik nanjo odpre ta dan. Klik na prazno vrstico odpre novo rezervacijo z izpolnjenim dnem, uro (na 15 min) in mizo. Zamujajoče rezervacije imajo rdeč rob, opombe rdečo piko. Nepreizkušeno: več kot 7 dni, tisoče rezervacij (vsak klic `day` je ločen, teden = 7 klicev na 20 s).

### P0: pred pilotom (brez tega osebje portala ne bo uporabljalo)

1. **Telefon in tablica.** Obrazec "Nova rezervacija" se na telefonu odpre napol izven zaslona; meni zgoraj zasede tretjino zaslona; tabela rezervacij se ne prilega (vodoravno drsenje); tloris je pomanjšan do neberljivosti. Predlog: na zaslonih pod 900 px namesto tlorisa seznam **"Prihodi"** (velike vrstice, en dotik = Prišli), meni kot spodnja vrstica z 4 ikonami, obrazec čez cel zaslon.
2. **Zvok obvestil** se vklopi samo na strani Naročila. Predlog: stikalo v stranski vrstici, zapomni si izbiro na napravi; ob novi rezervaciji iz klica kratek zvok.
3. **"Kaj je zdaj"** na vrhu pregleda dneva: tri skupine *Prihaja (naslednjih 60 min)*, *Za mizo*, *Kasneje*. Danes je to en seznam po uri, kjer mora natakar sam iskati.
4. **Zamuda in neprihod.** 15 min po začetku brez "Prišli" rezervacija postane rdeča "zamuja 15 min" z gumbom za klic (`tel:`); po 30 min predlog "Ni prišel". Miza se sicer drži do konca termina in ostane prazna.
5. **Gost brez rezervacije (walk-in).** Gumb "Gost brez rezervacije": izbereš mizo in število oseb, ime ni obvezno, začetek = zdaj, status takoj "Za mizo". Danes je treba izpolniti cel obrazec z imenom.

### P1: vsakodnevno delo

6. **Dotik mize na tlorisu odpre majhen meni** (Prišli / Odšli / Ni prišel / Premakni / Nova rezervacija). Danes dotik izbere rezervacijo v seznamu na desni, akcija je drugje.
7. **Opombe vidne na mizi**: ikona za alergijo, otroški stol, rojstni dan. Danes so samo v seznamu.
8. **Iskanje v pregledu dneva** (ime, telefon, koda). Gost pride in reče "na ime Novak"; danes iskanje obstaja samo na strani Rezervacije.
9. **Oznaka "nova"** pri rezervacijah, ki jih je naredila Tatjana in jih nihče še ni odprl, ter števec na zavihku Rezervacije.
10. **"Vseeno shranim (dvojna zasedba)"** naj bo dovoljeno samo vodji in naj pusti sled; danes ga lahko potrdi kdorkoli z enim klikom.
11. **Časovnica** naj ima zamrznjen stolpec z imeni miz in samodejno pomakne na zdaj; na manjših zaslonih je danes ime mize izven vidnega dela.

### P2: upravljanje

12. **Vloge.** Danes je vsak uporabnik `admin` in lahko spreminja meni, urnik, tloris, API ključ in gesla drugih. Predlog: *osebje* (pregled, rezervacije, naročila) in *vodja* (vse).
13. **Prijava s PIN na tablici** (iz specifikacije faze 2): po prvi prijavi z geslom 4-mestni PIN na tej napravi; menjava izmene v 3 sekundah.
14. **PWA**: manifest in ikona, da se portal namesti na tablico kot aplikacija (celozaslonsko, brez naslovne vrstice brskalnika). Service worker samo za lupino; podatki vedno sveži.
15. **Zgodovina rezervacije**: kdo je kdaj kaj spremenil (osebje / Tatjana). Pomaga pri sporu z gostom.
16. **Tisk / PDF dnevnega seznama** za kuhinjo in za izpad interneta.
17. **Omejitev prijave** (5 napačnih poskusov = 15 min zaklepa); zdaj je neomejena.

## Predlagan vrstni red

1. Deploy že popravljenega (1–5 zgoraj): 10 min.
2. P0-1 telefon/tablica in P0-3 "Kaj je zdaj": največji vpliv na servis. Ocena: pol dneva.
3. P0-2, P0-4, P0-5: po uro do dve vsak. Ocena je ugibanje, dokler ne vidimo, kako kolega ureja kodo.
4. P1 po povratnih informacijah pilotne restavracije.
5. P2-12 vloge in P2-17 omejitev prijave pred prvo pravo stranko (varnost).

Ocene časa so groba ocena, ne zaveza.
