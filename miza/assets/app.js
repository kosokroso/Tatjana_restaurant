'use strict';
/* Tatjana Miza · admin aplikacija (brez ogrodij) */

const S = { me: null, view: 'pregled', date: today(), minute: null, day: null, sel: null, selTable: null, tab: 'list', doneOpen: false, tlDays: 1, tl: null, tlFresh: true };
try { S.tlDays = [1, 3, 7].includes(+localStorage.getItem('tm_tl')) ? +localStorage.getItem('tm_tl') : 1; } catch { }
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = c => (c / 100).toLocaleString('sl-SI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
const DAYS = ['', 'Ponedeljek', 'Torek', 'Sreda', 'Četrtek', 'Petek', 'Sobota', 'Nedelja'];
const ST_RES = { confirmed: 'Potrjena', seated: 'Za mizo', completed: 'Zaključena', no_show: 'Ni prišel', cancelled: 'Preklicana' };
const ST_ORD = { new: 'Nova', preparing: 'V pripravi', ready: 'Opravljeno', picked_up: 'Prevzeto', cancelled: 'Preklicano' };
const SRC = { tatjana: 'Tatjana', manual: 'Ročno', web: 'Splet' };

function today() { const d = new Date(); return ymd(d); }
function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function addDays(s, n) { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); }
function toMin(hm) { const [h, m] = hm.split(':').map(Number); return h * 60 + m; }
function fromMin(m) { m = Math.max(0, m); return String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
function timeOf(dt) { return dt.slice(11, 16); }
function minOf(dt, baseDate) { let m = toMin(timeOf(dt)); if (dt.slice(0, 10) > baseDate) m += 1440; return m; }
function nowMin() { const d = new Date(); return d.getHours() * 60 + d.getMinutes(); }
function dateLabel(s) { const d = new Date(s + 'T12:00:00'); return DAYS[d.getDay() || 7] + ', ' + d.getDate() + '. ' + (d.getMonth() + 1) + '.'; }

/* ---------------- brez povezave (PWA) ----------------
   Brez omrežja aplikacija samo bere: pokaže zadnje podatke, ki jih je ta naprava
   dobila od strežnika, in jasno pove, od kdaj so. Spreminjanje ni mogoče, ker bi
   Tatjana medtem isto mizo lahko oddala po telefonu. Shranjeni so samo odgovori
   za branje (ne nastavitve z API ključem); ob odjavi se pobrišejo. */
const BRANJE = new Set(['auth/me', 'day', 'tables', 'reservations/list', 'menu', 'orders']);
const OFF = 'tm_off:', OFF_MAX = 60;
const offKey = (route, data) => OFF + route + ':' + JSON.stringify(data);
function offKeys() { try { return Object.keys(localStorage).filter(k => k.startsWith(OFF)); } catch { return []; } }
function offLoad(route, data) { try { return JSON.parse(localStorage.getItem(offKey(route, data)) || 'null'); } catch { return null; } }
function offClear() { offKeys().forEach(k => { try { localStorage.removeItem(k); } catch { } }); }
function offTrim(keep) {
  const ks = offKeys(); if (ks.length <= keep) return;
  ks.map(k => { let t = 0; try { t = JSON.parse(localStorage.getItem(k)).t || 0; } catch { } return [k, t]; })
    .sort((a, b) => a[1] - b[1]).slice(0, ks.length - keep).forEach(([k]) => { try { localStorage.removeItem(k); } catch { } });
}
function offSave(route, data, j) {
  const v = JSON.stringify({ t: Date.now(), j });
  try { localStorage.setItem(offKey(route, data), v); offTrim(OFF_MAX); }
  catch { offTrim(Math.floor(OFF_MAX / 2)); try { localStorage.setItem(offKey(route, data), v); } catch { } }
}
/* Pas na vrhu zaslona. t = čas prikazanih podatkov (ms), true = čas ni znan, null = povezava je. */
function setOffline(t) {
  S.offlineAt = t;
  document.body.classList.toggle('offline', !!t);
  let b = $('#offbar');
  if (!t) { if (b) b.remove(); return; }
  if (!b) { b = document.createElement('div'); b.id = 'offbar'; b.setAttribute('role', 'status'); document.body.prepend(b); }
  let kdaj = '';
  if (typeof t === 'number') { const d = new Date(t); kdaj = ' Prikazani podatki so od ' + (ymd(d) === today() ? '' : d.getDate() + '. ' + (d.getMonth() + 1) + '. ') + d.toTimeString().slice(0, 5) + '.'; }
  b.textContent = 'Brez povezave.' + kdaj + ' Spreminjanje ni mogoče, dokler se povezava ne vrne.';
  document.documentElement.style.setProperty('--offh', b.offsetHeight + 'px');
}
function brezPovezave(route, data) {
  const c = BRANJE.has(route) && offLoad(route, data);
  if (c) { setOffline(c.t); return Object.assign(c.j, { _iz: c.t }); }
  if (!S.offlineAt) setOffline(true);
  throw new Error(BRANJE.has(route) ? 'Brez povezave. Za ta pogled na tej napravi ni shranjenih podatkov.' : 'Brez povezave. Spreminjanje ni mogoče, dokler se povezava ne vrne.');
}

async function api(route, data = {}) {
  let r;
  try {
    // Brez časovne meje bi aplikacija ob slabem signalu čakala minuto, preden pokaže shranjeno.
    const ctl = new AbortController(), tm = setTimeout(() => ctl.abort(), 10000);
    r = await fetch('api.php?r=' + route, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-TM': '1' }, body: JSON.stringify(data), credentials: 'same-origin', signal: ctl.signal });
    clearTimeout(tm);
  } catch { return brezPovezave(route, data); }
  if (r.status >= 502 && r.status <= 504) return brezPovezave(route, data); // strežnik ali posrednik ne dosegljiv
  let j; try { j = await r.json(); } catch { j = { ok: false, message: 'Napaka strežnika' }; }
  if (r.status === 401 && route !== 'auth/login') { offClear(); S.me = null; renderLogin(); throw new Error('unauth'); }
  if (S.offlineAt) setOffline(null);
  if (!j.ok) { const e = new Error(j.message || 'Napaka'); e.data = j; throw e; }
  if (BRANJE.has(route)) offSave(route, data, j);
  return j;
}
function toast(msg, err = false) {
  const t = document.createElement('div'); t.className = 'toast' + (err ? ' err' : ''); t.textContent = msg;
  document.body.appendChild(t); setTimeout(() => t.remove(), 3200);
}
/* Ikone: črtne, 24 px mreža, brez emojijev (na vsaki napravi izgledajo drugače). */
const ICONS = {
  pregled: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  rezervacije: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
  narocila: '<path d="M6 8h12l1 12H5z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
  tloris: '<rect x="3" y="3" width="8" height="8" rx="1.5"/><circle cx="17.5" cy="6.5" r="3.5"/><rect x="3" y="14" width="8" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  meni: '<path d="M7 3v7M4 3v4a3 3 0 0 0 6 0V3M7 10v11"/><path d="M17 21V3c-2.6 1.1-4 4-4 8h4"/>',
  nastavitve: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
  vec: '<path d="M5 12h.01M12 12h.01M19 12h.01" stroke-width="3"/>',
  odjava: '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
  levo: '<path d="M15 6l-6 6 6 6"/>', desno: '<path d="M9 6l6 6-6 6"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>', plus: '<path d="M12 5v14M5 12h14"/>',
  opomba: '<path d="M5 4h10l4 4v12H5z"/><path d="M9 13h6M9 17h4"/>',
  telefon: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
  zvok: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
  zvokOff: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4M4 4l16 16"/>',
};
const ic = (n, cls = '') => `<svg class="ic ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[n] || ''}</svg>`;
/* Znak: miza z štirimi stoli. */
const LOGO = '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="16" cy="16" r="6.5"/><path d="M16 3.5v3M16 25.5v3M3.5 16h3M25.5 16h3"/></svg>';

function modal(title, body, foot = '') {
  const bg = document.createElement('div'); bg.className = 'modal-bg';
  bg.innerHTML = `<div class="modal" role="dialog" aria-label="${esc(title)}"><div class="mh"><h2>${esc(title)}</h2><button class="ib ghost" data-x aria-label="Zapri">${ic('x')}</button></div><div class="mb">${body}</div>${foot ? `<div class="mf">${foot}</div>` : ''}</div>`;
  document.body.appendChild(bg);
  const onKey = e => { if (e.key === 'Escape') close(); };
  const close = () => { document.removeEventListener('keydown', onKey); bg.remove(); };
  document.addEventListener('keydown', onKey);
  bg.addEventListener('mousedown', e => { if (e.target === bg) close(); });
  $('[data-x]', bg).onclick = close;
  return { el: bg, close };
}
function debounce(fn, ms = 300) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

/* ---------------- prijava in ogrodje ---------------- */
async function boot() {
  let odjava = false; try { odjava = localStorage.getItem('tm_odjava') === '1'; } catch { }
  if (odjava) {
    // 401 pomeni, da seje na strežniku tako ali tako ni več: zastavica ni več potrebna.
    try { await api('auth/logout'); localStorage.removeItem('tm_odjava'); }
    catch (e) { if (e.message === 'unauth') try { localStorage.removeItem('tm_odjava'); } catch { } }
    return renderLogin();
  }
  try {
    const me = await api('auth/me'); S.me = me;
    window.removeEventListener('hashchange', route); window.addEventListener('hashchange', route); route();
    if (!me._iz) setTimeout(predpomni, 3000);
  }
  catch (e) { if (e.message !== 'unauth') renderLogin(); }
}
/* Ob zagonu z omrežjem naloži današnji dan, naročila, meni in mize, da ima
   naprava za branje brez povezave vse glavne poglede, ne samo tistega, ki je
   bil odprt. Rezervacije za naslednji teden pridejo zraven. */
async function predpomni() {
  const d = today();
  // Telesa morajo biti enaka kot v pogledih (ključ je pot + telo).
  const dnevi = [0, 1, 2, 3, 4, 5, 6].map(n => ['day', { date: addDays(d, n) }]); // časovnica do 7 dni
  for (const [r, b] of [['orders', { date: d }], ...dnevi, ['menu', {}], ['tables', {}], ['reservations/list', { from: d, to: addDays(d, 30), q: '' }]]) {
    if (offLoad(r, b) && Date.now() - offLoad(r, b).t < 60000) continue;
    try { await api(r, b); } catch { return; }
  }
}
function renderLogin() {
  stopPolling();
  $('#app').innerHTML = `<div class="auth-wrap"><form class="auth" id="lf">
    <div class="logo">${LOGO}</div>
    <h1>Tatjana Miza</h1><p class="muted">Rezervacije in prevzemi z AI asistentko</p>
    <div id="lerr"></div>
    <label>E-naslov<input name="email" type="email" required autofocus autocomplete="username"></label>
    <label>Geslo<input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn primary">Prijava</button></form></div>`;
  $('#lf').onsubmit = async e => {
    e.preventDefault();
    try { await api('auth/login', Object.fromEntries(new FormData(e.target))); try { localStorage.removeItem('tm_odjava'); } catch { } boot(); }
    catch (err) { $('#lerr').innerHTML = `<div class="alert">${esc(err.message)}</div>`; }
  };
}
/* [ključ, kratka oznaka, polno ime, v spodnji vrstici na telefonu] */
const NAV = [
  ['pregled', 'Dan', 'Pregled dneva', 1], ['rezervacije', 'Rezervacije', 'Rezervacije', 1], ['narocila', 'Naročila', 'Naročila za prevzem', 1],
  ['tloris', 'Tloris', 'Tloris miz', 0], ['meni', 'Meni', 'Meni', 0], ['nastavitve', 'Nastavitve', 'Nastavitve', 0],
];
async function doLogout() {
  // Shranjene podatke pobrišemo tudi, če strežnik ni dosegljiv: po odjavi na napravi ne sme ostati nič.
  // Sejo na strežniku pa zapremo ob prvi povezavi (zastavica), sicer bi bil po vrnitvi omrežja spet prijavljen.
  try { await api('auth/logout'); localStorage.removeItem('tm_odjava'); } catch { try { localStorage.setItem('tm_odjava', '1'); } catch { } }
  offClear(); S.me = null; location.hash = ''; renderLogin();
}
function shell() {
  if (!$('.shell')) {
    $('#app').innerHTML = `<div class="shell"><nav class="rail" aria-label="Glavna navigacija"><div class="brand" title="${esc(S.me.restaurant.name)}">${LOGO}</div>
      ${NAV.map(([k, t, , main]) => `<a class="ni ${main ? '' : 'alt'}" href="#${k}" data-nav="${k}">${ic(k)}<span>${t}</span>${k === 'narocila' ? '<span class="badge" id="obadge" hidden></span>' : ''}</a>`).join('')}
      <button class="ni more" id="morebtn" aria-label="Več">${ic('vec')}<span>Več</span></button>
      <div class="rail-foot"><button class="ni" id="sndbtn" aria-label="Zvok obvestil"></button><button class="ni" id="logout" title="${esc(S.me.user.email)}">${ic('odjava')}<span>Odjava</span></button></div>
    </nav>
    <div class="more-sheet" id="more" hidden>${NAV.filter(n => !n[3]).map(([k, , t]) => `<a href="#${k}" data-nav="${k}">${ic(k)}${t}</a>`).join('')}
      <button id="sndbtn2"></button>
      <button id="logout2">${ic('odjava')}Odjava<small>${esc(S.me.user.email)}</small></button></div>
    <main class="main" id="main"></main></div>`;
    $('#logout').onclick = doLogout; $('#logout2').onclick = doLogout;
    $('#sndbtn').onclick = () => setSound(!soundOn()); $('#sndbtn2').onclick = () => setSound(!soundOn());
    document.addEventListener('pointerdown', () => { if (soundOn()) initAudio(); });
    drawSoundBtn();
    $('#morebtn').onclick = e => { e.stopPropagation(); $('#more').hidden = !$('#more').hidden; };
    document.addEventListener('click', e => { const m = $('#more'); if (m && !m.hidden && !e.target.closest('#more')) m.hidden = true; });
    document.addEventListener('keydown', e => { const m = $('#more'); if (e.key === 'Escape' && m) m.hidden = true; });
    window.addEventListener('resize', debounce(() => { if (S.view === 'pregled' && S.day && $('#plan')) drawPlan(); }, 150));
  }
  $$('[data-nav]').forEach(a => a.classList.toggle('on', a.dataset.nav === S.view));
  $('#morebtn')?.classList.toggle('on', !NAV.find(n => n[0] === S.view)[3]);
  const mo = $('#more'); if (mo) mo.hidden = true;
  return $('#main');
}
function route() {
  S.view = (location.hash.slice(1) || 'pregled');
  if (!NAV.find(n => n[0] === S.view)) S.view = 'pregled';
  if (S.view !== 'narocila') stopPolling();
  shell();
  const pogled = ({ pregled: viewDay, rezervacije: viewList, narocila: viewOrders, tloris: viewEditor, meni: viewMenu, nastavitve: viewSettings })[S.view];
  Promise.resolve().then(pogled).catch(e => {
    // Brez povezave pogled brez shranjenih podatkov (npr. Nastavitve) pove, zakaj je prazen.
    if (e.message === 'unauth') return;
    const m = $('#main'); if (m) m.innerHTML = `<div class="card pad stack"><h3>${esc(NAV.find(n => n[0] === S.view)[2])}</h3><div class="alert">${esc(e.message)}</div></div>`;
  });
  startBadgePoll();
}

/* ---------------- tloris (skupni izris) ---------------- */
function planSize(tables) {
  let w = 900, h = 520;
  tables.forEach(t => { w = Math.max(w, +t.x + +t.w + 80); h = Math.max(h, +t.y + +t.h + 80); });
  return [w, h];
}
function tableState(t, minute) {
  if (!S.day) return { cls: '', sub: '' };
  const rs = S.day.reservations.filter(r => ['confirmed', 'seated'].includes(r.status) && r.tables.some(x => +x.id === +t.id));
  const cur = rs.find(r => minOf(r.start_at, S.day.date) <= minute && minute < minOf(r.end_at, S.day.date));
  if (cur) return { cls: cur.status === 'seated' ? 'seated' : 'res', sub: `${esc(cur.name)} · ${cur.party_size}`, res: cur };
  // Prej je tloris pokazal naslednjo rezervacijo samo, če je bila v 2 urah, in
  // osebje je ob 12h videlo vse mize "proste", čeprav je bila zvečer polna sala.
  // Zdaj vsaka miza pove svojo naslednjo rezervacijo dneva; v uri pred njo je
  // obarvana kot "kmalu", da je natakar ne odda gostu brez rezervacije.
  const ahead = rs.filter(r => minOf(r.start_at, S.day.date) > minute).sort((a, b) => a.start_at.localeCompare(b.start_at));
  const next = ahead[0];
  if (!next) return { cls: '', sub: '' };
  const inMin = minOf(next.start_at, S.day.date) - minute;
  const more = ahead.length > 1 ? ` +${ahead.length - 1}` : '';
  return { cls: inMin <= 60 ? 'soon' : 'later', sub: `${timeOf(next.start_at)} ${esc(next.name.split(' ')[0])} · ${next.party_size}${more}`, next };
}
function tableHTML(t, extra = '', sub = '') {
  return `<div class="tbl ${t.shape === 'round' ? 'round' : ''} ${+t.reservable ? '' : 'off'} ${extra}" data-tid="${t.id ?? t._k}"
    style="left:${t.x}px;top:${t.y}px;width:${t.w}px;height:${t.h}px"><b>${esc(t.name)}</b><span class="sub">${sub || (t.min_seats + ' do ' + t.max_seats + ' oseb')}</span><span class="rz"></span></div>`;
}

/* ---------------- pregled dneva ---------------- */
async function viewDay() {
  const m = shell();
  m.innerHTML = `<div class="phead"><div class="grow"><div class="over">${esc(S.me.restaurant.name)}</div>
      <div class="dnav"><button class="ib" id="dprev" aria-label="Prejšnji dan">${ic('levo')}</button><h1 class="ptitle">${dateLabel(S.date)}</h1><button class="ib" id="dnext" aria-label="Naslednji dan">${ic('desno')}</button></div></div>
    <div class="dctl"><input type="date" id="ddate" value="${S.date}" aria-label="Datum"><button class="btn" id="dtoday">Danes</button>
    ${S.date === today() ? '<button class="btn" id="walkin">Gost brez rezervacije</button>' : ''}<button class="btn primary fab" id="newres" aria-label="Nova rezervacija">${ic('plus')}<span class="lbl">Nova rezervacija</span></button></div></div>
    <div class="statline" id="stat"></div>
    <div class="alert" id="stale" hidden></div>
    <div class="seg" id="dtabs" role="tablist"><button data-tab="list">Seznam</button><button data-tab="plan">Mize</button><button data-tab="time">Časovnica</button></div>
    <div class="day" data-tab="${S.tab}"><div class="dtop"><div class="dcol">
      <section class="panel plan-wrap pane pane-plan"><div id="plan"></div>
        <div class="slider"><span class="muted small">Čas</span><input type="range" id="tslider" step="15" aria-label="Čas na tlorisu"><b id="tlabel"></b>${S.date === today() ? '<button class="btn sm" id="tnow">Zdaj</button>' : ''}</div>
        <div class="legend"><span><i></i>Prosto</span><span><i class="s-soon"></i>Kmalu rezervirano</span><span><i class="s-res"></i>Rezervirano</span><span><i class="s-seated"></i>Gostje za mizo</span><span><i class="s-off"></i>Ni za rezervacije</span></div>
      </section>
      </div>
      <section class="panel list pane pane-list" id="rlist"></section></div>
      <section class="panel tl pane pane-time" id="timeline"><div class="tlbar"><h3>Časovnica miz</h3><span class="muted small" id="tlrange"></span><span class="grow"></span>
        <span class="rseg" id="tlseg"><button data-r="1">Dan</button><button data-r="3">3 dni</button><button data-r="7">Teden</button></span><button class="btn sm" id="tlnow">Zdaj</button></div>
        <div class="tlscroll" id="tlscroll"></div></section></div>`;
  $('#ddate').onchange = e => { if (!e.target.value) return; S.date = e.target.value; S.minute = null; viewDay(); };
  $('#dprev').onclick = () => { S.date = addDays(S.date, -1); S.minute = null; viewDay(); };
  $('#dnext').onclick = () => { S.date = addDays(S.date, 1); S.minute = null; viewDay(); };
  $('#dtoday').onclick = () => { S.date = today(); S.minute = null; viewDay(); };
  $('#newres').onclick = () => resModal({ date: S.date, time: S.minute != null ? fromMin(S.minute) : '19:00' });
  if ($('#walkin')) $('#walkin').onclick = walkinModal;
  $$('#dtabs button').forEach(b => b.onclick = () => { S.tab = b.dataset.tab; syncTabs(); if (S.tab === 'plan' && S.day) drawPlan(); if (S.tab === 'time') drawTimeline(); });
  $$('#tlseg button').forEach(b => b.onclick = () => { S.tlDays = +b.dataset.r; S.tlFresh = true; try { localStorage.setItem('tm_tl', S.tlDays); } catch { } loadTimeline(); });
  $('#tlnow').onclick = () => { const sc = $('#tlscroll'); if (sc && S.tlNowX != null) sc.scrollTo({ left: Math.max(0, S.tlNowX - sc.clientWidth * .3), behavior: 'smooth' }); };
  S.tlFresh = true;
  syncTabs();
  // Tloris se prilagodi širini, ko se spremeni (drsnik strani, vrtenje tablice, zavihek).
  if (window.ResizeObserver) {
    let lastW = 0;
    new ResizeObserver(es => { const w = Math.round(es[0].contentRect.width); if (w && w !== lastW) { lastW = w; if (S.day && S.view === 'pregled') drawPlan(); } }).observe($('#plan'));
    let lastT = 0;
    new ResizeObserver(es => { const w = Math.round(es[0].contentRect.width); if (w && w !== lastT) { lastT = w; if (S.tl && S.view === 'pregled') drawTimeline(); } }).observe($('#tlscroll'));
  }
  await loadDay();
}
/* Gost brez rezervacije: miza + število oseb, začetek je zdaj, status takoj "Za mizo".
   Zasedene mize so onemogočene; mize z rezervacijo kasneje kažejo, do kdaj so proste. */
function walkinModal() {
  if (!S.day || S.day.date !== today()) return toast('Gostov brez rezervacije vpišeš samo za danes.', true);
  const nm = nowMin();
  const md = modal('Gost brez rezervacije', `
    <div class="grid2"><label>Število oseb<input type="number" name="party" min="1" value="2"></label><label>Ime (neobvezno)<input name="name" autocomplete="off"></label></div>
    <div><div class="small muted" style="margin-bottom:6px">Izberi mizo</div><div class="wgrid" id="wgrid"></div></div>
    <div class="avail" id="wavail">Izberi mizo.</div>`,
    `<button class="btn" data-x2>Zapri</button><button class="btn primary" data-save disabled>Posadi goste</button>`);
  let pick = null;
  const party = () => Math.max(1, +$('[name=party]', md.el).value || 1);
  const draw = () => {
    const p = party();
    const info = S.day.tables.map(t => { const st = tableState(t, nm); return { t, st, busy: ['res', 'seated'].includes(st.cls), fits: p <= +t.max_seats }; });
    const best = info.filter(i => !i.busy && i.fits && i.st.cls !== 'soon').sort((a, b) => a.t.max_seats - b.t.max_seats)[0]
      || info.filter(i => !i.busy && i.fits).sort((a, b) => a.t.max_seats - b.t.max_seats)[0];
    info.sort((a, b) => (a.busy - b.busy) || (!a.fits - !b.fits) || String(a.t.name).localeCompare(String(b.t.name), 'sl', { numeric: true }));
    $('#wgrid', md.el).innerHTML = info.map(({ t, st, busy, fits }) => {
      const sub = busy ? 'Zasedeno' : st.next ? `Prosta do ${timeOf(st.next.start_at)}` : 'Prosta';
      return `<button class="chip ${pick === t.id ? 'sel' : ''} ${busy ? 'off' : st.cls === 'soon' ? 'soon' : ''}" data-t="${t.id}" ${busy ? 'disabled' : ''}><b>${esc(t.name)}</b><span class="sub">${sub}</span><i>${best && best.t === t ? 'predlog · ' : ''}${t.min_seats}–${t.max_seats} oseb${fits ? '' : ' (premajhna)'}</i></button>`;
    }).join('');
    $$('#wgrid [data-t]', md.el).forEach(b => b.onclick = () => { pick = +b.dataset.t; draw(); });
    const t = S.day.tables.find(x => x.id === pick), st = t && tableState(t, nm);
    const box = $('#wavail', md.el);
    if (!t) { box.className = 'avail'; box.textContent = 'Izberi mizo.'; }
    else if (p > +t.max_seats) { box.className = 'avail no'; box.textContent = `Miza ${t.name} ima največ ${t.max_seats} mest, gostov je ${p}.`; }
    else if (st.next) { box.className = 'avail'; box.textContent = `Miza ${t.name}: rezervacija ${timeOf(st.next.start_at)} (${st.next.name}). Gostje morajo oditi do takrat.`; }
    else { box.className = 'avail yes'; box.textContent = `Miza ${t.name} je prosta.`; }
    $('[data-save]', md.el).disabled = !t;
  };
  $('[name=party]', md.el).addEventListener('input', draw);
  draw();
  $('[data-x2]', md.el).onclick = md.close;
  $('[data-save]', md.el).onclick = async () => {
    if (!pick) return;
    const btn = $('[data-save]', md.el); btn.disabled = true;
    try {
      const r = await api('reservations/create', { date: today(), time: fromMin(nowMin()), party_size: party(), name: $('[name=name]', md.el).value.trim() || 'Gost brez rezervacije', notes: 'Brez rezervacije', table_ids: [pick] });
      await api('reservations/status', { id: r.reservation.id, status: 'seated' });
      const t = S.day.tables.find(x => x.id === pick);
      toast(`Gostje za mizo ${t ? t.name : ''}`); md.close(); refreshCurrent();
    } catch (e) { btn.disabled = false; toast(e.message, true); }
  };
}
function syncTabs() {
  $('.day')?.setAttribute('data-tab', S.tab);
  $$('#dtabs button').forEach(b => b.classList.toggle('on', b.dataset.tab === S.tab));
}
async function loadDay() {
  S.day = await api('day', { date: S.date });
  S.dayAt = S.day._iz ? new Date(S.day._iz) : new Date();
  markSeen(S.day.reservations);
  startDayPoll();
  const hrs = S.day.hours.length ? S.day.hours : [['12:00', '22:00']];
  const lo = toMin(hrs[0][0]), hi = Math.max(...hrs.map(h => { let c = toMin(h[1]); if (c <= toMin(h[0])) c += 1440; return c; }));
  S.range = [lo, hi];
  if (S.minute == null) S.minute = S.date === today() ? Math.min(hi, Math.max(lo, Math.round(nowMin() / 15) * 15)) : Math.min(hi, lo + 7 * 60);
  const sl = $('#tslider'); if (!sl) return;
  sl.min = lo; sl.max = hi; sl.value = S.minute;
  sl.oninput = e => { S.minute = +e.target.value; drawPlan(); };
  const tn = $('#tnow');
  if (tn) tn.onclick = () => { S.minute = Math.min(hi, Math.max(lo, Math.round(nowMin() / 15) * 15)); sl.value = S.minute; drawPlan(); };
  drawPlan(); drawList(); loadTimeline();
}
function drawPlan() {
  const tables = S.day.tables, [w, h] = planSize(tables);
  $('#tlabel').textContent = fromMin(S.minute);
  const highlight = S.sel ? S.day.reservations.find(r => r.id === S.sel)?.tables.map(t => +t.id) || [] : [];
  // Tloris se prilagodi širini zaslona (najmanj 55 %), da ni vodoravnega drsenja.
  const avail = $('#plan').clientWidth, s = avail > 0 ? Math.max(.55, Math.min(1, avail / w)) : 1;
  const grid = [...tables].sort((a, b) => String(a.name).localeCompare(String(b.name), 'sl', { numeric: true }));
  $('#plan').innerHTML = `<div class="plan-fit" style="width:${Math.round(w * s)}px;height:${Math.round(h * s)}px"><div class="plan" style="width:${w}px;height:${h}px;transform:scale(${s})">${tables.map(t => {
    const st = tableState(t, S.minute);
    return tableHTML(t, st.cls + (highlight.includes(+t.id) ? ' sel' : ''), st.sub);
  }).join('')}</div></div>
    <div class="tgrid">${grid.map(t => {
      const st = tableState(t, S.minute), off = !+t.reservable;
      return `<button class="chip ${off ? 'off' : st.cls} ${highlight.includes(+t.id) ? 'sel' : ''}" data-tid="${t.id}"><b>${esc(t.name)}</b><span class="sub">${off ? 'Ni za rezervacije' : st.sub || 'Prosto'}</span><i>${t.min_seats}–${t.max_seats} oseb</i></button>`;
    }).join('')}</div>`;
  $$('#plan [data-tid]').forEach(el => el.onclick = () => tableTap(el.dataset.tid));
}
function tableTap(tid) {
  const t = S.day.tables.find(x => +x.id === +tid);
  const st = tableState(t, S.minute), r = st.res || st.next;
  if (r) {
    S.sel = r.id;
    if (S.tab !== 'list' && matchMedia('(max-width:1180px)').matches) { S.tab = 'list'; syncTabs(); }
    drawPlan(); drawList(); scrollToRes(r.id);
  } else resModal({ date: S.date, time: fromMin(S.minute), party_size: Math.min(2, t.max_seats), table_ids: [t.id], zone_pref: t.zone });
}
/* Samodejno osveževanje pregleda dneva.
   Rezervacijo, ki jo Tatjana naredi med klicem, mora osebje videti brez
   ponovnega nalaganja strani. Vsakih 20 s, samo ko je zavihek viden. */
let dayT = null;
const seenRes = new Set();
function markSeen(rs, announce = false) {
  rs.forEach(r => {
    if (!seenRes.has(r.id) && announce && r.source === 'tatjana' && r.status === 'confirmed') {
      toast(`Nova rezervacija (Tatjana): ${dateLabel(r.start_at.slice(0, 10))} ${timeOf(r.start_at)}, ${r.name}, ${r.party_size} os.`);
      beep();
    }
    seenRes.add(r.id);
  });
}
function startDayPoll() {
  if (dayT) return;
  dayT = setInterval(async () => {
    if (S.view !== 'pregled') { clearInterval(dayT); dayT = null; return; }
    if (document.hidden) return;
    try {
      const d = await api('day', { date: S.date });
      if (S.view !== 'pregled' || d.date !== S.date) return;
      // Obvestilo da globalni števec (reservations/new); tu samo osvežimo.
      markSeen(d.reservations);
      S.day = d; S.dayAt = d._iz ? new Date(d._iz) : new Date();
      drawPlan(); drawList(); loadTimeline(); drawStale(!!d._iz);
    } catch (e) { if (e.message !== 'unauth') drawStale(true); }
  }, 20000);
}
function drawStale(stale) {
  const el = $('#stale'); if (!el) return;
  el.hidden = !stale || !!S.offlineAt; // brez povezave to pove pas na vrhu (#offbar)
  if (stale && S.dayAt) el.textContent = `Ni povezave. Prikazano stanje je od ${S.dayAt.toTimeString().slice(0, 5)}.`;
}
function scrollToRes(id) { const el = $(`.ritem[data-rid="${id}"]`); el && el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
/* Časovnica: vrstice so mize, vodoravna os je čas. Za več dni so dnevi v isti osi
   (vsak dan ima svoj delovni čas, zaprt dan je ozek). Imena miz in dnevi so lepljivi,
   da se ob drsenju vedno vidi, katera miza in kateri dan je to. */
const TL = { NAME: 78, ROW: 44, CLOSED: 84 };
const LIVE = d => d.reservations.filter(r => !['cancelled', 'no_show'].includes(r.status));
function dayRange(d) {
  let lo = null, hi = null;
  (d.hours || []).forEach(([o, c]) => { const a = toMin(o); let b = toMin(c); if (b <= a) b += 1440; lo = lo == null ? a : Math.min(lo, a); hi = hi == null ? b : Math.max(hi, b); });
  LIVE(d).forEach(r => { const a = minOf(r.start_at, d.date), b = minOf(r.end_at, d.date); lo = lo == null ? a : Math.min(lo, a); hi = hi == null ? b : Math.max(hi, b); });
  return lo == null ? null : [Math.floor(lo / 60) * 60, Math.ceil(hi / 60) * 60];
}
function sortedTables(ts) {
  const zs = (S.me.settings.zones || []), zi = z => { const i = zs.indexOf(z); return i < 0 ? 99 : i; };
  return [...ts].sort((a, b) => zi(a.zone) - zi(b.zone) || String(a.name).localeCompare(String(b.name), 'sl', { numeric: true }));
}
async function loadTimeline() {
  const n = S.tlDays, dates = Array.from({ length: n }, (_, i) => addDays(S.date, i));
  try {
    const days = await Promise.all(dates.map(dt => S.day && S.day.date === dt ? S.day : api('day', { date: dt })));
    if (dates[0] !== S.date || n !== S.tlDays || S.view !== 'pregled') return;
    S.tl = days; drawTimeline();
  } catch (e) { if (e.message !== 'unauth') drawStale(true); }
}
function drawTimeline() {
  const sc = $('#tlscroll'); if (!sc || !S.tl || !S.day) return;
  const { NAME, CLOSED } = TL, days = S.tl, n = days.length, isToday = d => d.date === today();
  const ranges = days.map(dayRange);
  const hoursTotal = ranges.reduce((s, r) => s + (r ? (r[1] - r[0]) / 60 : 0), 0) || 1;
  const closed = ranges.filter(r => !r).length;
  const avail = (sc.clientWidth || 720) - NAME - 2;
  const pph = Math.max({ 1: 56, 3: 48, 7: 40 }[n] || 40, (avail - closed * CLOSED) / hoursTotal);   // px na uro
  const ppm = pph / 60, nm = nowMin(), step = pph >= 48 ? 1 : 2;
  let x = 0;
  const geo = ranges.map(r => { const w = r ? (r[1] - r[0]) * ppm : CLOSED; const g = { x, w }; x += w; return g; });
  const total = Math.round(x);

  const lbl = $('#tlrange');
  if (lbl) lbl.textContent = n === 1 ? dateLabel(days[0].date) : `${dateLabel(days[0].date)} do ${dateLabel(days[n - 1].date)}`;
  $$('#tlseg button').forEach(b => b.classList.toggle('on', +b.dataset.r === n));
  const tn = $('#tlnow'); if (tn) tn.hidden = !days.some(isToday);

  const head = days.map((d, i) => {
    const g = geo[i], r0 = ranges[i], dt = new Date(d.date + 'T12:00:00');
    const act = d.reservations.filter(r => ['confirmed', 'seated'].includes(r.status));
    const gu = act.reduce((s, r) => s + r.party_size, 0);
    let ticks = '';
    if (r0) for (let m = r0[0]; m < r0[1]; m += 60 * step) ticks += `<span style="left:${(m - r0[0]) * ppm}px">${fromMin(m)}</span>`;
    return `<div class="tld${isToday(d) ? ' today' : ''}" data-date="${d.date}" style="width:${g.w}px" title="Odpri ${esc(dateLabel(d.date))}"><div class="tld-h"><b>${DAYS[dt.getDay() || 7].slice(0, 3)} ${dt.getDate()}. ${dt.getMonth() + 1}.</b><span>${r0 ? `${act.length} rez · ${gu} os.` : 'zaprto'}</span></div><div class="tld-t">${ticks}</div></div>`;
  }).join('');

  let gl = '';
  days.forEach((d, i) => {
    const g = geo[i], r0 = ranges[i];
    gl += `<i class="day" style="left:${g.x}px"></i>`;
    if (!r0) { gl += `<i class="closed" style="left:${g.x}px;width:${g.w}px"></i>`; return; }
    for (let m = r0[0] + 60; m < r0[1]; m += 60) gl += `<i style="left:${g.x + (m - r0[0]) * ppm}px"></i>`;
    if (isToday(d) && nm >= r0[0] && nm <= r0[1]) gl += `<i class="now" style="left:${g.x + (nm - r0[0]) * ppm}px"></i>`;
  });

  const tables = sortedTables(S.day.tables), zones = new Set(tables.map(t => t.zone || ''));
  let rows = '', lastZone = null;
  tables.forEach(t => {
    if (zones.size > 1 && (t.zone || '') !== lastZone) { lastZone = t.zone || ''; rows += `<div class="tlz" style="width:${NAME + total}px"><span>${esc(lastZone || 'Brez cone')}</span></div>`; }
    const blocks = days.map((d, i) => {
      const g = geo[i], r0 = ranges[i]; if (!r0) return '';
      return LIVE(d).filter(r => r.tables.some(y => +y.id === +t.id)).map(r => {
        const a = Math.max(r0[0], minOf(r.start_at, d.date)), b = Math.min(r0[1], minOf(r.end_at, d.date));
        const w = Math.max(16, (b - a) * ppm - 2);
        const late = isToday(d) && r.status === 'confirmed' && nm - minOf(r.start_at, d.date) > 10;
        const txt = w >= 120 ? `<b>${timeOf(r.start_at)}</b> ${esc(r.name)} · ${r.party_size}` : w >= 56 ? `${esc(r.name.split(' ')[0])} · ${r.party_size}` : `${r.party_size}`;
        const tip = `${timeOf(r.start_at)} ${r.name}, ${r.party_size} os.${r.notes ? ' · ' + r.notes : ''}`;
        return `<div class="blk ${r.status}${late ? ' late' : ''}${S.sel === r.id ? ' sel' : ''}" data-rid="${r.id}" data-d="${i}" style="left:${g.x + (a - r0[0]) * ppm}px;width:${w}px" title="${esc(tip)}">${txt}${r.notes ? '<i class="nd"></i>' : ''}</div>`;
      }).join('');
    }).join('');
    rows += `<div class="tlr"><div class="tln"><b>${esc(t.name)}</b><small>${t.min_seats}–${t.max_seats}</small></div><div class="tll" data-tid="${t.id}" style="width:${total}px">${blocks}</div></div>`;
  });

  const keepL = sc.scrollLeft, keepT = sc.scrollTop;
  sc.innerHTML = `<div class="tlin" style="width:${NAME + total}px"><div class="tlhead"><div class="tlcorner"></div>${head}</div><div class="tlbody"><div class="tlgl" style="width:${total}px">${gl}</div>${rows}</div></div>`;
  const ti = days.findIndex(isToday), r0 = ti >= 0 ? ranges[ti] : null;
  if (S.tlFresh && sc.clientWidth) {
    // Prvi izris: drsnik na "zdaj" (če je danes v obdobju), sicer na začetek.
    sc.scrollLeft = r0 && nm >= r0[0] && nm <= r0[1] ? Math.max(0, geo[ti].x + (nm - r0[0]) * ppm - sc.clientWidth * .3) : 0;
    S.tlFresh = false;
  } else { sc.scrollLeft = keepL; sc.scrollTop = keepT; }
  S.tlNowX = r0 && nm >= r0[0] && nm <= r0[1] ? geo[ti].x + (nm - r0[0]) * ppm : null;

  $$('#tlscroll .blk').forEach(b => b.onclick = e => { e.stopPropagation(); resModal(days[+b.dataset.d].reservations.find(y => y.id === +b.dataset.rid)); });
  $$('#tlscroll .tll').forEach(l => l.onclick = e => {
    const t = S.day.tables.find(y => +y.id === +l.dataset.tid), px = e.clientX - l.getBoundingClientRect().left;
    const i = geo.findIndex((g, k) => ranges[k] && px >= g.x && px < g.x + g.w); if (i < 0) return;
    const m = Math.round((ranges[i][0] + (px - geo[i].x) / ppm) / 15) * 15;
    resModal({ date: days[i].date, time: fromMin(m % 1440), party_size: Math.min(2, t.max_seats), table_ids: [t.id], zone_pref: t.zone });
  });
  $$('#tlscroll .tld').forEach(h => h.onclick = () => { S.date = h.dataset.date; S.minute = null; S.tlFresh = true; if (matchMedia('(max-width:1180px)').matches) S.tab = 'list'; viewDay(); });
}

/* Seznam rezervacij je razdeljen po tem, kaj mora natakar narediti zdaj (danes):
   zamuja, prihaja v 60 min, za mizo, kasneje; zaključeno je zloženo. */
const DONE = ['completed', 'no_show', 'cancelled'];
function resItem(r, nm = null) {
  const acts = [];
  const late0 = nm != null && r.status === 'confirmed' ? nm - minOf(r.start_at, S.date) : 0;
  // Po 30 min brez prihoda je glavni gumb "Ni prišel" (rdeč); miza se sicer drži do konca termina.
  if (r.status === 'confirmed') acts.push(['seated', 'Prišli', late0 >= 30 ? 0 : 1], ['no_show', 'Ni prišel', late0 >= 30 ? 2 : 0]);
  if (r.status === 'seated') acts.push(['completed', 'Odšli', 1]);
  if (['confirmed', 'seated'].includes(r.status)) acts.push(['edit', 'Uredi']); // preklic je v oknu Uredi, da ga ni mogoče sprožiti z naključnim dotikom
  if (DONE.includes(r.status)) acts.push(['confirmed', 'Obnovi']);
  const late = nm != null && r.status === 'confirmed' ? nm - minOf(r.start_at, S.date) : 0;
  const tel = r.phone ? String(r.phone).replace(/[^\d+]/g, '') : '';
  return `<div class="ritem ${r.status} ${late > 10 ? 'late' : ''} ${S.sel === r.id ? 'sel' : ''}" data-rid="${r.id}">
    <div class="rtime">${timeOf(r.start_at)}</div>
    <div class="rbody">
      <div class="rline"><span class="rname">${esc(r.name)}</span><span class="rparty">${r.party_size} os.</span>${r.tables.length ? `<span class="tchip">${r.tables.map(t => esc(t.name)).join(' + ')}</span>` : ''}</div>
      <div class="meta">${late > 10 ? `<span class="late-tag">zamuja ${late} min${late >= 30 ? ' · predlog: Ni prišel' : ''}</span>` : ''}${DONE.includes(r.status) ? `<span class="pill ${r.status}">${ST_RES[r.status]}</span>` : ''}${r.source === 'tatjana' ? '<span class="pill tatjana">Tatjana</span>' : ''}${r.phone ? `<a href="tel:${esc(tel)}">${ic('telefon')} ${esc(r.phone)}</a>` : ''}</div>
      ${r.notes ? `<div class="meta note">${ic('opomba')}<span>${esc(r.notes)}</span></div>` : ''}
      <div class="acts">${acts.map(([k, l, p]) => `<button class="btn sm ${p === 1 ? 'primary' : p === 2 ? 'warn' : ''}" data-act="${k}">${l}</button>`).join('')}</div>
    </div></div>`;
}
function drawList() {
  const rs = [...S.day.reservations].sort((a, b) => a.start_at.localeCompare(b.start_at));
  const active = rs.filter(r => ['confirmed', 'seated'].includes(r.status));
  const guests = active.reduce((s, r) => s + r.party_size, 0);
  const ords = S.day.orders.filter(o => o.status !== 'cancelled');
  const isToday = S.date === today(), nm = isToday ? nowMin() : null;
  const g = { late: [], soon: [], seated: [], later: [], done: [] };
  rs.forEach(r => {
    if (DONE.includes(r.status)) return g.done.push(r);
    if (r.status === 'seated') return g.seated.push(r);
    if (!isToday) return g.later.push(r);
    const d = minOf(r.start_at, S.date) - nm;
    (d < -10 ? g.late : d <= 60 ? g.soon : g.later).push(r);
  });
  const sec = (t, n, cls = '') => `<div class="sec ${cls}"><span>${t}</span><span class="n">${n}</span></div>`;
  const rows = l => l.map(r => resItem(r, nm)).join('');
  let h = '';
  if (isToday) {
    if (g.late.length) h += sec('Zamuja', g.late.length, 'bad') + rows(g.late);
    h += sec('Prihaja v 60 min', g.soon.length) + (g.soon.length ? rows(g.soon) : '<div class="quiet">Naslednjih 60 minut ni prihodov.</div>');
  }
  if (g.seated.length) h += sec('Za mizo', g.seated.length) + rows(g.seated);
  if (g.later.length) h += sec(isToday ? 'Kasneje' : 'Rezervacije', g.later.length) + rows(g.later);
  if (!active.length && !g.done.length) h = '<div class="empty">Ni rezervacij za ta dan.</div>';
  if (g.done.length) h += `<details class="done" ${S.doneOpen ? 'open' : ''}><summary class="sec"><span>Zaključeno in preklicano</span><span class="n">${g.done.length}</span></summary>${rows(g.done)}</details>`;
  if (ords.length) h += sec('Prevzemi', ords.length) + ords.map(o => `<div class="ritem" onclick="location.hash='narocila'"><div class="rtime">${timeOf(o.pickup_at)}</div><div class="rbody"><div class="rline"><span class="rname">${esc(o.name)}</span><span class="rparty">#${esc(o.code)}</span><span class="pill" style="margin-left:auto">${ST_ORD[o.status]}</span></div><div class="meta">${o.items.map(i => i.qty + '× ' + esc(i.name)).join(', ')}</div></div></div>`).join('');
  $('#rlist').innerHTML = h;
  $('#stat').innerHTML = `<span><b>${active.length}</b> rezervacij</span><span><b>${guests}</b> gostov</span>${isToday ? `<span><b>${g.seated.length}</b> za mizo</span>` : ''}${ords.length ? `<span><b>${ords.length}</b> prevzemov</span>` : ''}`;
  $('#rlist details.done')?.addEventListener('toggle', e => { S.doneOpen = e.target.open; });
  $$('#rlist .ritem[data-rid]').forEach(el => {
    const r = rs.find(x => x.id === +el.dataset.rid);
    el.onclick = e => {
      const btn = e.target.closest('[data-act]');
      if (e.target.closest('a')) return;
      if (!btn) { S.sel = S.sel === r.id ? null : r.id; if (S.sel) S.minute = Math.max(S.range[0], minOf(r.start_at, S.date)); $('#tslider').value = S.minute; drawPlan(); drawList(); return; }
      e.stopPropagation();
      const act = btn.dataset.act;
      if (act === 'edit') return resModal(r);
      if (act === 'cancelled' && !confirm(`Prekličem rezervacijo ${r.name}?`)) return;
      setResStatus(r.id, act);
    };
  });
}
async function setResStatus(id, status) {
  try { await api('reservations/status', { id, status }); toast(ST_RES[status]); refreshCurrent(); }
  catch (e) { toast(e.message, true); }
}
function refreshCurrent() { if (S.view === 'pregled') loadDay(); else if (S.view === 'rezervacije') viewList(); }

/* ---------------- modal rezervacije ---------------- */
function resModal(r = {}) {
  const isEdit = !!r.id;
  const v = {
    date: r.start_at ? r.start_at.slice(0, 10) : (r.date || S.date),
    time: r.start_at ? timeOf(r.start_at) : (r.time || '19:00'),
    party_size: r.party_size || 2, name: r.name || '', phone: r.phone || '', email: r.email || '', notes: r.notes || '',
    zone_pref: r.zone_pref || '', table_ids: r.table_ids || (isEdit ? [] : []),
  };
  const tables = (S.day && S.day.tables) || S.tablesCache || [];
  const zones = S.me.settings.zones || [];
  const dur = isEdit ? Math.round((new Date(r.end_at.replace(' ', 'T')) - new Date(r.start_at.replace(' ', 'T'))) / 60000) : '';
  const curTables = isEdit ? r.tables.map(t => +t.id) : v.table_ids.map(Number);
  const md = modal(isEdit ? 'Uredi rezervacijo' : 'Nova rezervacija', `
    <div class="grid3"><label>Datum<input type="date" name="date" value="${v.date}"></label><label>Ura<input type="time" name="time" step="900" value="${v.time}"></label><label>Osebe<input type="number" name="party_size" min="1" value="${v.party_size}"></label></div>
    <div class="grid2"><label>Ime<input name="name" value="${esc(v.name)}" required></label><label>Telefon<input name="phone" value="${esc(v.phone)}"></label></div>
    <div class="grid3"><label>Cona<select name="zone_pref"><option value="">Vseeno</option>${zones.map(z => `<option ${z === v.zone_pref ? 'selected' : ''}>${esc(z)}</option>`).join('')}</select></label>
      <label>Miza<select name="table" ${tables.length ? '' : 'disabled'}><option value="">Samodejno</option>${tables.map(t => `<option value="${t.id}" ${curTables.length === 1 && curTables[0] === +t.id ? 'selected' : ''}>${esc(t.name)} (${t.min_seats} do ${t.max_seats})</option>`).join('')}${curTables.length > 1 ? `<option value="keep" selected>${r.tables.map(t => esc(t.name)).join(' + ')}</option>` : ''}</select></label>
      <label>Trajanje (min)<input type="number" name="duration_minutes" min="15" step="15" value="${dur}" placeholder="privzeto"></label></div>
    <label>E-pošta<input name="email" type="email" value="${esc(v.email)}"></label>
    <label>Opombe (alergije, rojstni dan, voziček…)<textarea name="notes" rows="2">${esc(v.notes)}</textarea></label>
    <div class="avail" id="avail">Preverjam…</div>`,
    `${isEdit ? '<button class="btn danger" data-del style="margin-right:auto">Prekliči rezervacijo</button>' : ''}<button class="btn" data-x2>Zapri</button><button class="btn primary" data-save>${isEdit ? 'Shrani' : 'Rezerviraj'}</button>`);
  const f = n => $(`[name=${n}]`, md.el);
  const data = () => {
    const d = { date: f('date').value, time: f('time').value, party_size: +f('party_size').value, name: f('name').value.trim(), phone: f('phone').value.trim(), email: f('email').value.trim(), notes: f('notes').value.trim(), zone_pref: f('zone_pref').value || null };
    const tv = f('table').value;
    if (tv === 'keep') d.table_ids = curTables; else if (tv) d.table_ids = [+tv];
    if (f('duration_minutes').value) d.duration_minutes = +f('duration_minutes').value;
    return d;
  };
  const check = debounce(async () => {
    const d = data(); const box = $('#avail', md.el);
    if (!d.date || !d.time || !d.party_size) return;
    if (d.table_ids) {
      const t = tables.filter(x => d.table_ids.includes(+x.id));
      const cap = t.reduce((s, x) => s + +x.max_seats, 0);
      box.className = 'avail' + (cap < d.party_size ? ' no' : '');
      box.textContent = cap < d.party_size ? `Izbrana miza ima samo ${cap} mest.` : `Ročno izbrana miza ${t.map(x => x.name).join(' + ')}. Zasedenost se preveri ob shranjevanju.`;
      return;
    }
    try {
      const a = await api('availability', { date: d.date, time: d.time, party_size: d.party_size, zone: d.zone_pref, exclude_id: r.id });
      box.className = 'avail ' + (a.available ? 'yes' : 'no');
      box.textContent = a.available ? `Prosto: miza ${a.tables.map(t => t.name).join(' + ')} · ${a.duration_minutes} min` : a.message;
    } catch (e) { box.className = 'avail no'; box.textContent = e.message; }
  });
  $$('input,select', md.el).forEach(i => i.addEventListener('input', check)); check();
  $('[data-x2]', md.el).onclick = md.close;
  if (isEdit) $('[data-del]', md.el).onclick = async () => { if (confirm('Prekličem rezervacijo?')) { await setResStatus(r.id, 'cancelled'); md.close(); } };
  const save = async (force = false) => {
    const d = data(); if (!d.name) return toast('Vpiši ime.', true);
    if (force) d.force = 1;
    try {
      if (isEdit) await api('reservations/update', { id: r.id, ...d }); else await api('reservations/create', d);
      toast(isEdit ? 'Shranjeno' : 'Rezervacija ustvarjena'); md.close();
      if (S.view === 'pregled' && (d.date < S.date || d.date > addDays(S.date, S.tlDays - 1))) { S.date = d.date; S.minute = toMin(d.time); viewDay(); } else refreshCurrent();
    } catch (e) {
      if (e.data?.error === 'table_busy' && confirm(e.message + ' Vseeno shranim (dvojna zasedba)?')) return save(true);
      const alts = e.data?.alternatives?.length ? ' Prosto: ' + e.data.alternatives.map(a => (a.date !== d.date ? a.date + ' ' : '') + a.time).join(', ') : '';
      toast(e.message + alts, true);
    }
  };
  $('[data-save]', md.el).onclick = () => save();
}

/* ---------------- seznam rezervacij ---------------- */
async function viewList() {
  const m = shell();
  S.lf = S.lf || { from: today(), to: addDays(today(), 30), q: '' };
  m.innerHTML = `<div class="top"><h2 class="grow">Rezervacije</h2>
    <label>Od<input type="date" id="lfrom" value="${S.lf.from}"></label><label>Do<input type="date" id="lto" value="${S.lf.to}"></label>
    <label>Iskanje<input id="lq" placeholder="ime, telefon, koda" value="${esc(S.lf.q)}"></label>
    <button class="btn primary fab" id="newres" style="align-self:flex-end" aria-label="Nova rezervacija">${ic('plus')}<span class="lbl">Nova</span></button></div><div class="card" id="ltbl"><div class="empty">Nalagam…</div></div>`;
  const load = async () => {
    const j = await api('reservations/list', S.lf);
    const rs = j.reservations;
    $('#ltbl').innerHTML = rs.length ? `<table class="simple resp"><thead><tr><th>Datum</th><th>Ura</th><th>Ime</th><th>Osebe</th><th>Miza</th><th>Telefon</th><th>Vir</th><th>Status</th><th>Koda</th></tr></thead><tbody>
      ${rs.map(r => `<tr data-rid="${r.id}"><td class="c-date">${dateLabel(r.start_at.slice(0, 10))}</td><td class="c-time">${timeOf(r.start_at)}</td><td class="c-name">${esc(r.name)}${r.notes ? ` <span title="${esc(r.notes)}" style="color:var(--muted)">${ic('opomba')}</span>` : ''}</td><td class="c-party">${r.party_size}<span class="muted small"> os.</span></td><td class="c-table">${r.tables.map(t => esc(t.name)).join(' + ')}</td><td class="c-phone">${esc(r.phone || '')}</td><td class="c-src"><span class="pill ${r.source}">${SRC[r.source] || r.source}</span></td><td class="c-status"><span class="pill ${r.status}">${ST_RES[r.status]}</span></td><td class="c-code muted">${esc(r.code)}</td></tr>`).join('')}</tbody></table>`
      : '<div class="empty">Ni rezervacij v tem obdobju.</div>';
    $$('#ltbl tr[data-rid]').forEach(tr => tr.onclick = async () => { await ensureTables(); resModal(rs.find(r => r.id === +tr.dataset.rid)); });
  };
  const upd = debounce(() => { S.lf = { from: $('#lfrom').value, to: $('#lto').value, q: $('#lq').value.trim() }; load(); });
  ['#lfrom', '#lto', '#lq'].forEach(s => $(s).addEventListener('input', upd));
  $('#newres').onclick = async () => { await ensureTables(); resModal({ date: today() }); };
  load();
}
async function ensureTables() { if (!S.tablesCache) S.tablesCache = (await api('tables')).tables; S.day = null; }

/* ---------------- urejevalnik tlorisa ---------------- */
async function viewEditor() {
  const m = shell();
  const j = await api('tables');
  S.ed = { tables: j.tables.map(t => ({ ...t })), sel: null, dirty: false, k: 1 };
  m.innerHTML = `<div class="top"><h2 class="grow">Tloris miz</h2><span class="muted small" id="dirty"></span><button class="btn primary" id="esave">Shrani tloris</button></div>
    <div class="editor"><div class="card plan-wrap"><div class="toolbar">
      <button class="btn sm" data-add="square">+ Kvadratna miza</button><button class="btn sm" data-add="round">+ Okrogla miza</button><button class="btn sm" data-add="rect">+ Podolgovata miza</button>
      <span class="muted small" style="align-self:center">Povleci mizo za premik, kvadratek v kotu za velikost.</span></div><div id="eplan"></div></div>
    <div class="card pad" id="eprops"></div></div>`;
  $$('[data-add]').forEach(b => b.onclick = () => {
    const shape = b.dataset.add, n = S.ed.tables.length + 1;
    const t = { _k: 'n' + (S.ed.k++), name: String(n), min_seats: shape === 'rect' ? 4 : 1, max_seats: shape === 'rect' ? 6 : shape === 'round' ? 2 : 4, zone: S.me.settings.zones[0] || '', shape, x: 40, y: 40, w: shape === 'rect' ? 160 : shape === 'round' ? 70 : 90, h: shape === 'rect' ? 80 : shape === 'round' ? 70 : 90, combine_group: '', reservable: 1, features: '', priority: 0 };
    S.ed.tables.push(t); S.ed.sel = key(t); markDirty(); drawEditor();
  });
  $('#esave').onclick = saveEditor;
  window.onbeforeunload = () => S.ed?.dirty ? 'Neshranjene spremembe' : undefined;
  drawEditor();
}
const key = t => String(t.id ?? t._k);
function markDirty() { S.ed.dirty = true; $('#dirty').textContent = 'Neshranjene spremembe'; }
function drawEditor() {
  const [w, h] = planSize(S.ed.tables);
  $('#eplan').innerHTML = `<div class="plan edit" style="width:${w}px;height:${h}px">${S.ed.tables.map(t => tableHTML(t, key(t) === S.ed.sel ? 'sel' : '', (t.zone ? esc(t.zone) + ' · ' : '') + t.min_seats + ' do ' + t.max_seats)).join('')}</div>`;
  const plan = $('#eplan .plan');
  $$('.tbl', plan).forEach(el => {
    el.onpointerdown = e => {
      const t = S.ed.tables.find(x => key(x) === el.dataset.tid);
      const resize = e.target.classList.contains('rz');
      if (S.ed.sel !== key(t)) { S.ed.sel = key(t); drawEditor(); drawProps(); const nel = $(`#eplan .tbl[data-tid="${key(t)}"]`); return startDrag(nel, t, e, resize); }
      startDrag(el, t, e, resize);
    };
  });
  plan.onpointerdown = e => { if (e.target === plan) { S.ed.sel = null; drawEditor(); } };
  drawProps();
}
function startDrag(el, t, e, resize) {
  e.preventDefault();
  const sx = e.clientX, sy = e.clientY, ox = +t.x, oy = +t.y, ow = +t.w, oh = +t.h, snap = v => Math.round(v / 10) * 10;
  let moved = false;
  const mv = ev => {
    const dx = ev.clientX - sx, dy = ev.clientY - sy;
    if (Math.abs(dx) + Math.abs(dy) > 2) moved = true;
    if (resize) { t.w = Math.max(40, snap(ow + dx)); t.h = Math.max(40, snap(oh + dy)); if (t.shape === 'round') t.h = t.w; el.style.width = t.w + 'px'; el.style.height = t.h + 'px'; }
    else { t.x = Math.max(0, snap(ox + dx)); t.y = Math.max(0, snap(oy + dy)); el.style.left = t.x + 'px'; el.style.top = t.y + 'px'; }
  };
  const up = () => { document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); if (moved) { markDirty(); drawEditor(); } };
  document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
}
function drawProps() {
  const box = $('#eprops'); if (!box) return;
  const t = S.ed.tables.find(x => key(x) === S.ed.sel);
  const total = S.ed.tables.reduce((s, x) => s + +x.max_seats, 0);
  if (!t) { box.innerHTML = `<h3>Lastnosti mize</h3><p class="muted">Izberi mizo na tlorisu ali dodaj novo.</p><p class="small muted">${S.ed.tables.length} miz · skupaj ${total} sedežev</p>
    <p class="small muted"><b>Skupina združevanja</b>: mize z isto oznako (npr. A) lahko sistem združi za večjo skupino.</p>`; return; }
  const zones = S.me.settings.zones || [];
  box.innerHTML = `<div class="stack"><h3>Miza ${esc(t.name)}</h3>
    <label>Ime ali številka<input data-p="name" value="${esc(t.name)}"></label>
    <div class="grid2"><label>Najmanj oseb<input type="number" min="1" data-p="min_seats" value="${t.min_seats}"></label><label>Največ oseb<input type="number" min="1" data-p="max_seats" value="${t.max_seats}"></label></div>
    <label>Cona<select data-p="zone"><option value="">(brez)</option>${zones.map(z => `<option ${z === t.zone ? 'selected' : ''}>${esc(z)}</option>`).join('')}</select></label>
    <label>Oblika<select data-p="shape">${[['square', 'Kvadratna'], ['round', 'Okrogla'], ['rect', 'Podolgovata']].map(([k, l]) => `<option value="${k}" ${k === t.shape ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
    <label>Skupina združevanja<input data-p="combine_group" value="${esc(t.combine_group || '')}" placeholder="npr. A"></label>
    <label>Posebnosti<input data-p="features" value="${esc(t.features || '')}" placeholder="ob oknu, separe, dostop z vozičkom"></label>
    <label>Prednost pri dodelitvi<input type="number" data-p="priority" value="${t.priority || 0}"></label>
    <label class="check"><input type="checkbox" data-p="reservable" ${+t.reservable ? 'checked' : ''}> Na voljo za rezervacije (Tatjana)</label>
    <button class="btn danger" id="edel">Odstrani mizo</button></div>`;
  $$('[data-p]', box).forEach(i => i.oninput = () => {
    const p = i.dataset.p; t[p] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.type === 'number' ? +i.value : i.value;
    if (p === 'shape' && t.shape === 'round') t.h = t.w;
    markDirty(); const sel = S.ed.sel; drawEditorPlanOnly(sel);
  });
  $('#edel').onclick = () => { S.ed.tables = S.ed.tables.filter(x => x !== t); S.ed.sel = null; markDirty(); drawEditor(); };
}
function drawEditorPlanOnly() {
  // osveži samo tloris, da polje v lastnostih ne izgubi fokusa
  const props = $('#eprops'), keep = props.innerHTML;
  const f = document.activeElement?.dataset?.p;
  drawEditor();
  if (f) { const el = $(`[data-p="${f}"]`, props); if (el) { el.focus(); if (el.setSelectionRange && el.type === 'text') el.setSelectionRange(el.value.length, el.value.length); } }
}
async function saveEditor() {
  const bad = S.ed.tables.find(t => +t.min_seats > +t.max_seats);
  if (bad) return toast(`Miza ${bad.name}: najmanj oseb je več kot največ.`, true);
  try {
    const j = await api('tables/save', { tables: S.ed.tables });
    S.ed.tables = j.tables; S.ed.dirty = false; S.ed.sel = null; S.tablesCache = null; $('#dirty').textContent = ''; drawEditor(); toast('Tloris shranjen');
  } catch (e) { toast(e.message, true); }
}

/* ---------------- naročila ---------------- */
let pollT = null, badgeT = null;
function stopPolling() { clearInterval(pollT); pollT = null; window.onbeforeunload = null; }
function startBadgePoll() {
  if (badgeT) return;
  const tick = async () => {
    try {
      const j = await api('orders', { date: today() });
      const n = j.orders.filter(o => o.status === 'new').length;
      const b = $('#obadge'); if (b) { b.hidden = !n; b.textContent = n; }
      if (S.lastOrderId != null && j.latest_id > S.lastOrderId) { beep(); if (S.view !== 'narocila') toast('Novo naročilo za prevzem'); }
      S.lastOrderId = j.latest_id;
      // Nove rezervacije za kateri koli dan (Tatjana rezervira večinoma vnaprej).
      const rn = await api('reservations/new', S.lastResId != null ? { after_id: S.lastResId } : {});
      if (S.lastResId != null) {
        markSeen(rn.reservations, true);
        if (rn.reservations.length && S.view === 'pregled' && rn.reservations.some(r => r.start_at.slice(0, 10) === S.date)) loadDay();
        if (rn.reservations.length && S.view === 'rezervacije') viewList();
      }
      S.lastResId = rn.max_id;
    } catch { }
  };
  tick(); badgeT = setInterval(tick, 15000);
}
/* Zvok obvestil: izbira velja za to napravo (localStorage). Brskalnik dovoli zvok
   šele po prvem dotiku, zato se AudioContext ustvari ob prvem dotiku po nalaganju. */
let actx = null;
function soundOn() { try { return localStorage.getItem('tm_snd') === '1'; } catch { return false; } }
function initAudio() {
  if (!actx) { try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch { return; } }
  if (actx.state === 'suspended') actx.resume();
}
function setSound(on) {
  try { localStorage.setItem('tm_snd', on ? '1' : '0'); } catch { }
  if (on) { initAudio(); beep(); }
  drawSoundBtn();
}
function drawSoundBtn() {
  const on = soundOn();
  const a = $('#sndbtn'); if (a) { a.innerHTML = ic(on ? 'zvok' : 'zvokOff') + `<span>${on ? 'Zvok' : 'Tiho'}</span>`; a.classList.toggle('muted-s', !on); a.title = on ? 'Zvok obvestil je vklopljen' : 'Zvok obvestil je izklopljen'; }
  const b = $('#sndbtn2'); if (b) b.innerHTML = ic(on ? 'zvok' : 'zvokOff') + (on ? 'Zvok obvestil: vklopljen' : 'Zvok obvestil: izklopljen');
}
function beep() {
  if (!soundOn() || !actx) return;
  [0, .18].forEach(d => { const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = 880; o.connect(g); g.connect(actx.destination); g.gain.setValueAtTime(.25, actx.currentTime + d); g.gain.exponentialRampToValueAtTime(.001, actx.currentTime + d + .15); o.start(actx.currentTime + d); o.stop(actx.currentTime + d + .16); });
}
async function viewOrders() {
  const m = shell();
  S.odate = S.odate || today();
  m.innerHTML = `<div class="top"><h2 class="grow">Naročila za prevzem</h2>
    <div class="datenav"><button class="ib" id="oprev" aria-label="Prejšnji dan">${ic('levo')}</button><input type="date" id="odate" value="${S.odate}"><button class="ib" id="onext" aria-label="Naslednji dan">${ic('desno')}</button></div>
    <button class="btn primary" id="neword">${ic('plus')}Novo naročilo</button></div><div id="kb"></div>`;
  $('#odate').onchange = e => { S.odate = e.target.value; load(); };
  $('#oprev').onclick = () => { S.odate = addDays(S.odate, -1); viewOrders(); };
  $('#onext').onclick = () => { S.odate = addDays(S.odate, 1); viewOrders(); };
  $('#neword').onclick = orderModal;
  const seen = new Set(S.seenOrders || []);
  const load = async () => {
    const j = await api('orders', { date: S.odate });
    const os = j.orders, now = new Date();
    const fresh = os.filter(o => S.seenOrders && !seen.has(o.id)).map(o => o.id);
    os.forEach(o => seen.add(o.id)); S.seenOrders = [...seen];
    // Stolpec "Prevzeto" je viden samo za danes in prihodnje dni; za pretekle dni samo število.
    const past = S.odate < today();
    const cols = [['new', 'Nova'], ['preparing', 'V pripravi'], ['ready', 'Opravljeno'], ['picked_up', 'Prevzeto']].filter(c => !(past && c[0] === 'picked_up'));
    const next = { new: ['preparing', 'Predano kuhinji'], preparing: ['ready', 'Opravljeno'], ready: ['picked_up', 'Prevzeto'] };
    const card = o => {
      const late = o.status !== 'picked_up' && new Date(o.pickup_at.replace(' ', 'T')) < now;
      return `<div class="ocard ${o.status} ${late ? 'late' : ''} ${fresh.includes(o.id) ? 'fresh' : ''}" data-oid="${o.id}">
        <div class="h"><b>${timeOf(o.pickup_at)}</b><span class="muted">#${esc(o.code)}</span></div>
        <div><b>${esc(o.name)}</b> ${o.phone ? `<span class="muted small">${esc(o.phone)}</span>` : ''} <span class="pill ${o.source}">${SRC[o.source] || o.source}</span></div>
        <ul>${o.items.map(i => `<li><b>${i.qty}×</b> ${esc(i.name)}${i.options_text ? ` <small>(${esc(i.options_text)})</small>` : ''}${i.notes ? ` <small>· ${esc(i.notes)}</small>` : ''}</li>`).join('')}</ul>
        ${o.notes ? `<div class="note">${esc(o.notes)}</div>` : ''}
        <div class="row"><span class="price">${eur(o.total_cents)}</span><span style="flex:1"></span>
        ${o.status !== 'picked_up' ? `<button class="btn sm ghost danger" data-st="cancelled">Prekliči</button>` : ''}
        ${next[o.status] ? `<button class="btn sm primary" data-st="${next[o.status][0]}">${next[o.status][1]}</button>` : ''}</div></div>`;
    };
    const cancelled = os.filter(o => o.status === 'cancelled').length;
    const pickedUp = os.filter(o => o.status === 'picked_up').length;
    $('#kb').innerHTML = `<div class="kanban ${cols.length === 3 ? 'k3' : ''}">${cols.map(([k, l]) => { const list = os.filter(o => o.status === k); return `<div class="col"><h3>${l}<span class="muted">${list.length}</span></h3>${list.map(card).join('') || '<div class="empty small">Prazno</div>'}</div>`; }).join('')}</div>
      ${past && pickedUp ? `<p class="muted small">Prevzetih: ${pickedUp}</p>` : ''}
      ${cancelled ? `<p class="muted small">Preklicanih: ${cancelled}</p>` : ''}`;
    $$('#kb [data-st]').forEach(b => b.onclick = async () => {
      const id = +b.closest('[data-oid]').dataset.oid;
      if (b.dataset.st === 'cancelled' && !confirm('Prekličem naročilo?')) return;
      try { await api('orders/status', { id, status: b.dataset.st }); load(); } catch (e) { toast(e.message, true); }
    });
    if (fresh.length) beep();
  };
  S.reloadOrders = load;
  await load();
  stopPolling(); pollT = setInterval(() => { if (S.view === 'narocila') load(); }, 10000);
}
async function orderModal() {
  const menu = (await api('menu')).menu;
  const items = menu.flatMap(c => c.items.filter(i => i.available && i.pickup).map(i => ({ ...i, cat: c.name })));
  const cart = [];
  const md = modal('Novo naročilo za prevzem', `
    <div class="grid2"><label>Ime<input name="name"></label><label>Telefon<input name="phone"></label></div>
    <div class="grid2"><label>Datum prevzema<input type="date" name="pdate" value="${S.odate || today()}"></label><label>Ura prevzema<input type="time" name="ptime" step="300"></label></div>
    <label>Iskanje jedi<input id="isearch" placeholder="npr. pica"></label>
    <div class="pick" id="ipick"></div>
    <h3>Košarica</h3><div id="cart" class="stack"></div>
    <label>Opombe<textarea name="notes" rows="2"></textarea></label>
    <div class="avail" id="otot">Dodaj jedi.</div>`,
    `<button class="btn" data-x2>Zapri</button><button class="btn primary" data-save>Ustvari naročilo</button>`);
  const drawPick = () => {
    const q = $('#isearch', md.el).value.toLowerCase();
    $('#ipick', md.el).innerHTML = items.filter(i => !q || i.name.toLowerCase().includes(q) || i.cat.toLowerCase().includes(q)).map(i => `<div class="pi"><div><b>${esc(i.name)}</b> <span class="muted small">${esc(i.cat)}</span></div><span class="price">${eur(i.price_cents)}</span><button class="btn sm" data-add="${i.id}">Dodaj</button></div>`).join('') || '<div class="empty">Ni zadetkov.</div>';
    $$('[data-add]', md.el).forEach(b => b.onclick = () => { const it = items.find(i => i.id === +b.dataset.add); cart.push({ it, qty: 1, opts: new Set(), notes: '' }); drawCart(); });
  };
  const lineCents = l => (l.it.price_cents + l.it.option_groups.flatMap(g => g.options).filter(o => l.opts.has(+o.id)).reduce((s, o) => s + +o.price_cents, 0)) * l.qty;
  const drawCart = () => {
    $('#cart', md.el).innerHTML = cart.map((l, idx) => `<div class="og" data-i="${idx}"><div class="row"><b style="flex:1">${esc(l.it.name)}</b>
      <div class="qty"><button data-q="-1">−</button><b>${l.qty}</b><button data-q="1">+</button></div><span class="price">${eur(lineCents(l))}</span><button class="btn sm ghost danger" data-rm>${ic('x')}</button></div>
      ${l.it.option_groups.map(g => `<div class="small"><b>${esc(g.name)}</b>${+g.required ? ' (obvezno)' : ''}${+g.max_select > 1 ? ` · največ ${g.max_select}` : ''}<div class="row">${g.options.map(o => `<label class="check"><input type="${+g.max_select === 1 ? 'radio' : 'checkbox'}" name="g${idx}_${g.id}" value="${o.id}" ${l.opts.has(+o.id) ? 'checked' : ''}> ${esc(o.name)}${+o.price_cents ? ' +' + eur(o.price_cents) : ''}</label>`).join('')}</div></div>`).join('')}
      <input placeholder="Opomba k jedi" data-n value="${esc(l.notes)}"></div>`).join('') || '<div class="muted small">Košarica je prazna.</div>';
    $$('#cart .og', md.el).forEach(el => {
      const l = cart[+el.dataset.i];
      $$('[data-q]', el).forEach(b => b.onclick = () => { l.qty = Math.max(1, l.qty + +b.dataset.q); drawCart(); });
      $('[data-rm]', el).onclick = () => { cart.splice(+el.dataset.i, 1); drawCart(); };
      $('[data-n]', el).oninput = e => l.notes = e.target.value;
      $$('input[type=radio],input[type=checkbox]', el).forEach(inp => inp.onchange = () => {
        const g = l.it.option_groups.find(g => inp.name.endsWith('_' + g.id));
        if (inp.type === 'radio') g.options.forEach(o => l.opts.delete(+o.id));
        if (inp.checked) l.opts.add(+inp.value); else l.opts.delete(+inp.value);
        drawCart();
      });
    });
    const tot = cart.reduce((s, l) => s + lineCents(l), 0);
    $('#otot', md.el).textContent = cart.length ? 'Skupaj: ' + eur(tot) : 'Dodaj jedi.';
  };
  $('#isearch', md.el).oninput = drawPick; drawPick(); drawCart();
  const d = new Date(Date.now() + (S.me.settings.pickup_prep_minutes || 20) * 60000);
  $('[name=ptime]', md.el).value = fromMin(Math.ceil((d.getHours() * 60 + d.getMinutes()) / 5) * 5);
  $('[data-x2]', md.el).onclick = md.close;
  $('[data-save]', md.el).onclick = async () => {
    const f = n => $(`[name=${n}]`, md.el).value.trim();
    if (!f('name')) return toast('Vpiši ime.', true);
    if (!cart.length) return toast('Košarica je prazna.', true);
    try {
      await api('orders/create', { name: f('name'), phone: f('phone'), notes: f('notes'), pickup_time: f('pdate') + ' ' + f('ptime'), items: cart.map(l => ({ item_id: l.it.id, qty: l.qty, options: [...l.opts], notes: l.notes })) });
      toast('Naročilo ustvarjeno'); md.close(); S.reloadOrders && S.reloadOrders();
    } catch (e) { toast(e.message, true); }
  };
}

/* ---------------- meni ---------------- */
async function viewMenu() {
  const m = shell();
  const menu = (await api('menu')).menu;
  S.menu = menu;
  m.innerHTML = `<div class="top"><h2 class="grow">Meni</h2><button class="btn" id="addcat">+ Kategorija</button><button class="btn primary" id="additem">+ Jed</button></div>
    <p class="muted small" style="margin-top:-8px">Tatjana bere ta meni. Ko jed zmanjka, jo izklopi in je Tatjana ne bo več ponujala.</p>
    ${menu.map(c => `<div class="card mcat"><div class="head"><h3 style="flex:1">${esc(c.name)}</h3>${c.id ? `<button class="btn sm ghost" data-ecat="${c.id}">Preimenuj</button><button class="btn sm ghost danger" data-dcat="${c.id}">Izbriši</button>` : ''}</div>
      ${c.items.map(i => `<div class="mitem ${i.available ? '' : 'na'}"><div><b>${esc(i.name)}</b>${i.option_groups.length ? ` <span class="pill">${i.option_groups.length} izbir</span>` : ''}${i.pickup ? '' : ' <span class="pill">samo v lokalu</span>'}<div class="muted small">${[esc(i.description || ''), i.allergens ? 'Alergeni: ' + esc(i.allergens) : ''].filter(Boolean).join(' · ')}</div></div>
        <span class="price">${eur(i.price_cents)}</span><button class="switch ${i.available ? 'on' : ''}" data-tog="${i.id}" title="Na voljo"></button><button class="btn sm" data-edit="${i.id}">Uredi</button></div>`).join('') || '<div class="empty small">Ni jedi.</div>'}</div>`).join('') || '<div class="card empty">Meni je prazen. Dodaj kategorijo in jedi.</div>'}`;
  $('#addcat').onclick = () => catModal();
  $('#additem').onclick = () => itemModal({});
  $$('[data-ecat]').forEach(b => b.onclick = () => catModal(menu.find(c => c.id === +b.dataset.ecat)));
  $$('[data-dcat]').forEach(b => b.onclick = async () => { if (confirm('Izbrišem kategorijo? Jedi ostanejo pod "Ostalo".')) { await api('menu/category', { id: +b.dataset.dcat, delete: 1 }); viewMenu(); } });
  $$('[data-tog]').forEach(b => b.onclick = async () => { await api('menu/toggle', { id: +b.dataset.tog }); viewMenu(); });
  $$('[data-edit]').forEach(b => b.onclick = () => itemModal(menu.flatMap(c => c.items).find(i => i.id === +b.dataset.edit)));
}
function catModal(c = {}) {
  const md = modal(c.id ? 'Preimenuj kategorijo' : 'Nova kategorija', `<label>Ime<input name="name" value="${esc(c.name || '')}"></label><label>Vrstni red<input type="number" name="sort" value="${c.sort ?? S.menu.length}"></label>`, `<button class="btn primary" data-save>Shrani</button>`);
  $('[data-save]', md.el).onclick = async () => { await api('menu/category', { id: c.id, name: $('[name=name]', md.el).value, sort: +$('[name=sort]', md.el).value }); md.close(); viewMenu(); };
}
function itemModal(i) {
  const cats = S.menu.filter(c => c.id);
  const groups = JSON.parse(JSON.stringify(i.option_groups || []));
  const md = modal(i.id ? 'Uredi jed' : 'Nova jed', `
    <div class="grid2"><label>Ime<input name="name" value="${esc(i.name || '')}"></label><label>Kategorija<select name="category_id">${cats.map(c => `<option value="${c.id}" ${c.id === i.category_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label></div>
    <label>Opis<textarea name="description" rows="2">${esc(i.description || '')}</textarea></label>
    <div class="grid3"><label>Cena (€)<input name="price_eur" value="${i.price_cents != null ? (i.price_cents / 100).toFixed(2).replace('.', ',') : ''}"></label><label>Priprava (min)<input type="number" name="prep_minutes" value="${i.prep_minutes ?? ''}" placeholder="privzeto"></label><label>Vrstni red<input type="number" name="sort" value="${i.sort ?? 0}"></label></div>
    <label>Alergeni<input name="allergens" value="${esc(i.allergens || '')}" placeholder="gluten, mleko, jajca"></label>
    <div class="row"><label class="check"><input type="checkbox" name="available" ${i.available !== 0 ? 'checked' : ''}> Na voljo</label><label class="check"><input type="checkbox" name="pickup" ${i.pickup !== 0 ? 'checked' : ''}> Tudi za prevzem</label></div>
    <div class="row"><h3 style="flex:1">Izbire in dodatki</h3><button class="btn sm" id="addg">+ Skupina</button></div><div id="groups" class="stack"></div>`,
    `${i.id ? '<button class="btn danger" data-del style="margin-right:auto">Izbriši jed</button>' : ''}<button class="btn primary" data-save>Shrani</button>`);
  const drawG = () => {
    $('#groups', md.el).innerHTML = groups.map((g, gi) => `<div class="og" data-g="${gi}"><div class="grid3"><input data-gf="name" placeholder="npr. Velikost" value="${esc(g.name)}"><label class="check"><input type="checkbox" data-gf="required" ${+g.required ? 'checked' : ''}> Obvezno</label><label class="check">Največ <input type="number" min="1" style="width:60px" data-gf="max_select" value="${g.max_select || 1}"></label></div>
      ${(g.options || []).map((o, oi) => `<div class="opt" data-o="${oi}"><input data-of="name" placeholder="Možnost" value="${esc(o.name)}"><input data-of="price_eur" placeholder="+ €" value="${o.price_eur ?? (o.price_cents ? (o.price_cents / 100).toFixed(2).replace('.', ',') : '')}"><button class="btn sm ghost danger" data-orm>${ic('x')}</button></div>`).join('')}
      <div class="row"><button class="btn sm" data-oadd>+ Možnost</button><button class="btn sm ghost danger" data-grm>Odstrani skupino</button></div></div>`).join('') || '<div class="muted small">Npr. velikost pice, pečenost, dodatki z doplačilom.</div>';
    $$('[data-g]', md.el).forEach(el => {
      const g = groups[+el.dataset.g];
      $$('[data-gf]', el).forEach(inp => inp.oninput = () => g[inp.dataset.gf] = inp.type === 'checkbox' ? (inp.checked ? 1 : 0) : inp.value);
      $$('[data-o]', el).forEach(oel => { const o = g.options[+oel.dataset.o]; $$('[data-of]', oel).forEach(inp => inp.oninput = () => o[inp.dataset.of] = inp.value); $('[data-orm]', oel).onclick = () => { g.options.splice(+oel.dataset.o, 1); drawG(); }; });
      $('[data-oadd]', el).onclick = () => { (g.options = g.options || []).push({ name: '', price_eur: '' }); drawG(); };
      $('[data-grm]', el).onclick = () => { groups.splice(+el.dataset.g, 1); drawG(); };
    });
  };
  $('#addg', md.el).onclick = () => { groups.push({ name: '', required: 0, max_select: 1, options: [{ name: '', price_eur: '' }] }); drawG(); };
  drawG();
  if (i.id) $('[data-del]', md.el).onclick = async () => { if (confirm('Izbrišem jed?')) { await api('menu/item', { id: i.id, delete: 1 }); md.close(); viewMenu(); } };
  $('[data-save]', md.el).onclick = async () => {
    const f = n => $(`[name=${n}]`, md.el);
    const og = groups.map(g => ({ ...g, options: (g.options || []).map(o => ({ name: o.name, price_eur: o.price_eur ?? (o.price_cents ? o.price_cents / 100 : 0) })) }));
    try {
      await api('menu/item', { id: i.id, name: f('name').value, category_id: +f('category_id').value || null, description: f('description').value, price_eur: f('price_eur').value, prep_minutes: f('prep_minutes').value, sort: +f('sort').value, allergens: f('allergens').value, available: f('available').checked ? 1 : 0, pickup: f('pickup').checked ? 1 : 0, option_groups: og });
      md.close(); viewMenu(); toast('Shranjeno');
    } catch (e) { toast(e.message, true); }
  };
}

/* ---------------- nastavitve ---------------- */
async function viewSettings() {
  const m = shell();
  const j = await api('settings');
  const st = j.settings, hours = j.hours, sp = j.special_days;
  const apiBase = location.href.replace(/[#?].*$/, '').replace(/index\.php$/, '').replace(/\/?$/, '/') + 'api.php?r=ai/';
  const appCard = namestitevHTML();
  const hourRows = kind => [1, 2, 3, 4, 5, 6, 7].map(d => {
    const hs = hours.filter(h => h.kind === kind && +h.weekday === d);
    return `<tr data-kind="${kind}" data-day="${d}"><td><b>${DAYS[d]}</b></td><td class="ints">${hs.map(h => intHTML(kind, h)).join('') || '<span class="muted small closed">Zaprto</span>'}</td><td><button class="btn sm" data-addint>+ Interval</button></td></tr>`;
  }).join('');
  m.innerHTML = `<div class="top"><h2 class="grow">Nastavitve</h2><button class="btn primary" id="ssave">Shrani nastavitve</button></div>
  <div class="stack">
    ${appCard}
    <div class="card pad stack"><h3>Restavracija</h3><div class="grid3"><label>Ime<input id="r_name" value="${esc(j.restaurant.name)}"></label><label>Telefon<input id="r_phone" value="${esc(j.restaurant.phone || '')}"></label><label>Naslov<input id="r_address" value="${esc(j.restaurant.address || '')}"></label></div></div>
    <div class="stack">
      <div class="card pad stack"><h3>Delovni čas za goste</h3><p class="muted small">Zadnja rezervacija: najkasnejša ura, ko Tatjana še sprejme rezervacijo.</p><table class="simple">${hourRows('dine')}</table></div>
      <div class="card pad stack"><h3>Ure prevzema</h3><p class="muted small">Kdaj je mogoče prevzeti naročeno hrano.</p><table class="simple">${hourRows('pickup')}</table></div>
    </div>
    <div class="card pad stack"><h3>Pravila rezervacij</h3>
      <div class="grid4"><label>Korak termina (min)<input type="number" data-s="slot_minutes" value="${st.slot_minutes}"></label><label>Čas za pospravljanje (min)<input type="number" data-s="buffer_minutes" value="${st.buffer_minutes}"></label><label>Največ prihodov na termin (0 = brez)<input type="number" data-s="max_covers_per_slot" value="${st.max_covers_per_slot}"></label><label>Največja skupina za Tatjano<input type="number" data-s="max_party_online" value="${st.max_party_online}"></label>
      <label>Najmanj vnaprej (min)<input type="number" data-s="min_notice_minutes" value="${st.min_notice_minutes}"></label><label>Največ dni vnaprej<input type="number" data-s="days_ahead" value="${st.days_ahead}"></label><label style="grid-column:span 2">Cone (ločene z vejico)<input id="zones" value="${esc(st.zones.join(', '))}"></label></div>
      <h3>Povprečen čas obiska</h3><table class="simple" id="durs"><tr><th>Do oseb</th><th>Minut</th><th></th></tr>${st.durations.map(d => durRow(d)).join('')}</table><div><button class="btn sm" id="adddur">+ Pravilo</button></div></div>
    <div class="card pad stack"><h3>Prevzem hrane</h3><div class="grid4"><label class="check"><input type="checkbox" data-s="pickup_enabled" ${st.pickup_enabled ? 'checked' : ''}> Sprejemamo naročila za prevzem</label><label>Privzeti čas priprave (min)<input type="number" data-s="pickup_prep_minutes" value="${st.pickup_prep_minutes}"></label><label>Termin kuhinje (min)<input type="number" data-s="pickup_slot_minutes" value="${st.pickup_slot_minutes}"></label><label>Največ naročil na termin<input type="number" data-s="max_orders_per_slot" value="${st.max_orders_per_slot}"></label></div></div>
    <div class="card pad stack"><h3>Zaprti in posebni dnevi</h3><table class="simple" id="spd"><tr><th>Datum</th><th>Zaprto</th><th>Opomba</th><th></th></tr>${sp.map(spRow).join('')}</table><div><button class="btn sm" id="addsp">+ Dan</button></div></div>
    <div class="card pad stack"><h3>Tatjana</h3>
      <label>Pozdrav na začetku klica<input data-s="ai_greeting" value="${esc(st.ai_greeting)}"></label>
      <label class="check"><input type="checkbox" data-s="sms_enabled" ${st.sms_enabled ? 'checked' : ''}> Pošiljaj SMS potrditve (ko je ponudnik SMS povezan)</label>
      <div><div class="small muted">Naslov API</div><code class="key">${esc(apiBase)}</code></div>
      <div><div class="small muted">API ključ (glava X-Api-Key)</div><code class="key" id="akey">${esc(j.restaurant.api_key)}</code></div>
      <div class="row"><button class="btn sm" id="copyk">Kopiraj ključ</button><button class="btn sm danger" id="newk">Ustvari nov ključ</button></div></div>
  </div>`;
  wireNamestitev();
  const wire = () => {
    $$('[data-addint]').forEach(b => b.onclick = () => { const tr = b.closest('tr'); $('.closed', tr)?.remove(); $('.ints', tr).insertAdjacentHTML('beforeend', intHTML(tr.dataset.kind, { open_time: '12:00', close_time: '22:00', last_booking: tr.dataset.kind === 'dine' ? '20:30' : '' })); wire(); });
    $$('[data-rmint]').forEach(b => b.onclick = () => { b.closest('.int').remove(); });
    $$('[data-rmrow]').forEach(b => b.onclick = () => b.closest('tr').remove());
  };
  $('#adddur').onclick = () => { $('#durs').insertAdjacentHTML('beforeend', durRow({ max_party: 8, minutes: 120 })); wire(); };
  $('#addsp').onclick = () => { $('#spd').insertAdjacentHTML('beforeend', spRow({ day: today(), closed: 1, note: '' })); wire(); };
  $('#copyk').onclick = () => { navigator.clipboard.writeText($('#akey').textContent); toast('Ključ kopiran'); };
  $('#newk').onclick = async () => { if (!confirm('Star ključ bo prenehal delovati. Tatjano boš moral posodobiti. Nadaljujem?')) return; const r = await api('settings/new_key'); $('#akey').textContent = r.api_key; toast('Nov ključ ustvarjen'); };
  wire();
  $('#ssave').onclick = async () => {
    const settings = {};
    $$('[data-s]').forEach(i => settings[i.dataset.s] = i.type === 'checkbox' ? i.checked : i.type === 'number' ? +i.value : i.value);
    settings.zones = $('#zones').value.split(',').map(s => s.trim()).filter(Boolean);
    settings.durations = $$('#durs tr[data-dur]').map(tr => ({ max_party: +$('[data-k=max_party]', tr).value, minutes: +$('[data-k=minutes]', tr).value })).filter(d => d.max_party > 0 && d.minutes > 0);
    if (!settings.durations.length) return toast('Dodaj vsaj eno pravilo za čas obiska.', true);
    const hrs = $$('.int').map(el => ({ kind: el.dataset.kind, weekday: +el.closest('tr').dataset.day, open_time: $('[data-k=open]', el).value, close_time: $('[data-k=close]', el).value, last_booking: $('[data-k=last]', el)?.value || null }));
    const special = $$('#spd tr[data-sp]').map(tr => ({ day: $('[data-k=day]', tr).value, closed: $('[data-k=closed]', tr).checked ? 1 : 0, note: $('[data-k=note]', tr).value }));
    try {
      await api('settings/save', { restaurant: { name: $('#r_name').value, phone: $('#r_phone').value, address: $('#r_address').value }, settings, hours: hrs, special_days: special });
      S.me = await api('auth/me'); toast('Nastavitve shranjene');
    } catch (e) { toast(e.message, true); }
  };
}
function intHTML(kind, h) {
  return `<div class="int row" data-kind="${kind}" style="margin:3px 0"><input type="time" data-k="open" value="${h.open_time}" style="width:110px"> do <input type="time" data-k="close" value="${h.close_time}" style="width:110px">${kind === 'dine' ? ` <span class="small muted">zadnja</span> <input type="time" data-k="last" value="${h.last_booking || ''}" style="width:110px">` : ''}<button class="btn sm ghost danger" data-rmint>${ic('x')}</button></div>`;
}
function durRow(d) { return `<tr data-dur><td><input type="number" data-k="max_party" value="${d.max_party}" style="width:90px"></td><td><input type="number" data-k="minutes" step="15" value="${d.minutes}" style="width:90px"></td><td><button class="btn sm ghost danger" data-rmrow>${ic('x')}</button></td></tr>`; }
/* ---------------- namestitev kot aplikacija (PWA) ---------------- */
let namestitev = null; // dogodek brskalnika (Android, Chrome, Edge) za gumb Namesti
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); namestitev = e; if ($('#appcard')) $('#appcard').outerHTML = namestitevHTML(), wireNamestitev(); });
window.addEventListener('appinstalled', () => { namestitev = null; toast('Aplikacija je nameščena'); });
const jeNamescena = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
const jeIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
function namestitevHTML() {
  let h;
  if (jeNamescena()) h = '<p class="muted small">Aplikacija je nameščena na tej napravi. Brez povezave pokaže zadnje prebrane podatke.</p>';
  else if (namestitev) h = '<p class="muted small">Namesti Tatjana Miza na to napravo: odpira se kot aplikacija, z ikono na začetnem zaslonu.</p><div><button class="btn primary" id="appinst">Namesti aplikacijo</button></div>';
  else if (jeIOS()) h = '<p class="muted small">Na iPhonu ali iPadu odpri to stran v <b>Safariju</b>, tapni <b>Deli</b> (kvadrat s puščico) in izberi <b>Dodaj na začetni zaslon</b>.</p>';
  else h = '<p class="muted small">V meniju brskalnika izberi <b>Namesti aplikacijo</b> ali <b>Dodaj na začetni zaslon</b>. Na Androidu deluje v Chromu.</p>';
  return `<div class="card pad stack" id="appcard"><h3>Aplikacija na tablici ali telefonu</h3>${h}</div>`;
}
function wireNamestitev() {
  const b = $('#appinst'); if (!b) return;
  b.onclick = async () => { const p = namestitev; namestitev = null; await p.prompt(); try { await p.userChoice; } catch { } if ($('#appcard')) $('#appcard').outerHTML = namestitevHTML(); };
}
/* Lupina aplikacije za odpiranje brez omrežja (sw.js). Brskalnik zahteva HTTPS ali localhost. */
if ('serviceWorker' in navigator && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => { }));
}
window.addEventListener('offline', () => { if (!S.offlineAt) setOffline(true); });
window.addEventListener('online', () => { if (S.me) route(); else boot(); });

function spRow(d) { return `<tr data-sp><td><input type="date" data-k="day" value="${d.day}"></td><td><input type="checkbox" data-k="closed" ${+d.closed ? 'checked' : ''}></td><td><input data-k="note" value="${esc(d.note || '')}" placeholder="npr. božič"></td><td><button class="btn sm ghost danger" data-rmrow>${ic('x')}</button></td></tr>`; }

boot();
