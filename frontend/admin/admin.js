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
  const TITLES = { dashboard: 'Dashboard', stats: 'Statistik', event: 'Veranstaltung', dates: 'Termine & Orte', images: 'Bilder', screens: 'Screens', payments: 'Zahlung', accounts: 'Konten', system: 'Wartung' };
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

  // ---- event -----------------------------------------------------------------------
  inits.event = () => {
    const f = $('eventForm');
    $('evOrga').value = S.orga_name || ''; $('evTitle').value = S.title || ''; $('evSub').value = S.subtitle || '';
    $('evMail').value = S.contact_email || ''; $('evDomain').value = S.app_domain || '';
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
        + '<td class="adm-rowact"><button type="button" class="adm-iconbtn" aria-label="Termin bearbeiten">' + icon('edit') + '</button></td></tr>';
    });
    $('dayRows').innerHTML = rows.join('') || '<tr class="adm-empty"><td colspan="8">Noch keine Termine.</td></tr>';
    $('dayRows').querySelectorAll('tr[data-open]').forEach((tr) => tr.addEventListener('click', () => openDay(tr.dataset.open)));
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
  inits.screens = () => {
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

  // ---- system ------------------------------------------------------------------------------
  inits.system = () => {
    $('backupBtn').addEventListener('click', async (e) => {
      const btn = e.currentTarget; busyBtn(btn, true);
      try {
        const res = await fetch('backup.php', { credentials: 'same-origin' });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'HTTP ' + res.status);
        const blob = await res.blob();
        const m = (res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = m ? m[1] : 'qrgate-backup.db';
        document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(a.href);
        toast('Backup heruntergeladen.');
      } catch (err) { toast('Backup fehlgeschlagen: ' + err.message, 'error'); }
      busyBtn(btn, false);
    });
    document.querySelectorAll('[data-danger]').forEach((b) => b.addEventListener('click', async () => {
      const ok = await confirmBox(b.dataset.label + '?', 'Das lässt sich nicht rückgängig machen.', b.dataset.label, b.dataset.word);
      if (!ok) return;
      const r = await action(b.dataset.danger, {});
      if (!r._ok) { toast(r.message || 'Fehlgeschlagen.', 'error'); return; }
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
