'use strict';
/* Service worker za Tatjana Miza (PWA).

   Shrani samo lupino aplikacije: stran, CSS, JS, ikone in pisave. Tako se
   aplikacija odpre tudi brez omrežja. Podatkov (rezervacije, naročila) tu NE
   hranimo: api.php gre vedno mimo (POST), zadnje prebrane podatke za branje
   brez povezave hrani app.js in jih ob odjavi pobriše.

   Ob spremembi tega seznama ali logike povečaj RAZLICICA, da se stari
   predpomnilnik pobriše. CSS in JS imata različico v naslovu (?v=...), zanju ni
   treba ničesar spreminjati. */
const RAZLICICA = 'miza-v1';
const KOREN = new URL('./', self.location).pathname; // npr. /miza/
const LUPINA = ['./', 'manifest.json', 'assets/icons/icon-192.png', 'assets/icons/apple-touch-icon.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(RAZLICICA).then(c => c.addAll(LUPINA)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(k => Promise.all(k.filter(x => x !== RAZLICICA).map(x => caches.delete(x))))
      .then(() => self.clients.claim())
  );
});

/* Shrani odgovor in pobriše starejše različice iste datoteke (app.js?v=stara). */
async function shrani(req, r) {
  const c = await caches.open(RAZLICICA);
  const pot = new URL(req.url).pathname;
  for (const k of await c.keys()) {
    const u = new URL(k.url);
    if (u.pathname === pot && k.url !== req.url) await c.delete(k);
  }
  await c.put(req, r);
}

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return; // api.php (POST) in prijava gresta vedno na strežnik
  const url = new URL(req.url);
  const naseIzvor = url.origin === self.location.origin;
  const pisave = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';

  // Glavna stran: najprej omrežje (vedno najnovejša), brez omrežja shranjena.
  // Druge strani (users.php) gredo mimo.
  if (req.mode === 'navigate') {
    if (!naseIzvor || (url.pathname !== KOREN && url.pathname !== KOREN + 'index.php')) return;
    e.respondWith(
      fetch(req)
        .then(r => { if (r.ok) { const kopija = r.clone(); caches.open(RAZLICICA).then(c => c.put('./', kopija)); } return r; })
        .catch(async () => (await caches.match('./')) || Response.error())
    );
    return;
  }

  // CSS, JS, ikone in pisave: najprej predpomnilnik (naslov nosi različico),
  // sicer omrežje in shrani.
  const lupina = naseIzvor && (url.pathname.startsWith(KOREN + 'assets/') || url.pathname === KOREN + 'manifest.json');
  if (lupina || pisave) {
    e.respondWith(
      caches.match(req).then(hit => hit || fetch(req).then(r => {
        if (r.ok || r.type === 'opaque') e.waitUntil(shrani(req, r.clone()));
        return r;
      }))
    );
  }
});
