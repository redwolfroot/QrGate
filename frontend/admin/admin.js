/* QrGate admin · one page, one view per rail entry (#hash routing, no reloads).
 * Reads its start data from window.ADMIN (rendered by index.php), talks to
 * api.php (writes) and admin-api-proxy.php (backend reads/writes). */
(function () {
  'use strict';

  const A = window.ADMIN || {};
  const S = A.show || {};
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const eur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
  const money = (v) => eur.format(Number(v) || 0);
  const num = (v) => new Intl.NumberFormat('de-DE').format(Number(v) || 0);
  const WD = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  // Local calendar day; replaced by the server's (event time zone) once known.
  let today = (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
  function fmtDate(iso, withDay) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!m) return iso || '';
    const d = new Date(+m[1], +m[2] - 1, +m[3]);
    return (withDay === false ? '' : WD[d.getDay()] + ', ') + m[3] + '.' + m[2] + '.' + m[1];
  }
  const ICON = {
    edit: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
    trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    map: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    up: '<path d="m18 15-6-6-6 6"/>', down: '<path d="m6 9 6 6 6-6"/>', x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    list: '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1"/><path d="m9 14 2 2 4-4"/>',
    dl: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  };
  const icon = (k) => '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICON[k] + '</svg>';

  // ---- transport ------------------------------------------------------------
  async function call(url, body, isForm) {
    try {
      const r = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers: Object.assign({ 'X-CSRF-Token': A.csrf }, isForm || body === undefined ? {} : { 'Content-Type': 'application/json' }),
        body: body === undefined ? undefined : (isForm ? body : JSON.stringify(body)),
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (r.status === 401) { location.href = 'login.php'; return {}; }
      const j = await r.json().catch(() => ({}));
      return Object.assign({ _ok: r.ok && (j.status === 'success' || (j.status === undefined && !j.error)) }, j);
    } catch (e) {
      return { _ok: false, message: 'Netzwerkfehler' };
    }
  }
  const action = (name, body, isForm) => call('api.php?action=' + name, body, isForm);
  const proxy = (ep, body) => call('admin-api-proxy.php?endpoint=' + ep, body);

  // ---- toasts + confirm ----------------------------------------------------------
  function toast(msg, kind) {
    const t = document.createElement('div');
    t.className = 'avo-plate avo-toast avo-status ' + (kind === 'error' ? 'error' : kind === 'warn' ? 'warning' : 'success');
    t.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    t.innerHTML = '<div class="avo-title">' + esc(kind === 'error' ? 'Fehler' : kind === 'warn' ? 'Hinweis' : 'Gespeichert') + '</div><p class="avo-small">' + esc(msg) + '</p>';
    $('toasts').append(t);
    setTimeout(() => t.remove(), kind === 'error' ? 7000 : 3500);
  }
  function confirmBox(title, text, okLabel, word) {
    const d = $('cfDlg');
    $('cfTitle').textContent = title;
    $('cfText').textContent = text;
    $('cfOk').querySelector('span').textContent = okLabel || 'Bestätigen';
    $('cfWordField').hidden = !word;
    $('cfWord').value = '';
    $('cfWordLabel').textContent = word ? 'Zum Bestätigen „' + word + '“ eintippen' : '';
    return new Promise((resolve) => {
      const done = (v) => { d.close(); resolve(v); };
      $('cfForm').onsubmit = (e) => {
        e.preventDefault();
        if (word && $('cfWord').value.trim() !== word) { $('cfWord').setAttribute('aria-invalid', 'true'); $('cfWord').focus(); return; }
        done(true);
      };
      d.querySelector('[data-close]').onclick = () => done(false);
      d.oncancel = () => resolve(false);
      $('cfWord').removeAttribute('aria-invalid');
      d.showModal();
      if (word) $('cfWord').focus();
    });
  }
  document.querySelectorAll('dialog [data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  function busyBtn(btn, on) { if (btn) { btn.disabled = on; btn.setAttribute('aria-busy', on ? 'true' : 'false'); } }

  // ---- routing ----------------------------------------------------------------
  const TITLES = { dashboard: 'Dashboard', stats: 'Statistik', broadcast: 'Durchsagen', export: 'Export', event: 'Veranstaltung', dates: 'Termine & Orte', images: 'Bilder', screens: 'Screens', payments: 'Zahlung', accounts: 'Konten', system: 'Wartung' };
  const inits = {}, started = {};
  function route() {
    let v = location.hash.slice(1);
    if (!TITLES[v]) v = 'dashboard';
    document.querySelectorAll('.adm-view').forEach((s) => { s.hidden = s.dataset.view !== v; });
    document.querySelectorAll('[data-nav]').forEach((a) => {
      if (a.classList.contains('adm-brand')) return;
      if (a.dataset.nav === v) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    $('crumb').textContent = TITLES[v];
    document.title = TITLES[v] + ' · QrGate Admin';
    if (!started[v] && inits[v]) { started[v] = true; inits[v](); }
    if (v === 'dashboard' || v === 'stats' || v === 'dates') pollNow();
    if (v === 'broadcast' && started.broadcast) castLoad();
    window.scrollTo(0, 0);
  }
  window.addEventListener('hashchange', route);

  function renderStoreChip() {
    const c = $('storeChip');
    c.textContent = S.store_lock ? 'SHOP GESPERRT' : 'SHOP OFFEN';
    c.style.color = S.store_lock ? 'var(--avo-warning)' : '';
  }

  // ---- overview (dashboard + stats) ------------------------------------------------
  let OV = null, pollTimer = null;
  async function pollNow() {
    clearTimeout(pollTimer);
    const r = await proxy('overview');
    if (r._ok && r.data) {
      OV = r.data;
      if (OV.today) today = OV.today;
      $('liveSerial').textContent = 'LIVE';
      renderDashboard();
      if (started.stats) renderStats();
      if (started.dates) renderDays();
    } else {
      $('liveSerial').textContent = 'OFFLINE';
    }
    pollTimer = setTimeout(() => {
      const v = location.hash.slice(1) || 'dashboard';
      if (!document.hidden && (v === 'dashboard' || v === 'stats' || v === 'dates')) pollNow(); else pollTimer = setTimeout(pollNow, 10000);
    }, 10000);
  }

  function dateStats(date) {
    return (OV && OV.by_date && OV.by_date[date]) || { sold: 0, paid: 0, unpaid: 0, checked_in: 0, revenue: 0, outstanding: 0 };
  }

  function renderDashboard() {
    if (!OV) return;
    const dates = S.dates || [];
    const upcoming = dates.filter((d) => d.date >= today);
    let sold = 0, cap = 0, rev = 0, open = 0, openEur = 0;
    dates.forEach((d) => {
      const st = dateStats(d.date);
      rev += st.revenue; open += st.unpaid; openEur += st.outstanding;
      if (d.date >= today) { sold += st.sold; cap += d.tickets; }
    });
    $('dashSub').textContent = [S.orga_name, upcoming.length + ' kommende Termine'].filter(Boolean).join(' · ');
    $('tSold').innerHTML = num(sold) + '<small>/ ' + num(cap) + '</small>';
    $('tSoldBar').style.transform = 'scaleX(' + (cap ? Math.min(1, sold / cap) : 0) + ')';
    $('tSoldSub').textContent = 'KOMMENDE TERMINE · ' + (cap ? Math.round(sold / cap * 100) : 0) + ' %';
    $('tRev').textContent = money(rev);
    const inCheckout = Object.values(OV.in_checkout || {}).reduce((a, b) => a + b, 0);
    $('tRevSub').textContent = inCheckout ? inCheckout + ' TICKETS GERADE IM CHECKOUT' : 'ONLINE + KASSE';
    $('tOpen').innerHTML = num(open) + '<small>Tickets</small>';
    $('tOpenSub').textContent = money(openEur) + ' AUSSTEHEND';

    // door: today's date if it has one, else the next upcoming
    const focus = dates.find((d) => d.date === today) || upcoming[0] || null;
    const fs = focus ? dateStats(focus.date) : null;
    $('tIn').innerHTML = fs ? num(fs.checked_in) + '<small>/ ' + num(fs.sold) + '</small>' : '–';
    $('tInBar').style.transform = 'scaleX(' + (fs && fs.sold ? fs.checked_in / fs.sold : 0) + ')';
    $('tInSub').textContent = focus ? (focus.date === today ? 'HEUTE' : fmtDate(focus.date, false)) + ' · ' + focus.time : 'KEIN TERMIN';
    $('liveIn').textContent = fs ? num(fs.checked_in) : '–';
    $('liveOf').textContent = fs ? 'von ' + num(fs.sold) + ' eingecheckt' : '';
    $('liveDate').textContent = focus ? (focus.date === today ? 'HEUTE · ' : '') + fmtDate(focus.date) + ' · ' + focus.time : 'KEIN KOMMENDER TERMIN';
    const scans = OV.recent_checkins || [];
    $('liveList').innerHTML = scans.length
      ? scans.map((x) => '<li><span>' + esc(x.name || x.tid) + (x.seat_label ? ' · ' + esc(x.seat_label) : '') + '</span><span>' + esc((String(x.used_at || '').match(/(\d{2}:\d{2})/) || [])[1] || '') + '</span></li>').join('')
      : '<li><span class="avo-muted">Noch keine Scans.</span><span></span></li>';

    // dates table
    const locName = (id) => (S.locations && S.locations[id] ? S.locations[id].name : '–');
    $('dashDates').innerHTML = dates.length ? dates.map((d) => {
      const st = dateStats(d.date);
      const hold = (OV.in_checkout || {})[d.date] || 0;
      const pct = d.tickets ? Math.min(1, st.sold / d.tickets) : 0;
      return '<tr class="' + (d.date < today ? 'adm-past' : '') + '"><td>' + esc(fmtDate(d.date)) + '<span class="adm-sub">' + esc(d.time) + (d.seating ? ' · PLATZWAHL' : '') + '</span></td>'
        + '<td>' + esc(locName(d.location)) + '</td>'
        + '<td><div class="adm-meter adm-meter--row"><span style="transform:scaleX(' + pct + ')"></span></div><span class="adm-sub">' + Math.round(pct * 100) + ' %</span></td>'
        + '<td class="num">' + num(st.sold) + ' / ' + num(d.tickets) + '</td>'
        + '<td class="num">' + (hold ? num(hold) : '–') + '</td>'
        + '<td class="num">' + num(d.available) + '</td>'
        + '<td class="num">' + (st.unpaid ? num(st.unpaid) : '–') + '</td>'
        + '<td class="num">' + money(st.revenue) + '</td>'
        + '<td class="num">' + num(st.checked_in) + '</td></tr>';
    }).join('') : '<tr class="adm-empty"><td colspan="9">Noch keine Termine. <a class="avo-link" href="#dates">Termin anlegen</a></td></tr>';

    const orders = OV.recent_orders || [];
    $('dashOrders').innerHTML = orders.length ? orders.map((o) => {
      const t = String(o.created_at || '');
      const when = t.slice(0, 10) === today ? t.slice(11, 16) : fmtDate(t.slice(0, 10), false).slice(0, 6);
      const state = o.cancelled ? '<span class="adm-state muted">Storniert</span>'
        : o.paid ? '<span class="adm-state ok">' + (o.method === 'stripe' ? 'Karte online' : o.method === 'bar' ? 'Bar' : o.method === 'card' ? 'Karte Kasse' : 'Bezahlt') + '</span>'
          : '<span class="adm-state warn">Reserviert</span>';
      const who = /^(Unknown\s*)+$/.test(o.name || '') || !o.name ? 'Abendkasse' : o.name;
      return '<tr><td class="num" style="text-align:left">' + esc(when) + '</td><td>' + esc(who) + '<span class="adm-sub">' + esc(o.email || '') + '</span></td><td>' + esc(fmtDate(o.valid_date, false)) + '</td><td>' + state + '</td><td class="num">' + num(o.count) + '</td><td class="num">' + money(o.total) + '</td></tr>';
    }).join('') : '<tr class="adm-empty"><td colspan="6">Noch keine Bestellungen.</td></tr>';

    $('dashUpdated').textContent = 'STAND ' + new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  // ---- statistics ---------------------------------------------------------------
  let charts = {};
  inits.stats = () => { if (OV) renderStats(); };
  function renderStats() {
    if (!OV || !OV.daily) return;
    const inc = OV.daily.income_by_date || {}, sal = OV.daily.sales_by_date || {};
    const days = Array.from(new Set(Object.keys(inc).concat(Object.keys(sal)))).sort();
    $('statDays').innerHTML = days.length ? days.slice().reverse().map((d) => '<tr><td>' + esc(fmtDate(d)) + '</td><td class="num">' + num(sal[d] || 0) + '</td><td class="num">' + money(inc[d] || 0) + '</td></tr>').join('')
      : '<tr class="adm-empty"><td colspan="3">Noch keine Verkäufe.</td></tr>';
    const range = days.length ? fmtDate(days[0], false) + ' – ' + fmtDate(days[days.length - 1], false) : '';
    $('chRange1').textContent = range; $('chRange2').textContent = range;
    if (!window.Chart) { setTimeout(renderStats, 300); return; }
    const css = getComputedStyle(document.documentElement);
    const v = (n) => css.getPropertyValue(n).trim();
    const labels = days.map((d) => d.slice(8, 10) + '.' + d.slice(5, 7) + '.');
    const base = (fmt) => ({
      responsive: true, maintainAspectRatio: false, animation: false,
      plugins: { legend: { display: false }, tooltip: {
        backgroundColor: v('--avo-surface-raised'), borderColor: v('--avo-line-strong'), borderWidth: 1, cornerRadius: 6,
        titleColor: v('--avo-text'), bodyColor: v('--avo-text'), titleFont: { family: 'IBM Plex Mono' }, bodyFont: { family: 'IBM Plex Mono' },
        callbacks: { label: (c) => fmt(c.parsed.y) } } },
      scales: {
        x: { grid: { display: false }, border: { color: v('--avo-line') }, ticks: { color: v('--avo-text-muted'), font: { family: 'IBM Plex Mono', size: 11 } } },
        y: { beginAtZero: true, grid: { color: v('--avo-line') }, border: { display: false }, ticks: { color: v('--avo-text-muted'), font: { family: 'IBM Plex Mono', size: 11 }, callback: fmt } },
      },
    });
    const draw = (id, data, color, fmt) => {
      if (charts[id]) charts[id].destroy();
      charts[id] = new Chart($(id), { type: 'bar', data: { labels, datasets: [{ data, backgroundColor: color, borderRadius: 0, maxBarThickness: 36 }] }, options: base(fmt) });
    };
    draw('chIncome', days.map((d) => inc[d] || 0), v('--avo-primary'), (x) => money(x));
    draw('chSales', days.map((d) => sal[d] || 0), v('--avo-coral-700'), (x) => num(x));
  }
  document.addEventListener('avo:theme', () => { if (started.stats) renderStats(); });

  // ---- announcements (Durchsagen) ---------------------------------------------------
  const CAST_LABEL = { info: 'Info', attention: 'Achtung', alert: 'Dringend', success: 'Hinweis' };
  const CAST_ERR = { empty_text: 'Bitte einen Text eingeben.', invalid_category: 'Unbekannte Kategorie.', invalid_duration: 'Ungültige Dauer.', no_targets: 'Bitte mindestens eine Zielgruppe wählen.' };
  let castPresets = [], castTimer = null;
  const hhmm = (sec) => new Date(sec * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

  function castTargets() {
    return [$('castScreens').checked && 'screens', $('castStaff').checked && 'staff'].filter(Boolean);
  }
  async function castSend(text, category, textEn) {
    const targets = castTargets();
    if (!text) { toast(CAST_ERR.empty_text, 'error'); return false; }
    if (!targets.length) { toast(CAST_ERR.no_targets, 'error'); return false; }
    const dur = $('castDur').value;
    const r = await proxy('broadcast_send', { category, text, text_en: textEn || '', targets, duration_min: dur ? Number(dur) : null });
    if (!r._ok) { toast(CAST_ERR[r.message] || r.message || r.error || 'Senden fehlgeschlagen.', 'error'); return false; }
    toast('Durchsage gesendet: ' + text);
    castLoad();
    return true;
  }
  async function castLoad() {
    clearTimeout(castTimer);
    const r = await proxy('broadcast_history');
    if (r._ok) {
      renderCastNow(r.active);
      renderCastHistory(r.history || [], r.now);
      if (!castPresets.length && Array.isArray(r.presets)) { castPresets = r.presets.map((p) => Object.assign({}, p)); renderCastPresets(); }
    }
    castSchedule();
  }
  // Refresh only while the view is open; route() restarts it on return.
  function castSchedule() {
    clearTimeout(castTimer);
    castTimer = setTimeout(() => {
      if (location.hash.slice(1) !== 'broadcast') return;
      if (document.hidden) castSchedule(); else castLoad();
    }, 5000);
  }
  function renderCastNow(b) {
    const box = $('castNowBody');
    if (!b) { box.innerHTML = '<p class="avo-small avo-muted">Keine Durchsage aktiv.</p>'; return; }
    const left = b.expires_in == null ? 'bis beendet' : 'noch ' + (b.expires_in >= 60 ? Math.ceil(b.expires_in / 60) + ' min' : b.expires_in + ' s');
    const to = (b.targets || []).map((t) => (t === 'screens' ? 'Screens' : 'Personal')).join(' + ');
    box.innerHTML = '<div class="adm-castnow__card" data-cat="' + esc(b.category) + '">'
      + '<span class="adm-casttag">' + esc(CAST_LABEL[b.category] || b.category) + '</span>'
      + '<div class="adm-castnow__text">' + esc(b.text) + '</div>'
      + '<div class="adm-castnow__meta"><span class="avo-serial">' + esc(to) + ' · ' + esc(left) + '</span>'
      + '<button type="button" class="avo-btn compact" id="castClear"><span>Beenden</span></button></div></div>';
    $('castClear').addEventListener('click', async (e) => {
      busyBtn(e.currentTarget, true);
      const res = await proxy('broadcast_clear', {});
      if (res._ok) toast('Durchsage beendet.'); else toast('Beenden fehlgeschlagen.', 'error');
      castLoad();
    });
  }
  function renderCastHistory(list, now) {
    $('castHistory').innerHTML = list.length ? list.map((b) => {
      const running = !b.cleared_at && (b.expires_at == null || b.expires_at > now);
      const state = running ? '<span class="adm-state ok">Läuft</span>'
        : b.cleared_at ? '<span class="adm-state muted">Beendet ' + esc(hhmm(b.cleared_at)) + '</span>'
          : '<span class="adm-state muted">Abgelaufen ' + esc(hhmm(b.expires_at)) + '</span>';
      const w = new Date(b.created_at * 1000);
      const iso = w.getFullYear() + '-' + String(w.getMonth() + 1).padStart(2, '0') + '-' + String(w.getDate()).padStart(2, '0');
      const day = iso === today ? '' : fmtDate(iso, false) + ' ';
      return '<tr data-cat="' + esc(b.category) + '"><td class="num" style="text-align:left">' + esc(day + hhmm(b.created_at)) + '<span class="adm-sub">' + esc(b.created_by || '') + '</span></td>'
        + '<td><span class="adm-casttag">' + esc(CAST_LABEL[b.category] || b.category) + '</span><br><span class="adm-casttext">' + esc(b.text) + '</span></td><td>' + state + '</td></tr>';
    }).join('') : '<tr class="adm-empty"><td colspan="3">Noch keine Durchsagen.</td></tr>';
  }
  function renderCastPresets() {
    $('castPresets').innerHTML = castPresets.length ? castPresets.map((p, i) =>
      '<button type="button" class="avo-btn compact" data-cat="' + esc(p.category) + '" data-preset="' + i + '" title="' + esc(p.text) + '"><span>' + esc(p.label) + '</span></button>').join('')
      : '<p class="avo-help">Keine Schnelltasten.</p>';
    const list = $('castPresetList');
    list.innerHTML = castPresets.length ? '<div class="adm-cat adm-cat--head" aria-hidden="true"><span>Knopf</span><span>Kategorie</span><span>Text</span><span></span></div>' : '';
    castPresets.forEach((p, i) => {
      const row = document.createElement('div');
      row.className = 'adm-cat';
      row.innerHTML = '<input class="avo-input" data-k="label" maxlength="40" placeholder="Beschriftung" aria-label="Beschriftung">'
        + '<select class="avo-select" data-k="category" aria-label="Kategorie">' + Object.entries(CAST_LABEL).map(([k, t]) => '<option value="' + k + '">' + t + '</option>').join('') + '</select>'
        + '<input class="avo-input" data-k="text" maxlength="200" placeholder="Text der Durchsage" aria-label="Text">'
        + '<button type="button" class="adm-iconbtn" aria-label="Schnelltaste entfernen">' + icon('x') + '</button>';
      row.querySelectorAll('[data-k]').forEach((el) => {
        el.value = p[el.dataset.k] || (el.dataset.k === 'category' ? 'info' : '');
        el.addEventListener('input', () => { p[el.dataset.k] = el.value; });
      });
      row.querySelector('button').addEventListener('click', () => { castPresets.splice(i, 1); renderCastPresets(); });
      list.append(row);
    });
  }
  inits.broadcast = () => {
    const f = $('castForm');
    $('castText').addEventListener('input', () => { $('castCount').textContent = $('castText').value.length; });
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = f.querySelector('[type=submit]'); busyBtn(btn, true);
      const ok = await castSend($('castText').value.trim(), f.querySelector('[name=castCat]:checked').value, $('castTextEn').value.trim());
      busyBtn(btn, false);
      if (ok) { $('castText').value = ''; $('castTextEn').value = ''; $('castCount').textContent = '0'; }
    });
    $('castPresets').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-preset]');
      if (!b) return;
      const p = castPresets[Number(b.dataset.preset)];
      busyBtn(b, true);
      await castSend(p.text, p.category, '');
      busyBtn(b, false);
    });
    $('castPresetAdd').addEventListener('click', () => {
      castPresets.push({ label: '', category: 'info', text: '' });
      renderCastPresets(); $('castPresetList').querySelector('.adm-cat:last-child input')?.focus();
    });
    $('castPresetSave').addEventListener('click', async (e) => {
      const clean = castPresets.filter((p) => String(p.label || '').trim() && String(p.text || '').trim());
      busyBtn(e.currentTarget, true);
      const r = await proxy('show_edit', { broadcast_presets: clean });
      busyBtn(e.currentTarget, false);
      if (r._ok) { castPresets = clean; renderCastPresets(); toast('Schnelltasten gespeichert.'); }
      else toast(r.message || 'Speichern fehlgeschlagen.', 'error');
    });
    castLoad();
  };

  // ---- event -----------------------------------------------------------------------
  inits.event = () => {
    const f = $('eventForm');
    $('evOrga').value = S.orga_name || ''; $('evTitle').value = S.title || ''; $('evSub').value = S.subtitle || '';
    $('evMail').value = S.contact_email || ''; $('evDomain').value = S.app_domain || '';
    $('evDuration').value = String(S.event_duration_min || 120);
    $('evMethods').value = S.payment_methods || 'both'; $('evLock').checked = !!S.store_lock;
    $('evRemind').checked = !!S.reminder_enabled; $('evRemindDays').value = String(S.reminder_days || 1);
    const syncRemind = () => { $('evRemindDays').disabled = !$('evRemind').checked; };
    $('evRemind').addEventListener('change', syncRemind); syncRemind();
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!$('evOrga').value.trim() || !$('evTitle').value.trim()) { toast('Veranstalter und Titel sind Pflichtfelder.', 'error'); return; }
      const btn = f.querySelector('[type=submit]'); busyBtn(btn, true);
      const body = {
        orga_name: $('evOrga').value.trim(), title: $('evTitle').value.trim(), subtitle: $('evSub').value.trim(),
        contact_email: $('evMail').value.trim(), app_domain: $('evDomain').value.trim(),
        payment_methods: $('evMethods').value, store_lock: $('evLock').checked,
        reminder_enabled: $('evRemind').checked, reminder_days: Number($('evRemindDays').value),
        event_duration_min: Math.min(1440, Math.max(15, Number($('evDuration').value) || 120)),
      };
      const r = await proxy('show_edit', body);
      busyBtn(btn, false);
      if (r._ok) { Object.assign(S, body); renderStoreChip(); toast('Veranstaltung gespeichert.'); }
      else toast(r.message || 'Speichern fehlgeschlagen.', 'error');
    });

    // box-office price categories
    let cats = (S.boxoffice_categories || []).map((c) => Object.assign({}, c));
    const MODES = { minus: 'Abzug in €', percent: 'Rabatt in %', fixed: 'Festpreis in €' };
    const list = $('catList');
    function render() {
      list.innerHTML = cats.length
        ? '<div class="adm-cat adm-cat--head" aria-hidden="true"><span>Name</span><span>Preisregel</span><span>Wert</span><span></span></div>'
        : '<p class="avo-help" style="padding:var(--avo-space-3) 0">Keine Kategorien. An der Kasse gibt es nur „Normal“.</p>';
      cats.forEach((c, i) => {
        const row = document.createElement('div');
        row.className = 'adm-cat';
        row.innerHTML = '<input class="avo-input" data-k="name" maxlength="40" placeholder="z. B. Ermäßigt" aria-label="Name">'
          + '<select class="avo-select" data-k="mode" aria-label="Preisregel">' + Object.entries(MODES).map(([k, t]) => '<option value="' + k + '">' + t + '</option>').join('') + '</select>'
          + '<input class="avo-input" data-k="value" type="number" min="0" step="0.01" aria-label="Wert">'
          + '<button type="button" class="adm-iconbtn" aria-label="Kategorie entfernen">' + icon('x') + '</button>';
        row.querySelector('[data-k=name]').value = c.name || '';
        row.querySelector('[data-k=mode]').value = c.mode || 'minus';
        row.querySelector('[data-k=value]').value = c.value ?? 0;
        row.querySelectorAll('[data-k]').forEach((el) => el.addEventListener('input', () => {
          c[el.dataset.k] = el.dataset.k === 'value' ? (parseFloat(el.value) || 0) : el.value;
        }));
        row.querySelector('button').addEventListener('click', () => { cats.splice(i, 1); render(); });
        list.append(row);
      });
    }
    $('catAdd').addEventListener('click', () => {
      cats.push({ id: 'c' + Date.now().toString(36), name: '', mode: 'minus', value: 0 });
      render(); list.querySelector('.adm-cat:last-child input')?.focus();
    });
    $('catSave').addEventListener('click', async (e) => {
      const clean = cats.filter((c) => String(c.name || '').trim());
      busyBtn(e.currentTarget, true);
      const r = await proxy('show_edit', { boxoffice_categories: clean });
      busyBtn(e.currentTarget, false);
      if (r._ok) { cats = clean; S.boxoffice_categories = clean; render(); toast('Kassen-Kategorien gespeichert.'); }
      else toast(r.message || 'Speichern fehlgeschlagen.', 'error');
    });
    render();
  };

  // ---- dates + locations ----------------------------------------------------------------
  inits.dates = () => {
    renderDays(); renderLocs();
    $('dayNew').addEventListener('click', () => openDay(null));
    $('locNew').addEventListener('click', () => openLoc(null));
  };
  function locOptions(sel) {
    return '<option value="">Kein Ort</option>' + Object.entries(S.locations || {}).map(([id, l]) =>
      '<option value="' + esc(id) + '"' + (id === sel ? ' selected' : '') + '>' + esc(l.name) + '</option>').join('');
  }
  function renderDays() {
    const rows = (S.dates || []).map((d) => {
      const st = dateStats(d.date);
      const loc = S.locations && S.locations[d.location] ? S.locations[d.location].name : '–';
      return '<tr class="' + (d.date < today ? 'adm-past' : '') + '" data-open="' + esc(d.id) + '"><td>' + esc(fmtDate(d.date)) + '</td><td class="num" style="text-align:left">' + esc(d.time) + '</td><td>' + esc(loc) + '</td>'
        + '<td><span class="adm-state ' + (d.seating ? 'ok' : 'muted') + '">' + (d.seating ? 'Platzwahl' : 'Frei') + '</span></td>'
        + '<td class="num">' + money(d.price) + (d.seating ? '<span class="adm-sub">Basis</span>' : '') + '</td>'
        + '<td class="num">' + num(d.tickets) + '</td><td class="num">' + num(d.available) + (st.sold ? '<span class="adm-sub">' + num(st.sold) + ' verkauft</span>' : '') + '</td>'
        + '<td class="adm-rowact"><button type="button" class="adm-iconbtn" data-gl="' + esc(d.date) + '" aria-label="Gästeliste als PDF" title="Gästeliste (PDF)">' + icon('list') + '</button>'
        + '<button type="button" class="adm-iconbtn" aria-label="Termin bearbeiten">' + icon('edit') + '</button></td></tr>';
    });
    $('dayRows').innerHTML = rows.join('') || '<tr class="adm-empty"><td colspan="8">Noch keine Termine.</td></tr>';
    $('dayRows').querySelectorAll('tr[data-open]').forEach((tr) => tr.addEventListener('click', (e) => {
      const gl = e.target.closest('[data-gl]');
      if (gl) guestList(gl.dataset.gl, gl); else openDay(tr.dataset.open);
    }));
  }
  function renderLocs() {
    const count = {};
    (S.dates || []).forEach((d) => { count[d.location] = (count[d.location] || 0) + 1; });
    const rows = Object.entries(S.locations || {}).map(([id, l]) =>
      '<tr><td>' + esc(l.name) + '</td><td class="avo-muted">' + esc(l.address || '–') + '</td>'
      + '<td><a class="avo-link" href="seatmap.php?loc=' + encodeURIComponent(id) + '">Saalplan bearbeiten</a></td>'
      + '<td class="num">' + num(count[id] || 0) + '</td>'
      + '<td class="adm-rowact"><button type="button" class="adm-iconbtn" data-loc="' + esc(id) + '" aria-label="Ort bearbeiten">' + icon('edit') + '</button></td></tr>');
    $('locRows').innerHTML = rows.join('') || '<tr class="adm-empty"><td colspan="5">Noch keine Orte.</td></tr>';
    $('locRows').querySelectorAll('[data-loc]').forEach((b) => b.addEventListener('click', () => openLoc(b.dataset.loc)));
  }

  let editDay = null;
  function openDay(id) {
    editDay = id ? (S.dates || []).find((d) => d.id === id) : null;
    const d = editDay || { date: '', time: '19:30', tickets: 100, price: 15, location: '', seating: false };
    $('dayDlgTitle').textContent = editDay ? fmtDate(d.date) : 'Neuer Termin';
    $('dDate').value = d.date; $('dTime').value = d.time; $('dCap').value = d.tickets; $('dPrice').value = Number(d.price).toFixed(2);
    $('dLoc').innerHTML = locOptions(d.location); $('dSeat').checked = !!d.seating;
    const st = editDay ? dateStats(d.date) : null;
    $('dInfo').textContent = editDay ? num(st.sold) + ' verkauft · ' + num(d.available) + ' frei' + (st.sold ? ' · Datum und Platzwahl sind gesperrt, weil schon Tickets existieren.' : '') : '';
    $('dDate').disabled = !!(st && st.sold); $('dSeat').disabled = !!(st && st.sold);
    $('dDel').hidden = !editDay;
    $('dErr').hidden = true;
    syncSeat();
    $('dayDlg').showModal();
  }
  function syncSeat() {
    const seated = $('dSeat').checked;
    $('dCapField').hidden = seated;
    if (seated && !$('dLoc').value) { $('dErr').textContent = 'Für Platzwahl einen Ort mit Saalplan wählen.'; $('dErr').hidden = false; }
    else $('dErr').hidden = true;
  }
  $('dSeat').addEventListener('change', syncSeat);
  $('dLoc').addEventListener('change', syncSeat);
  $('dayForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      date: $('dDate').value, time: $('dTime').value, tickets: parseInt($('dCap').value, 10) || 0,
      price: parseFloat($('dPrice').value) || 0, location: $('dLoc').value, seating: $('dSeat').checked,
    };
    if (!body.date || !body.time) { $('dErr').textContent = 'Datum und Beginn sind Pflichtfelder.'; $('dErr').hidden = false; return; }
    if (body.seating && !body.location) { syncSeat(); return; }
    const btn = $('dSave'); busyBtn(btn, true);
    const r = editDay ? await action('update_day', Object.assign({ dateId: editDay.id }, body)) : await action('add_day', body);
    busyBtn(btn, false);
    if (!r._ok) { $('dErr').textContent = r.message || 'Speichern fehlgeschlagen.'; $('dErr').hidden = false; return; }
    if (editDay) {
      if (!body.seating) editDay.available = Math.max(0, editDay.available + (body.tickets - editDay.tickets));
      Object.assign(editDay, body);
    } else {
      S.dates.push(Object.assign({ id: r.dateId, available: body.tickets }, body));
      S.dates.sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time));
    }
    $('dayDlg').close();
    toast(editDay ? 'Termin gespeichert.' : 'Termin angelegt.');
    renderDays(); renderLocs(); renderDashboard();
    if (body.seating) setTimeout(() => location.reload(), 600); // seat-map capacity comes from the server
  });
  $('dDel').addEventListener('click', async () => {
    if (!editDay) return;
    if (!(await confirmBox('Termin löschen?', fmtDate(editDay.date) + ' wird entfernt. Das geht nur, solange es keine Tickets gibt.', 'Löschen'))) return;
    const r = await action('delete_day', { dateId: editDay.id });
    if (!r._ok) { $('dErr').textContent = r.message || 'Löschen fehlgeschlagen.'; $('dErr').hidden = false; return; }
    S.dates = S.dates.filter((d) => d !== editDay);
    $('dayDlg').close(); toast('Termin gelöscht.'); renderDays(); renderLocs(); renderDashboard();
  });

  let editLoc = null;
  function openLoc(id) {
    editLoc = id;
    const l = id ? S.locations[id] : { name: '', address: '' };
    $('locDlgTitle').textContent = id ? l.name : 'Neuer Ort';
    $('lName').value = l.name || ''; $('lAddr').value = l.address || '';
    $('lDel').hidden = !id; $('lErr').hidden = true;
    $('locDlg').showModal();
  }
  $('locForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('lName').value.trim(), address = $('lAddr').value.trim();
    if (!name) { $('lErr').textContent = 'Name ist ein Pflichtfeld.'; $('lErr').hidden = false; return; }
    const r = editLoc ? await action('update_location', { locationId: editLoc, name, address }) : await action('add_location', { name, address });
    if (!r._ok) { $('lErr').textContent = r.message || 'Speichern fehlgeschlagen.'; $('lErr').hidden = false; return; }
    S.locations = S.locations || {};
    S.locations[editLoc || r.locationId] = { name, address };
    $('locDlg').close(); toast('Ort gespeichert.'); renderLocs(); renderDays();
  });
  $('lDel').addEventListener('click', async () => {
    if (!editLoc) return;
    if (!(await confirmBox('Ort löschen?', S.locations[editLoc].name + ' wird entfernt. Termine an diesem Ort verlieren ihre Ortsangabe.', 'Löschen'))) return;
    const r = await action('delete_location', { locationId: editLoc });
    if (!r._ok) { $('lErr').textContent = r.message || 'Löschen fehlgeschlagen.'; $('lErr').hidden = false; return; }
    delete S.locations[editLoc];
    (S.dates || []).forEach((d) => { if (d.location === editLoc) d.location = ''; });
    $('locDlg').close(); toast('Ort gelöscht.'); renderLocs(); renderDays();
  });

  // ---- images ---------------------------------------------------------------------------
  inits.images = () => {
    const load = () => document.querySelectorAll('[data-preview]').forEach((img) => {
      img.hidden = false;
      img.onerror = () => { img.hidden = true; };
      img.src = (A.imageBase || '') + '/api/image/get/' + img.dataset.preview + '.png?t=' + Date.now();
    });
    load();
    document.querySelectorAll('[data-upload]').forEach((inp) => inp.addEventListener('change', async () => {
      const file = inp.files[0];
      if (!file) return;
      if (file.size > 15 * 1024 * 1024) { toast('Die Datei ist größer als 15 MB.', 'error'); return; }
      const fd = new FormData(); fd.append('file', file); fd.append('type', inp.dataset.upload); fd.append('csrf_token', A.csrf);
      const r = await action('upload_image', fd, true);
      inp.value = '';
      if (r._ok) { toast((inp.dataset.upload === 'logo' ? 'Logo' : 'Banner') + ' hochgeladen.'); load(); }
      else toast(r.message || 'Upload fehlgeschlagen.', 'error');
    }));
  };

  // ---- screens ----------------------------------------------------------------------------
  const ICONS = ['fa-smile', 'fa-heart', 'fa-ticket', 'fa-theater-masks', 'fa-users', 'fa-star', 'fa-music', 'fa-hand-peace', 'fa-fire', 'fa-gift', 'fa-microphone', 'fa-camera'];
  const ANIMS = { 'none': 'Keine', 'bounce 1s infinite': 'Hüpfen', 'pulse 1s infinite': 'Pulsieren', 'wobble 1s infinite': 'Wackeln', 'laugh 0.5s infinite': 'Lachen' };
  const DEFAULT_SLIDES = [
    { id: 'slide_1', icon: 'fa-smile', icon_animation: 'laugh 0.5s infinite', text_en: 'Welcome to\n{orga_name}', text_de: 'Willkommen bei der\n{orga_name}', cast: [] },
    { id: 'slide_2', icon: 'fa-theater-masks', icon_animation: 'bounce 1s infinite', text_en: '{show_title}\n{show_subtitle}', text_de: '{show_title}\n{show_subtitle}', cast: [] },
    { id: 'slide_3', icon: 'fa-heart', icon_animation: 'pulse 1s infinite', text_en: 'We are so happy to see you here!', text_de: 'Wir freuen uns sehr, dich hier zu sehen!', cast: [] },
    { id: 'slide_4', icon: 'fa-ticket', icon_animation: 'wobble 1s infinite', text_en: 'To ensure a quick and smooth check-in,\nplease have your ticket ready before entering.', text_de: 'Um einen zügigen Check-in zu ermöglichen,\nhalte bitte dein Ticket vor dem Einlass bereit.', cast: [] },
  ];
  let SC = null, sel = 0;
  // ---- live dashboard link (display token) -------------------------------------------
  function renderDisplay(token) {
    const url = token ? new URL('../screens/live.php?token=' + encodeURIComponent(token), location.href).href : '';
    $('dispUrl').value = url;
    $('dispCopy').disabled = !url;
    $('dispRevoke').disabled = !url;
    $('dispOpen').href = url || '../screens/live.php';
  }
  async function initDisplay() {
    const r = await proxy('display_token');
    if (r._ok) renderDisplay(r.token);
    $('dispCopy').addEventListener('click', async () => {
      try { await navigator.clipboard.writeText($('dispUrl').value); toast('Link kopiert.'); }
      catch (e) { $('dispUrl').select(); toast('Link markiert, bitte mit Strg+C kopieren.', 'warn'); }
    });
    $('dispNew').addEventListener('click', async (e) => {
      if ($('dispUrl').value && !(await confirmBox('Neuen Link erzeugen?', 'Der bisherige Link funktioniert danach nicht mehr. Offene Monitore zeigen „Kein Zugriff“, bis sie den neuen Link bekommen.', 'Neu erzeugen'))) return;
      busyBtn(e.currentTarget, true);
      const res = await proxy('display_token', { action: 'new' });
      busyBtn(e.currentTarget, false);
      if (res._ok) { renderDisplay(res.token); toast('Neuer Link erzeugt.'); } else toast('Erzeugen fehlgeschlagen.', 'error');
    });
    $('dispRevoke').addEventListener('click', async (e) => {
      if (!(await confirmBox('Link zurückziehen?', 'Monitore mit diesem Link zeigen danach „Kein Zugriff“.', 'Zurückziehen'))) return;
      busyBtn(e.currentTarget, true);
      const res = await proxy('display_token', { action: 'revoke' });
      busyBtn(e.currentTarget, false);
      if (res._ok) { renderDisplay(null); toast('Link zurückgezogen.'); } else toast('Zurückziehen fehlgeschlagen.', 'error');
    });
  }

  inits.screens = () => {
    initDisplay();
    SC = S.screens && Array.isArray(S.screens.slides) ? JSON.parse(JSON.stringify(S.screens)) : { language_mode: 'both', slides: JSON.parse(JSON.stringify(DEFAULT_SLIDES)) };
    $('scrLang').value = SC.language_mode || 'both';
    $('scrLang').addEventListener('change', () => { SC.language_mode = $('scrLang').value; });
    $('scrAdd').addEventListener('click', () => {
      SC.slides.push({ id: 'slide_' + Date.now(), icon: 'fa-star', icon_animation: 'none', text_en: '', text_de: '', cast: [] });
      sel = SC.slides.length - 1; renderSlides();
    });
    $('scrSave').addEventListener('click', async (e) => {
      busyBtn(e.currentTarget, true);
      const r = await action('save_screens', { screens: SC });
      busyBtn(e.currentTarget, false);
      if (r._ok) { S.screens = JSON.parse(JSON.stringify(SC)); toast('Screens gespeichert.'); } else toast(r.message || 'Speichern fehlgeschlagen.', 'error');
    });
    renderSlides();
  };
  function renderSlides() {
    const ul = $('slideList');
    ul.innerHTML = SC.slides.map((s, i) => '<li role="option" aria-selected="' + (i === sel) + '" data-i="' + i + '"><span class="avo-serial">' + String(i + 1).padStart(2, '0') + '</span><span>' + esc((s.text_de || s.text_en || 'Leere Folie').split('\n')[0]) + '</span>'
      + '<button type="button" class="adm-iconbtn" data-mv="-1" aria-label="Nach oben"' + (i === 0 ? ' disabled' : '') + '>' + icon('up') + '</button>'
      + '<button type="button" class="adm-iconbtn" data-mv="1" aria-label="Nach unten"' + (i === SC.slides.length - 1 ? ' disabled' : '') + '>' + icon('down') + '</button></li>').join('');
    ul.querySelectorAll('li').forEach((li) => li.addEventListener('click', (e) => {
      const i = +li.dataset.i, mv = e.target.closest('[data-mv]');
      if (mv) {
        const j = i + (+mv.dataset.mv);
        [SC.slides[i], SC.slides[j]] = [SC.slides[j], SC.slides[i]];
        sel = j;
      } else sel = i;
      renderSlides();
    }));
    renderSlide();
  }
  function renderSlide() {
    const box = $('slideEditor'), s = SC.slides[sel];
    if (!s) { box.innerHTML = '<div class="avo-empty"><div class="avo-kicker"><span>Keine Folie</span></div><p class="avo-small">Lege links eine Folie an.</p></div>'; return; }
    s.cast = s.cast || [];
    box.innerHTML = '<h2 class="avo-title">Folie ' + (sel + 1) + '</h2>'
      + '<div class="avo-grid c2"><div class="avo-field"><label class="avo-label" for="sIcon">Symbol</label><select class="avo-select" id="sIcon">' + ICONS.map((k) => '<option value="' + k + '">' + k.replace('fa-', '') + '</option>').join('') + '</select></div>'
      + '<div class="avo-field"><label class="avo-label" for="sAnim">Animation</label><select class="avo-select" id="sAnim">' + Object.entries(ANIMS).map(([k, t]) => '<option value="' + esc(k) + '">' + t + '</option>').join('') + '</select></div></div>'
      + '<div class="avo-field"><label class="avo-label" for="sDe">Text Deutsch</label><textarea class="avo-textarea" id="sDe" rows="3"></textarea></div>'
      + '<div class="avo-field"><label class="avo-label" for="sEn">Text Englisch</label><textarea class="avo-textarea" id="sEn" rows="3"></textarea><p class="avo-help">Zeilenumbruch = neue Zeile auf dem Bildschirm.</p></div>'
      + '<label class="avo-choice adm-switchrow"><input type="checkbox" class="avo-switch" id="sCastOn"' + (s.cast.length ? ' checked' : '') + '><span><b>Besetzung zeigen</b><span class="avo-help">Bis zu 6 Personen pro Folie; mehr werden automatisch auf weitere Folien verteilt.</span></span></label>'
      + '<div class="adm-cast" id="sCast"' + (s.cast.length ? '' : ' hidden') + '></div>'
      + '<div class="adm-actions"><button type="button" class="avo-btn compact" id="sCastAdd"' + (s.cast.length ? '' : ' hidden') + '><span>Person hinzufügen</span></button><span class="adm-bar__sp"></span>'
      + '<button type="button" class="avo-btn compact adm-btn-danger" id="sDel"><span>Folie löschen</span></button></div>';
    $('sIcon').value = s.icon; $('sAnim').value = s.icon_animation || 'none';
    $('sDe').value = s.text_de || ''; $('sEn').value = s.text_en || '';
    $('sIcon').onchange = () => { s.icon = $('sIcon').value; };
    $('sAnim').onchange = () => { s.icon_animation = $('sAnim').value; };
    $('sDe').oninput = () => { s.text_de = $('sDe').value; const li = $('slideList').children[sel]; if (li) li.children[1].textContent = (s.text_de || s.text_en || 'Leere Folie').split('\n')[0]; };
    $('sEn').oninput = () => { s.text_en = $('sEn').value; };
    $('sCastOn').onchange = () => { s.cast = $('sCastOn').checked ? (s.cast.length ? s.cast : [{ name: '', role: '', image: '' }]) : []; renderSlide(); };
    $('sCastAdd').onclick = () => { s.cast.push({ name: '', role: '', image: '' }); renderSlide(); };
    $('sDel').onclick = async () => {
      if (SC.slides.length <= 1) { toast('Mindestens eine Folie muss bleiben.', 'warn'); return; }
      if (!(await confirmBox('Folie löschen?', 'Folie ' + (sel + 1) + ' wird entfernt. Speichern nicht vergessen.', 'Löschen'))) return;
      SC.slides.splice(sel, 1); sel = Math.max(0, sel - 1); renderSlides();
    };
    const cast = $('sCast');
    s.cast.forEach((m, i) => {
      const row = document.createElement('div');
      row.className = 'adm-cast__row';
      row.innerHTML = (m.image ? '<img class="avo-avatar" alt="" src="' + esc((A.imageBase || '') + '/api/show/cast/image/' + encodeURIComponent(m.image)) + '">' : '<span class="avo-avatar" style="display:inline-flex;align-items:center;justify-content:center;color:var(--avo-text-muted)">' + icon('user') + '</span>')
        + '<input class="avo-input" data-k="name" placeholder="Name" aria-label="Name"><input class="avo-input" data-k="role" placeholder="Rolle (optional)" aria-label="Rolle">'
        + '<label class="avo-btn compact">' + icon('upload') + '<span>Bild</span><input type="file" accept="image/*" hidden></label>'
        + '<button type="button" class="adm-iconbtn" aria-label="Person entfernen">' + icon('x') + '</button>';
      row.querySelector('[data-k=name]').value = m.name || '';
      row.querySelector('[data-k=role]').value = m.role || '';
      row.querySelectorAll('[data-k]').forEach((el) => el.addEventListener('input', () => { m[el.dataset.k] = el.value; }));
      row.querySelector('input[type=file]').addEventListener('change', async (e) => {
        const f = e.target.files[0]; if (!f) return;
        const fd = new FormData(); fd.append('file', f); fd.append('csrf_token', A.csrf);
        const r = await action('upload_cast_image', fd, true);
        if (r._ok && r.filename) { m.image = r.filename; renderSlide(); toast('Bild hochgeladen. Speichern nicht vergessen.'); }
        else toast(r.message || 'Upload fehlgeschlagen.', 'error');
      });
      row.querySelector('.adm-iconbtn').addEventListener('click', () => { s.cast.splice(i, 1); renderSlide(); });
      cast.append(row);
    });
  }

  // ---- payments --------------------------------------------------------------------------
  inits.payments = () => {
    const st = A.stripe || {};
    function paint() {
      $('payPub').value = st.publishable_key || '';
      $('paySecret').placeholder = st.secret_set ? 'gesetzt · leer lassen zum Behalten' : 'sk_live_… oder sk_test_…';
      $('paySecretHelp').textContent = st.secret_set ? 'Ein Secret Key ist hinterlegt. Nur ausfüllen, um ihn zu ersetzen.' : 'Ohne Secret Key ist Kartenzahlung im Shop aus.';
      $('payHook').placeholder = st.webhook_set ? 'gesetzt · leer lassen zum Behalten' : 'whsec_… (optional)';
      $('payHookHelp').textContent = 'Optional.';
      const mode = !st.publishable_key || !st.secret_set ? ['quiet', 'NICHT EINGERICHTET'] : st.live ? ['success', 'LIVE'] : ['warning', 'TESTMODUS'];
      $('payMode').innerHTML = '<span class="avo-tag ' + mode[0] + '">' + mode[1] + '</span>';
    }
    paint();
    $('payForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const pub = $('payPub').value.trim(), sec = $('paySecret').value.trim(), hook = $('payHook').value.trim();
      if (pub && !/^pk_(live|test)_/.test(pub)) { toast('Der Publishable Key beginnt mit pk_live_ oder pk_test_.', 'error'); return; }
      if (sec && !/^(sk|rk)_(live|test)_/.test(sec)) { toast('Der Secret Key beginnt mit sk_live_ oder sk_test_.', 'error'); return; }
      const body = { publishable_key: pub };
      if (sec) body.secret_key = sec;
      if (hook) body.webhook_secret = hook;
      const btn = e.submitter; busyBtn(btn, true);
      const r = await action('save_payment_settings', body);
      busyBtn(btn, false);
      if (!r._ok) { toast(r.message || 'Speichern fehlgeschlagen.', 'error'); return; }
      st.publishable_key = pub; st.live = pub.indexOf('pk_live_') === 0;
      if (sec) st.secret_set = true; if (hook) st.webhook_set = true;
      $('paySecret').value = ''; $('payHook').value = '';
      paint(); toast('Zahlungseinstellungen gespeichert.');
    });
  };

  // ---- accounts ----------------------------------------------------------------------------
  inits.accounts = () => {
    loadAccounts();
    $('accForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const username = $('accUser').value.trim(), password = $('accPw').value;
      if (!username || password.length < 6) { toast('Benutzername und ein Passwort mit mindestens 6 Zeichen angeben.', 'error'); return; }
      const btn = e.submitter; busyBtn(btn, true);
      const r = await proxy('users_create', { username, password, can_admin: $('accAdmin').checked, can_ticketflow: $('accTf').checked, can_handheld: $('accHh').checked });
      busyBtn(btn, false);
      if (r._ok) { e.target.reset(); $('accTf').checked = true; toast('Konto „' + username + '“ angelegt.'); loadAccounts(); }
      else toast(r.message || r.error || 'Anlegen fehlgeschlagen.', 'error');
    });
  };
  async function loadAccounts() {
    const r = await proxy('users_list');
    const body = $('accRows');
    const users = (r && r.users) || [];
    if (!users.length) { body.innerHTML = '<tr class="adm-empty"><td colspan="5">' + (r._ok ? 'Keine Konten.' : 'Konten konnten nicht geladen werden.') + '</td></tr>'; return; }
    body.innerHTML = '';
    users.forEach((u) => {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + esc(u.username) + '</td>'
        + ['can_admin', 'can_ticketflow', 'can_handheld'].map((p) => '<td><input type="checkbox" class="avo-check" data-perm="' + p + '"' + (u[p] ? ' checked' : '') + ' aria-label="' + p + '"></td>').join('')
        + '<td class="adm-rowact"><button type="button" class="adm-iconbtn" aria-label="Konto löschen">' + icon('trash') + '</button></td>';
      tr.querySelectorAll('[data-perm]').forEach((cb) => cb.addEventListener('change', async () => {
        const get = (p) => tr.querySelector('[data-perm="' + p + '"]').checked;
        const res = await proxy('users_update', { username: u.username, can_admin: get('can_admin'), can_ticketflow: get('can_ticketflow'), can_handheld: get('can_handheld') });
        if (res._ok) toast('Zugänge von „' + u.username + '“ geändert.'); else { toast(res.message || res.error || 'Ändern fehlgeschlagen.', 'error'); loadAccounts(); }
      }));
      tr.querySelector('.adm-iconbtn').addEventListener('click', async () => {
        if (!(await confirmBox('Konto löschen?', '„' + u.username + '“ kann sich danach nicht mehr anmelden.', 'Löschen'))) return;
        const res = await proxy('users_delete', { username: u.username });
        if (res._ok) { toast('Konto gelöscht.'); loadAccounts(); } else toast(res.message || res.error || 'Löschen fehlgeschlagen.', 'error');
      });
      body.append(tr);
    });
  }

  // ---- downloads (backups, exports) ----------------------------------------------------------
  async function download(url, fallback) {
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    if (res.status === 401) { location.href = 'login.php'; return; }
    if (!res.ok) {
      const j = await res.json().catch(() => ({}));
      const err = new Error(j.message || 'HTTP ' + res.status); err.code = j.error; throw err;
    }
    const blob = await res.blob();
    const m = (res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = m ? m[1] : fallback;
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  // ---- export ------------------------------------------------------------------------------
  const EX_HELP = {
    tickets: 'Name, E-Mail, Typ, Termin, Platz, Zahlart, Preis, Verkäufer, Kauf- und Einlasszeit, Status.',
    attempts: 'Jeder Scan am Einlass mit Zeit und Ergebnis (Einlass, bereits benutzt, nicht bezahlt, falscher Tag).',
    revenue: 'Eine Zeile pro Tag: Verkaufsstatistik, Einnahmen nach Zahlart (bar, Karte, online), Erstattungen und netto. Gilt für alle Termine.',
  };
  async function guestList(date, btn) {
    busyBtn(btn, true);
    try { await download('export.php?' + new URLSearchParams({ kind: 'guestlist', date }), 'qrgate-gaesteliste-' + date + '.pdf'); toast('Gästeliste heruntergeladen.'); }
    catch (err) { toast('Gästeliste fehlgeschlagen: ' + err.message, 'error'); }
    busyBtn(btn, false);
  }
  inits.export = () => {
    $('exDate').innerHTML = '<option value="">Alle Termine</option>'
      + (S.dates || []).map((d) => '<option value="' + esc(d.date) + '">' + esc(fmtDate(d.date)) + ' · ' + esc(d.time) + '</option>').join('')
      + '<option value="Unlimited">Ohne Termin (Admin/VIP)</option>';
    const sync = () => {
      const k = $('exKind').value;
      $('exHelp').textContent = EX_HELP[k];
      $('exDate').disabled = k === 'revenue';
      $('exCancelRow').hidden = k !== 'tickets';
    };
    $('exKind').addEventListener('change', sync); sync();
    const upcoming = (S.dates || []).find((d) => d.date >= today);
    $('glDate').innerHTML = (S.dates || []).map((d) => '<option value="' + esc(d.date) + '">' + esc(fmtDate(d.date)) + ' · ' + esc(d.time) + '</option>').join('')
      || '<option value="">Noch keine Termine</option>';
    if (upcoming) $('glDate').value = upcoming.date;
    $('glForm').addEventListener('submit', (e) => {
      e.preventDefault();
      if ($('glDate').value) guestList($('glDate').value, e.submitter);
    });
    $('exForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const k = $('exKind').value;
      const q = new URLSearchParams({ kind: k, format: $('exFormat').value });
      if (k !== 'revenue' && $('exDate').value) q.set('date', $('exDate').value);
      if (k === 'tickets' && $('exCancel').checked) q.set('include_cancelled', '1');
      const btn = e.submitter; busyBtn(btn, true);
      try { await download('export.php?' + q, 'qrgate-' + k + '.csv'); toast('Export heruntergeladen.'); }
      catch (err) { toast('Export fehlgeschlagen: ' + err.message, 'error'); }
      busyBtn(btn, false);
    });
  };

  // ---- system ------------------------------------------------------------------------------
  const BK_KIND = { auto: 'Automatisch', manual: 'Manuell', 'pre-wipe': 'Vor „Daten löschen“', 'pre-reinstall': 'Vor „Neu installieren“', 'pre-factory-reset': 'Vor „Werkseinstellungen“' };
  const BK_ERR = {
    no_space: 'Auf dem Server ist nicht genug Speicherplatz frei.',
    corrupt: 'Die Sicherung war fehlerhaft und wurde verworfen.',
    failed: 'Die Sicherung konnte nicht geschrieben werden.',
    not_found: 'Diese Sicherung gibt es nicht mehr.',
    backup_failed: 'Abgebrochen: Die Sicherung vorher ist fehlgeschlagen. Es wurde nichts geändert.',
  };
  const bytes = (n) => n < 1024 ? n + ' B' : n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
  function ago(sec) {
    if (sec < 90) return 'gerade eben';
    if (sec < 90 * 60) return 'vor ' + Math.round(sec / 60) + ' Minuten';
    if (sec < 36 * 3600) return 'vor ' + Math.round(sec / 3600) + ' Stunden';
    return 'vor ' + Math.round(sec / 86400) + ' Tagen';
  }
  let bkFilled = false;
  async function bkLoad() {
    const r = await proxy('backups_list');
    if (!r._ok) {
      $('bkLast').textContent = 'Sicherungen konnten nicht geladen werden.';
      $('bkRows').innerHTML = '<tr class="adm-empty"><td colspan="4">Backend nicht erreichbar.</td></tr>';
      return;
    }
    const st = r.settings || {};
    if (!bkFilled) {
      bkFilled = true;
      $('bkOn').checked = !!st.enabled; $('bkInt').value = String(st.interval_hours || 24);
      $('bkKeep').value = st.keep || 14; $('bkEvent').checked = !!st.event_hourly;
    }
    const last = $('bkLast');
    const stale = r.last_age_s == null || r.last_age_s > 2 * (r.effective_interval_hours || 24) * 3600;
    last.textContent = !st.enabled ? 'Automatische Sicherung ist aus.' + (r.last_age_s != null ? ' Zuletzt gesichert ' + ago(r.last_age_s) + '.' : '')
      : r.last_age_s == null ? 'Noch keine Sicherung vorhanden.' : 'Zuletzt gesichert ' + ago(r.last_age_s) + '.';
    last.style.color = !st.enabled ? 'var(--avo-warning)' : stale ? 'var(--avo-error)' : '';
    const rows = (r.backups || []).map((b) => '<tr><td>' + esc(fmtDate(b.created.slice(0, 10))) + ' · ' + esc(b.created.slice(11, 16)) + '<span class="adm-sub">' + esc(ago(b.age_s)) + '</span></td>'
      + '<td>' + esc(BK_KIND[b.kind] || b.kind) + '</td><td class="num">' + bytes(b.size) + '</td>'
      + '<td class="adm-rowact"><button type="button" class="adm-iconbtn" data-dl="' + esc(b.name) + '" aria-label="Sicherung herunterladen">' + icon('dl') + '</button>'
      + '<button type="button" class="adm-iconbtn" data-rm="' + esc(b.name) + '" aria-label="Sicherung löschen">' + icon('trash') + '</button></td></tr>');
    $('bkRows').innerHTML = rows.join('') || '<tr class="adm-empty"><td colspan="4">Noch keine Sicherungen.</td></tr>';
    $('bkRows').querySelectorAll('[data-dl]').forEach((b) => b.addEventListener('click', async () => {
      busyBtn(b, true);
      try { await download('backup.php?name=' + encodeURIComponent(b.dataset.dl), b.dataset.dl); }
      catch (err) { toast(BK_ERR[err.code] || 'Download fehlgeschlagen: ' + err.message, 'error'); }
      busyBtn(b, false);
    }));
    $('bkRows').querySelectorAll('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
      if (!(await confirmBox('Sicherung löschen?', b.dataset.rm + ' wird endgültig gelöscht.', 'Löschen'))) return;
      const res = await proxy('backups_delete', { name: b.dataset.rm, confirm: true });
      if (res._ok) toast('Sicherung gelöscht.'); else toast(BK_ERR[res.error] || res.message || 'Löschen fehlgeschlagen.', 'error');
      bkLoad();
    }));
  }

  inits.system = () => {
    bkLoad();
    $('bkRun').addEventListener('click', async (e) => {
      const btn = e.currentTarget; busyBtn(btn, true);
      const r = await proxy('backups_run', {});
      busyBtn(btn, false);
      if (r._ok) toast('Sicherung angelegt.'); else toast(BK_ERR[r.error] || r.message || 'Sicherung fehlgeschlagen.', 'error');
      bkLoad();
    });
    $('bkSave').addEventListener('click', async (e) => {
      const keep = parseInt($('bkKeep').value, 10);
      if (!(keep >= 1 && keep <= 100)) { toast('Aufbewahren: eine Zahl zwischen 1 und 100.', 'error'); return; }
      const btn = e.currentTarget; busyBtn(btn, true);
      const r = await proxy('show_edit', {
        backup_enabled: $('bkOn').checked, backup_interval_hours: Number($('bkInt').value),
        backup_keep: keep, backup_event_hourly: $('bkEvent').checked,
      });
      busyBtn(btn, false);
      if (r._ok) { toast('Backup-Einstellungen gespeichert. Die Aufbewahrung greift bei der nächsten Sicherung.'); bkLoad(); }
      else toast(r.message || 'Speichern fehlgeschlagen.', 'error');
    });
    $('backupBtn').addEventListener('click', async (e) => {
      const btn = e.currentTarget; busyBtn(btn, true);
      try { await download('backup.php', 'qrgate-backup.db'); toast('Backup heruntergeladen.'); }
      catch (err) { toast('Backup fehlgeschlagen: ' + err.message, 'error'); }
      busyBtn(btn, false);
    });
    document.querySelectorAll('[data-danger]').forEach((b) => b.addEventListener('click', async () => {
      const ok = await confirmBox(b.dataset.label + '?', 'Das lässt sich nicht rückgängig machen. Vorher wird automatisch gesichert.', b.dataset.label, b.dataset.word);
      if (!ok) return;
      busyBtn(b, true);
      const r = await action(b.dataset.danger, {});
      busyBtn(b, false);
      if (!r._ok) { toast(BK_ERR[r.error] || r.message || 'Fehlgeschlagen.', 'error'); bkLoad(); return; }
      toast(r.message || 'Erledigt.');
      if (b.dataset.danger !== 'wipe_data') setTimeout(() => { location.href = '/install'; }, 2000);
      else setTimeout(() => location.reload(), 1500);
    }));
  };

  // ---- boot ------------------------------------------------------------------------------------
  renderStoreChip();
  inits.dashboard = () => {};
  route();
})();
