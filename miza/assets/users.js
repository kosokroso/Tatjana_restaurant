'use strict';
/* Razdelek Uporabniki v Nastavitvah */
(function () {
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  async function call(r, data = {}) {
    const res = await fetch('users.php?r=' + r, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-TM': '1' }, body: JSON.stringify(data), credentials: 'same-origin' });
    const j = await res.json().catch(() => ({ ok: false, message: 'Napaka strežnika' }));
    if (!j.ok) throw new Error(j.message || 'Napaka');
    return j;
  }
  function msg(t, err) { typeof toast === 'function' ? toast(t, err) : alert(t); }
  function draw(card, j) {
    card.innerHTML = `<h3>Uporabniki</h3><p class="muted small">Vsi uporabniki imajo poln dostop do te restavracije.</p>
      <table class="simple"><tr><th>E-naslov</th><th>Ime</th><th>Dodan</th><th></th></tr>
      ${j.users.map(u => `<tr data-id="${u.id}"><td><b>${esc(u.email)}</b>${+u.id === +j.me ? ' <span class="pill">ti</span>' : ''}</td><td>${esc(u.name || '')}</td><td class="muted small">${esc((u.created_at || '').slice(0, 10))}</td>
        <td style="text-align:right"><button class="btn sm" data-pw>Novo geslo</button>${+u.id === +j.me ? '' : ' <button class="btn sm ghost danger" data-del>Izbriši</button>'}</td></tr>`).join('')}</table>
      <div class="grid4" style="align-items:end"><label>E-naslov<input id="nu_email" type="email" autocomplete="off"></label><label>Ime<input id="nu_name" autocomplete="off"></label><label>Geslo (vsaj 8 znakov)<input id="nu_pass" type="password" autocomplete="new-password"></label><button class="btn primary" id="nu_add">+ Dodaj uporabnika</button></div>`;
    card.querySelector('#nu_add').onclick = async () => {
      try {
        const r = await call('create', { email: card.querySelector('#nu_email').value, name: card.querySelector('#nu_name').value, password: card.querySelector('#nu_pass').value });
        draw(card, r); msg('Uporabnik dodan');
      } catch (e) { msg(e.message, true); }
    };
    card.querySelectorAll('tr[data-id]').forEach(tr => {
      const id = +tr.dataset.id;
      tr.querySelector('[data-pw]').onclick = async () => {
        const p = prompt('Novo geslo (vsaj 8 znakov):'); if (!p) return;
        try { draw(card, await call('password', { id, password: p })); msg('Geslo spremenjeno'); } catch (e) { msg(e.message, true); }
      };
      const d = tr.querySelector('[data-del]');
      if (d) d.onclick = async () => {
        if (!confirm('Izbrišem uporabnika?')) return;
        try { draw(card, await call('delete', { id })); msg('Uporabnik izbrisan'); } catch (e) { msg(e.message, true); }
      };
    });
  }
  async function mount() {
    const save = document.getElementById('ssave');
    if (!save || document.getElementById('users-card')) return;
    const stack = save.closest('.main')?.querySelector('.stack');
    if (!stack) return;
    const card = document.createElement('div');
    card.className = 'card pad stack'; card.id = 'users-card';
    card.innerHTML = '<h3>Uporabniki</h3><p class="muted small">Nalagam…</p>';
    stack.appendChild(card);
    try { draw(card, await call('list')); } catch (e) { card.innerHTML = '<h3>Uporabniki</h3><div class="alert">' + esc(e.message) + '</div>'; }
  }
  new MutationObserver(mount).observe(document.documentElement, { childList: true, subtree: true });
})();