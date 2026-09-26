/* QrGate ticket shop · checkout
 *
 * Two steps in one dialog:
 *   1  tickets   quantity (general admission) or seats (seat map)
 *   2  details   name, e-mail, payment
 *
 * "Continue" on step 1 reserves the tickets on the server (checkout.php →
 * start). From then on a timer shows how long they are held. Card payments
 * are only authorised in the browser; checkout.php → complete writes the
 * tickets and only then captures the payment, so nobody pays for a ticket
 * that is gone.
 */
(function () {
  'use strict';

  const QG = window.QG || {};
  const T = QG.t || {};
  const $ = (id) => document.getElementById(id);
  const dlg = $('co');
  if (!dlg) return;

  const SEAT_R = 12;
  const MAX = QG.maxPerOrder || 10;
  const money = new Intl.NumberFormat(QG.lang === 'de' ? 'de-DE' : 'en-IE', { style: 'currency', currency: 'EUR' });
  const fmt = (v) => money.format(Number(v) || 0);
  const tr = (s, vars) => String(s || '').replace(/\{(\w+)\}/g, (_, k) => (vars && k in vars ? vars[k] : ''));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const S = {
    ev: null, step: null, qty: 1, want: 1,
    seats: null, byId: {}, selected: [],
    hold: null, deadline: 0, ttl: 600, timer: null, poll: null,
    method: null, stripe: null, elements: null, secret: null, cardReady: false, authorized: false,
    busy: false,
  };

  // ---- server -------------------------------------------------------------
  async function api(action, body, opts) {
    try {
      const r = await fetch('checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': QG.csrf },
        body: JSON.stringify(Object.assign({ action }, body || {})),
        keepalive: !!(opts && opts.keepalive),
        credentials: 'same-origin',
      });
      const data = await r.json().catch(() => ({}));
      return { ok: r.ok && data.status === 'success', code: r.status, data };
    } catch (e) {
      return { ok: false, code: 0, data: { message: 'backend_unreachable' } };
    }
  }
  function errText(data) {
    const key = (data && data.message) || 'generic';
    return tr((T.err || {})[key] || (T.err || {}).generic, data || {});
  }

  // ---- messages -------------------------------------------------------------
  function showError(msg) {
    const el = $('coError');
    el.textContent = msg;
    el.hidden = !msg;
  }

  // ---- open / close -------------------------------------------------------------
  document.querySelectorAll('[data-book]').forEach((b) => {
    b.addEventListener('click', () => {
      try { open(JSON.parse(b.getAttribute('data-book'))); } catch (e) { /* malformed row */ }
    });
  });

  function open(ev) {
    S.ev = ev;
    S.qty = 1; S.want = 1; S.selected = []; S.seats = null;
    resetPayment();
    $('coTitle').textContent = QG.title || QG.orga || 'Tickets';
    $('coSub').textContent = [ev.label, ev.time, ev.location].filter(Boolean).join(' · ');
    showError('');
    dlg.showModal();
    if (ev.seating) { go('seats'); loadSeats(); } else { go('ga'); }
  }

  async function close() {
    stopTimer(); stopPoll();
    if (S.hold && S.step !== 'done') api('release', {}, { keepalive: true });
    S.hold = null;
    dlg.close();
    if (S.step === 'done') location.reload();
  }
  $('coClose').addEventListener('click', close);
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  window.addEventListener('pagehide', () => { if (S.hold && S.step !== 'done') api('release', {}, { keepalive: true }); });

  // ---- steps ----------------------------------------------------------------
  function go(step) {
    S.step = step;
    document.querySelectorAll('.co-step').forEach((s) => { s.hidden = s.dataset.step !== step; });
    const isFirst = step === 'ga' || step === 'seats';
    document.querySelectorAll('.co-steps li').forEach((li) => {
      const n = li.dataset.s;
      li.classList.toggle('on', (n === '1' && isFirst) || (n === '2' && step === 'details'));
      li.classList.toggle('done', (n === '1' && (step === 'details' || step === 'done')) || (n === '2' && step === 'done'));
    });
    $('coStepLabel').textContent = isFirst ? T.step1 : step === 'details' ? T.step2 : step === 'done' ? T.summary : T.expired_title;
    $('coBack').hidden = step !== 'details';
    const next = $('coNext');
    next.hidden = false;
    next.classList.remove('is-busy');
    if (isFirst) setNext(T.next, true);
    else if (step === 'details') setNext(S.method === 'cash' ? T.reserve : T.pay, true);
    else if (step === 'expired') setNext(T.restart, false);
    else if (step === 'done') setNext(T.finish, false);
    $('sumNote').textContent = isFirst ? T.pick_note : step === 'details' ? T.reserve_note : '';
    if (step === 'seats') startPoll(); else stopPoll();
    if (step === 'ga') renderQty();
    dlg.querySelector('.co-side').hidden = step === 'done' || step === 'expired';
    renderSummary();
    updateNext();
    $('coNext').blur();
    dlg.querySelector('.co-main').scrollTop = 0;
  }

  function draftTotal() {
    if (!S.ev) return 0;
    if (!S.ev.seating) return S.qty * S.ev.price;
    return S.selected.reduce((a, id) => a + ((S.byId[id] || {}).price || 0), 0);
  }
  function setNext(label, arrow) {
    const isFirst = S.step === 'ga' || S.step === 'seats';
    const sum = S.step === 'details' && S.hold ? S.hold.total : isFirst ? draftTotal() : 0;
    const withTotal = sum > 0 ? label + ' · ' + fmt(sum) : label;
    $('coNextLabel').textContent = withTotal;
    $('coNext').querySelector('svg').style.display = arrow ? '' : 'none';
  }

  function updateNext() {
    const n = $('coNext');
    let ok = !S.busy;
    if (S.step === 'seats') ok = ok && S.selected.length > 0;
    if (S.step === 'ga') ok = ok && S.qty >= 1 && S.qty <= maxQty();
    if (S.step === 'details' && S.method === 'card') ok = ok && S.cardReady;
    n.disabled = !ok;
  }

  $('coNext').addEventListener('click', () => {
    if (S.step === 'ga' || S.step === 'seats') reserve();
    else if (S.step === 'details') submit();
    else if (S.step === 'expired') { const ev = S.ev; S.hold = null; open(ev); }
    else if (S.step === 'done') close();
  });
  $('coBack').addEventListener('click', async () => {
    if (S.busy) return;
    stopTimer();
    if (S.hold) await api('release', {});
    S.hold = null;
    resetPayment();
    showError('');
    if (S.ev.seating) { go('seats'); refreshSeats(); } else { go('ga'); }
  });

  function busy(on, label) {
    S.busy = on;
    const n = $('coNext');
    n.classList.toggle('is-busy', on);
    if (on && label) $('coNextLabel').textContent = label + '…';
    if (!on) go(S.step);
    updateNext();
  }

  // ---- step 1 · general admission -------------------------------------------
  function maxQty() { return Math.max(0, Math.min(MAX, S.ev ? S.ev.available : MAX)); }
  document.querySelectorAll('[data-qty]').forEach((b) => b.addEventListener('click', () => {
    S.qty = Math.max(1, Math.min(maxQty(), S.qty + Number(b.dataset.qty)));
    renderQty(); updateNext();
  }));
  function renderQty() {
    $('qtyVal').textContent = S.qty;
    document.querySelector('[data-qty="-1"]').disabled = S.qty <= 1;
    document.querySelector('[data-qty="1"]').disabled = S.qty >= maxQty();
    $('qtyPrice').textContent = fmt(S.ev.price) + ' ' + T.per_ticket;
    renderSummary();
  }

  // ---- reserve (step 1 → 2) -------------------------------------------------
  async function reserve() {
    showError('');
    busy(true, T.next);
    const body = S.ev.seating ? { date: S.ev.date, seats: S.selected.slice() } : { date: S.ev.date, qty: S.qty };
    const res = await api('start', body);
    busy(false);
    if (!res.ok) {
      if (res.data.message === 'not_enough_tickets' && typeof res.data.available === 'number') {
        S.ev.available = res.data.available;
        S.qty = Math.max(1, Math.min(S.qty, maxQty()));
        if (!S.ev.seating) renderQty();
      }
      if (res.data.message === 'seats_taken') {
        const taken = new Set(res.data.seats || []);
        S.selected = S.selected.filter((id) => !taken.has(id));
        await refreshSeats();
      }
      showError(errText(res.data));
      return;
    }
    S.hold = res.data.hold;
    S.ttl = Math.max(60, S.hold.expires_in || 600);
    S.deadline = Date.now() + (S.hold.expires_in || 600) * 1000;
    startTimer();
    goDetails(res.data.methods || QG.methods || []);
  }

  // ---- hold timer -----------------------------------------------------------
  function startTimer() {
    stopTimer();
    $('holdBox').hidden = false;
    const tick = () => {
      const left = Math.max(0, Math.round((S.deadline - Date.now()) / 1000));
      $('holdTime').textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      $('holdBar').style.transform = 'scaleX(' + Math.min(1, left / S.ttl) + ')';
      $('holdBox').classList.toggle('is-low', left <= 60);
      if (left <= 0) expire();
    };
    tick();
    S.timer = setInterval(tick, 1000);
  }
  function stopTimer() { clearInterval(S.timer); S.timer = null; $('holdBox').hidden = true; }
  function expire() {
    stopTimer();
    if (S.hold) api('release', {});
    S.hold = null;
    resetPayment();
    showError('');
    go('expired');
  }

  // ---- step 2 · details + payment --------------------------------------------
  function goDetails(methods) {
    const avail = (methods || []).filter((m) => m === 'card' || m === 'cash');
    document.querySelectorAll('.co-method').forEach((l) => { l.hidden = !avail.includes(l.dataset.m); });
    $('methodBox').hidden = avail.length === 0;
    if (!avail.includes(S.method)) S.method = avail[0] || null;
    document.querySelectorAll('input[name="method"]').forEach((r) => { r.checked = r.value === S.method; });

    const n = S.hold ? S.hold.qty : 1;
    const box = $('guestsBox'), fields = $('guestFields');
    const prev = [...fields.querySelectorAll('input')].map((i) => i.value);
    fields.innerHTML = '';
    for (let i = 2; i <= n; i++) {
      const id = 'fGuest' + i;
      const lbl = S.hold && S.hold.seated && S.hold.seat_labels[i - 1] ? ' · ' + S.hold.seat_labels[i - 1] : '';
      fields.insertAdjacentHTML('beforeend',
        '<div class="avo-field"><label class="avo-label" for="' + id + '">' + esc(tr(T.guest, { n: i }) + lbl) +
        '</label><input class="avo-input" id="' + id + '" maxlength="80" autocomplete="off" value="' + esc(prev[i - 2] || '') + '"></div>');
    }
    box.hidden = n < 2;
    go('details');
    applyMethod();
    setTimeout(() => { const f = $('fFirst'); if (f && !f.value) f.focus({ preventScroll: true }); }, 50);
  }

  document.querySelectorAll('input[name="method"]').forEach((r) => r.addEventListener('change', () => {
    S.method = r.value; applyMethod(); showError('');
  }));

  function applyMethod() {
    $('consentText').textContent = S.method === 'cash' ? T.consent_cash : T.consent_card;
    $('cardBox').hidden = S.method !== 'card';
    setNext(S.method === 'cash' ? T.reserve : T.pay, true);
    if (S.method === 'card') mountCard();
    updateNext();
  }

  function resetPayment() {
    try { if (S.card) S.card.destroy(); } catch (e) { /* already gone */ }
    S.card = null; S.elements = null; S.secret = null; S.cardReady = false; S.authorized = false;
    $('cardElement').innerHTML = '';
    $('cardLoading').hidden = false;
  }

  function stripeAppearance() {
    const css = getComputedStyle(document.documentElement);
    const v = (n) => css.getPropertyValue(n).trim();
    const light = document.documentElement.classList.contains('avo-light');
    return {
      theme: light ? 'stripe' : 'night',
      variables: {
        colorPrimary: '#FF6B4A',
        colorBackground: v('--avo-surface') || (light ? '#FFFFFF' : '#0A0A0A'),
        colorText: v('--avo-text') || (light ? '#0A0A0A' : '#FFFFFF'),
        colorDanger: '#DC3838',
        fontFamily: "'IBM Plex Mono', ui-monospace, monospace",
        borderRadius: '6px',
        spacingUnit: '4px',
      },
      rules: {
        '.Input': { border: '1px solid ' + (light ? 'rgba(0,0,0,0.22)' : 'rgba(255,255,255,0.38)'), boxShadow: 'none' },
        '.Input:focus': { boxShadow: 'none', outline: '2px solid #FF6B4A', outlineOffset: '2px' },
        '.Label': { fontFamily: "'IBM Plex Mono', monospace", textTransform: 'uppercase', letterSpacing: '0.12em', fontSize: '11px' },
        '.Tab--selected': { borderColor: '#FF6B4A', boxShadow: 'none' },
      },
    };
  }

  async function mountCard() {
    if (S.card || S.mounting || !S.hold) return;
    S.mounting = true;
    try { await mountCardNow(); } finally { S.mounting = false; }
  }
  async function mountCardNow() {
    if (!window.Stripe || !QG.stripeKey) { showError(errText({ message: 'payment_unavailable' })); return; }
    $('cardLoading').hidden = false;
    const res = await api('intent', {});
    if (!res.ok) {
      if (res.data.message === 'hold_expired') return expire();
      showError(errText(res.data)); return;
    }
    if (S.method !== 'card' || S.step !== 'details') return;
    S.secret = res.data.client_secret;
    if (res.data.hold && res.data.hold.expires_in) {
      // The server gives a payment in progress a little more time.
      S.deadline = Math.max(S.deadline, Date.now() + res.data.hold.expires_in * 1000);
    }
    S.stripe = S.stripe || window.Stripe(QG.stripeKey, { locale: QG.lang === 'de' ? 'de' : 'en' });
    S.elements = S.stripe.elements({
      clientSecret: S.secret,
      appearance: stripeAppearance(),
      fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&display=swap' }],
    });
    S.card = S.elements.create('payment', {
      layout: 'tabs',
      fields: { billingDetails: { name: 'never', email: 'never' } },
      wallets: { applePay: 'auto', googlePay: 'auto' },
    });
    S.card.on('ready', () => { S.cardReady = true; $('cardLoading').hidden = true; updateNext(); });
    S.card.on('change', () => showError(''));
    S.card.mount('#cardElement');
  }

  // ---- field validation ------------------------------------------------------
  function fieldError(input, on) {
    input.setAttribute('aria-invalid', on ? 'true' : 'false');
  }
  function collect() {
    const f = $('coForm');
    return {
      first_name: $('fFirst').value.trim(),
      last_name: $('fLast').value.trim(),
      email: $('fEmail').value.trim(),
      add_people: [...$('guestFields').querySelectorAll('input')].map((i) => i.value.trim()),
      consent: $('fConsent').checked ? 1 : 0,
      method: S.method,
      lang: QG.lang,
      website: f.elements.website ? f.elements.website.value : '',
    };
  }
  function localCheck(o) {
    let first = null;
    const bad = (el) => { fieldError(el, true); first = first || el; };
    ['fFirst', 'fLast', 'fEmail'].forEach((id) => fieldError($(id), false));
    if (!o.first_name) bad($('fFirst'));
    if (!o.last_name) bad($('fLast'));
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(o.email)) bad($('fEmail'));
    if (first) { first.focus(); return first === $('fEmail') ? T.err.invalid_email : T.err.invalid_name; }
    if (!o.consent) { $('fConsent').focus(); return T.err.consent_required; }
    return null;
  }
  ['fFirst', 'fLast', 'fEmail'].forEach((id) => $(id).addEventListener('input', () => fieldError($(id), false)));
  $('coForm').addEventListener('submit', (e) => { e.preventDefault(); submit(); });

  // ---- place the order ---------------------------------------------------------
  async function submit() {
    if (S.busy || !S.hold) return;
    showError('');
    const order = collect();
    const local = localCheck(order);
    if (local) { showError(local); return; }

    busy(true, T.processing);
    const v = await api('validate', order);
    if (!v.ok) {
      busy(false);
      if (v.data.message === 'hold_expired') return expire();
      if (v.data.message === 'invalid_email' || v.data.message === 'email_domain') fieldError($('fEmail'), true);
      showError(errText(v.data));
      return;
    }

    if (order.method === 'card' && !S.authorized) {
      busy(true, T.paying);
      const { error: sErr } = await S.elements.submit();
      if (sErr) { busy(false); showError(sErr.message); return; }
      const { error, paymentIntent } = await S.stripe.confirmPayment({
        elements: S.elements,
        redirect: 'if_required',
        confirmParams: {
          return_url: location.href.split('#')[0],
          payment_method_data: { billing_details: { name: order.first_name + ' ' + order.last_name, email: order.email } },
        },
      });
      if (error) { busy(false); showError(error.message || errText({})); return; }
      if (!paymentIntent || !['requires_capture', 'succeeded'].includes(paymentIntent.status)) {
        busy(false); showError(errText({ message: 'payment_not_authorized' })); return;
      }
      S.authorized = true;
    }

    busy(true, T.processing);
    const res = await api('complete', order);
    if (!res.ok) {
      busy(false);
      const m = res.data.message;
      if (m === 'hold_expired' || m === 'payment_failed' || m === 'seats_taken' || m === 'order_failed') {
        // The server rolled everything back and nothing was charged.
        stopTimer(); S.hold = null; resetPayment();
        go('expired');
      }
      showError(errText(res.data));
      return;
    }
    stopTimer();
    S.busy = false;
    done(res.data.order || {});
  }

  function done(o) {
    const paid = !!o.paid;
    $('doneSerial').textContent = paid ? 'ORDER · PAID' : 'ORDER · RESERVED';
    $('doneTitle').textContent = paid ? T.done_paid : T.done_reserved;
    dlg.querySelector('.stub__top').classList.toggle('is-pending', !paid);
    const rows = [
      [T.date, [S.ev.label, S.ev.time].filter(Boolean).join(' · ')],
      [T.location, S.ev.location],
      [T.name, o.name],
      [(o.seat_labels && o.seat_labels.length) ? T.seats : T.step1, (o.seat_labels && o.seat_labels.length) ? o.seat_labels.join(', ') : String(o.qty)],
      [T.total, fmt(o.total)],
    ].filter((r) => r[1]);
    $('doneRows').innerHTML = rows.map((r) => '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>').join('');
    $('doneText').textContent = tr(paid ? T.done_paid_text : T.done_reserved_text, { email: o.email || '' });
    // The date's calendar entry; the ticket's own one comes with the email.
    $('doneIcs').href = 'ics.php?date=' + encodeURIComponent(o.date || S.ev.date || '');
    S.hold = null;
    go('done');
  }

  // ---- summary -------------------------------------------------------------------
  function renderSummary() {
    const ul = $('sumLines');
    let lines = [], total = 0;
    if (S.hold) {
      if (S.hold.seated) {
        S.hold.seat_labels.forEach((l, i) => lines.push([l, S.hold.prices[i]]));
      } else {
        lines.push([S.hold.qty + ' × Ticket', S.hold.total]);
      }
      total = S.hold.total;
    } else if (S.ev && S.ev.seating) {
      S.selected.forEach((id) => { const s = S.byId[id]; if (s) { lines.push([s.label, s.price]); total += s.price; } });
      if (!lines.length) lines.push([T.seats_none, null]);
    } else if (S.ev) {
      lines.push([S.qty + ' × Ticket', S.qty * S.ev.price]);
      total = S.qty * S.ev.price;
    }
    ul.innerHTML = lines.map((l) => '<li><span>' + esc(l[0]) + '</span>' + (l[1] == null ? '' : '<b>' + fmt(l[1]) + '</b>') + '</li>').join('');
    $('sumTotal').textContent = fmt(total);
    if ((S.step === 'ga' || S.step === 'seats') && !S.busy) setNext(T.next, true);
  }

  // ---- seat map -----------------------------------------------------------------
  async function loadSeats() {
    const svg = $('seatSvg');
    svg.innerHTML = '';
    $('seatBest').disabled = true;
    $('seatMap').setAttribute('aria-busy', 'true');
    $('seatLegend').innerHTML = '<span class="avo-spinner sm" role="status"></span> <span>' + esc(T.seats_loading) + '</span>';
    const res = await api('seats', { date: S.ev.date });
    $('seatMap').removeAttribute('aria-busy');
    if (!res.ok || !res.data.seating) {
      $('seatLegend').innerHTML = '';
      showError(T.seats_error);
      return;
    }
    S.seats = res.data;
    drawSeats();
    $('seatBest').disabled = false;
    requestAnimationFrame(() => requestAnimationFrame(fitSeats));
  }

  async function refreshSeats() {
    if (!S.ev || !S.ev.seating) return;
    const res = await api('seats', { date: S.ev.date });
    if (!res.ok || !res.data.seating) return;
    S.seats = res.data;
    const lost = S.selected.filter((id) => { const e = S.seats.elements.find((x) => x.id === id); return !e || e.status !== 'free'; });
    if (lost.length) {
      S.selected = S.selected.filter((id) => !lost.includes(id));
      showError(T.err.seats_taken);
    }
    drawSeats(true);
  }

  function startPoll() { stopPoll(); S.poll = setInterval(() => { if (!S.busy && !document.hidden) refreshSeats(); }, 20000); }
  function stopPoll() { clearInterval(S.poll); S.poll = null; }

  let view = { x: 0, y: 0, w: 200, h: 120 }, zoom = 1;

  function drawSeats(keepZoom) {
    const data = S.seats, els = data.elements || [];
    const cats = {}; (data.categories || []).forEach((c) => { cats[String(c.id)] = c; });
    const own = new Set(data.own_seats || []);
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    els.forEach((e) => {
      const w = e.type === 'seat' ? SEAT_R * 2 : (e.w || 40), h = e.type === 'seat' ? SEAT_R * 2 : (e.h || 40);
      const x0 = e.type === 'seat' ? e.x - SEAT_R : e.x, y0 = e.type === 'seat' ? e.y - SEAT_R : e.y;
      minX = Math.min(minX, x0); minY = Math.min(minY, y0); maxX = Math.max(maxX, x0 + w); maxY = Math.max(maxY, y0 + h);
    });
    if (!isFinite(minX)) { minX = 0; minY = 0; maxX = 200; maxY = 120; }
    const pad = 20;
    view = { x: minX - pad, y: minY - pad, w: maxX - minX + pad * 2, h: maxY - minY + pad * 2 };
    S.byId = {};
    let out = '';
    els.forEach((e) => {
      if (e.type === 'seat') return;
      const w = e.w || 40, h = e.h || 40;
      const rot = e.rotation ? ' transform="rotate(' + Number(e.rotation) + ' ' + (e.x + w / 2) + ' ' + (e.y + h / 2) + ')"' : '';
      if (e.type === 'label') {
        out += '<text class="deco-label" x="' + e.x + '" y="' + (e.y + 14) + '"' + rot + '>' + esc(e.text || '') + '</text>';
      } else if (e.type === 'table') {
        out += '<ellipse class="deco" cx="' + (e.x + w / 2) + '" cy="' + (e.y + h / 2) + '" rx="' + w / 2 + '" ry="' + h / 2 + '"' + rot + '/>';
      } else {
        out += '<rect class="deco" x="' + e.x + '" y="' + e.y + '" width="' + w + '" height="' + h + '" rx="4"' + rot + '/>';
        const lbl = e.type === 'stage' ? (QG.lang === 'de' ? 'BÜHNE' : 'STAGE') : e.type === 'screen' ? (QG.lang === 'de' ? 'LEINWAND' : 'SCREEN') : '';
        if (lbl) out += '<text class="deco-label" x="' + (e.x + w / 2) + '" y="' + (e.y + h / 2 + 4) + '" text-anchor="middle"' + rot + '>' + lbl + '</text>';
      }
    });
    els.forEach((e) => {
      if (e.type !== 'seat') return;
      const cat = e.category_id != null ? cats[String(e.category_id)] : null;
      const color = (cat && /^#[0-9a-f]{3,8}$/i.test(cat.color || '')) ? cat.color : '#BBBBBB';
      const free = e.status === 'free' || own.has(e.id);
      const label = String(e.row || '') + (e.number != null ? e.number : '');
      S.byId[e.id] = { id: e.id, x: e.x, y: e.y, row: e.row, free, price: Number(e.price) || 0,
        label: (e.row ? e.row + ' · ' : '') + (e.number != null ? e.number : '') || e.id };
      out += '<g class="seat' + (free ? '' : ' is-sold') + '" data-id="' + esc(e.id) + '" role="button" aria-label="' + esc(S.byId[e.id].label + ' · ' + fmt(e.price)) + '"' + (free ? ' tabindex="-1"' : ' aria-disabled="true"') + '>'
        + '<circle cx="' + e.x + '" cy="' + e.y + '" r="' + (SEAT_R - 1) + '" fill="' + color + '" fill-opacity="0.28" stroke="' + color + '"/>'
        + (label ? '<text x="' + e.x + '" y="' + (e.y + 3) + '" text-anchor="middle" fill="currentColor">' + esc(label) + '</text>' : '')
        + '</g>';
    });
    if (own.size && !S.selected.length) S.selected = [...own].filter((id) => S.byId[id]);
    const svg = $('seatSvg');
    svg.setAttribute('viewBox', view.x + ' ' + view.y + ' ' + view.w + ' ' + view.h);
    svg.innerHTML = out;
    svg.style.color = 'var(--avo-text)';
    paintSelection();
    renderLegend(data);
    if (keepZoom) applyZoom();
  }

  function renderLegend(data) {
    const items = (data.categories || []).map((c) => '<span><i style="background:' + esc(/^#[0-9a-f]{3,8}$/i.test(c.color || '') ? c.color : '#BBB') + '"></i>' + esc(c.name) + ' · ' + fmt(c.price) + '</span>');
    if (!items.length) items.push('<span><i style="background:#BBBBBB"></i>' + fmt(data.base_price) + '</span>');
    items.push('<span><i style="background:var(--avo-text);border:2px solid var(--avo-primary)"></i>' + esc(T.seat_mine) + '</span>');
    items.push('<span><i style="background:var(--avo-surface-high)"></i>' + esc(T.seat_sold) + '</span>');
    $('seatLegend').innerHTML = items.join('');
  }

  function paintSelection() {
    const sel = new Set(S.selected);
    document.querySelectorAll('#seatSvg .seat').forEach((g) => g.classList.toggle('is-mine', sel.has(g.dataset.id)));
    $('wantVal').textContent = S.want;
    document.querySelector('[data-want="-1"]').disabled = S.want <= 1;
    document.querySelector('[data-want="1"]').disabled = S.want >= MAX;
    checkOrphans();
    renderSummary(); updateNext();
  }

  // click / tap on a seat (ignored after a drag)
  let panMoved = false;
  $('seatSvg').addEventListener('click', (e) => {
    if (panMoved) return;
    const g = e.target.closest('.seat');
    if (!g || g.classList.contains('is-sold')) return;
    const id = g.dataset.id;
    showError('');
    const i = S.selected.indexOf(id);
    if (i >= 0) { S.selected.splice(i, 1); if (S.want > 1 && S.selected.length) S.want = Math.max(1, S.selected.length); }
    else if (S.want > 1 && S.selected.length === 0) { snapBlock(id, S.want); return; }
    else {
      if (S.selected.length >= MAX) { showError(tr(T.seat_max, { n: MAX })); return; }
      S.selected.push(id);
      S.want = Math.max(S.want, S.selected.length);
    }
    paintSelection();
  });

  document.querySelectorAll('[data-want]').forEach((b) => b.addEventListener('click', () => {
    S.want = Math.max(1, Math.min(MAX, S.want + Number(b.dataset.want)));
    if (S.selected.length > S.want) S.selected = S.selected.slice(0, S.want);
    paintSelection();
  }));
  $('seatBest').addEventListener('click', () => { showError(''); pickBest(S.want); });

  // rows and free runs, for the best-seat picker and the orphan warning
  function rows() {
    const seats = Object.values(S.byId);
    const byRow = {};
    const useRow = seats.some((s) => s.row != null && String(s.row).trim() !== '');
    if (useRow) seats.forEach((s) => { (byRow[s.row] = byRow[s.row] || []).push(s); });
    else seats.slice().sort((a, b) => a.y - b.y).forEach((s) => {
      const k = Object.keys(byRow).find((y) => Math.abs(Number(y) - s.y) <= SEAT_R * 1.5);
      (byRow[k != null ? k : s.y] = byRow[k != null ? k : s.y] || []).push(s);
    });
    return Object.values(byRow).map((r) => r.sort((a, b) => a.x - b.x)).sort((a, b) => a[0].y - b[0].y);
  }
  function runs(row) {
    const gaps = []; for (let i = 1; i < row.length; i++) gaps.push(row[i].x - row[i - 1].x);
    const med = gaps.length ? gaps.slice().sort((a, b) => a - b)[Math.floor(gaps.length / 2)] : 0;
    const out = []; let cur = [];
    row.forEach((s, i) => { if (i && med && row[i].x - row[i - 1].x > med * 1.6) { out.push(cur); cur = []; } cur.push(s); });
    if (cur.length) out.push(cur);
    return out;
  }
  function freeSegments() {
    const rs = rows(), mid = (rs.length - 1) / 2, segs = [];
    rs.forEach((row, ri) => runs(row).forEach((run) => {
      const cx = (run[0].x + run[run.length - 1].x) / 2;
      let cur = [];
      const flush = () => { if (cur.length) segs.push({ seats: cur, dist: Math.abs(ri - mid), cx }); cur = []; };
      run.forEach((s) => { if (s.free) cur.push(s); else flush(); });
      flush();
    }));
    return segs;
  }
  function score(seg, off, n) {
    const L = seg.seats.length, block = seg.seats.slice(off, off + n);
    let sc = (off === 1 ? 1000 : 0) + (L - n - off === 1 ? 1000 : 0) + (L === n ? -200 : 0);
    sc += seg.dist * 5 + Math.abs((block[0].x + block[block.length - 1].x) / 2 - seg.cx) / 40;
    return { sc, ids: block.map((b) => b.id) };
  }
  function pickBest(n) {
    let best = null;
    freeSegments().forEach((seg) => {
      for (let off = 0; off <= seg.seats.length - n; off++) { const c = score(seg, off, n); if (!best || c.sc < best.sc) best = c; }
    });
    if (!best) { showError(T.seat_no_block); return; }
    S.selected = best.ids; paintSelection(); centerOn(best.ids[0]);
  }
  function snapBlock(anchor, n) {
    const seg = freeSegments().find((s) => s.seats.some((x) => x.id === anchor));
    if (!seg || seg.seats.length < n) { pickBest(n); return; }
    const ai = seg.seats.findIndex((x) => x.id === anchor);
    let best = null;
    for (let off = Math.max(0, ai - n + 1); off <= Math.min(ai, seg.seats.length - n); off++) {
      const c = score(seg, off, n); c.sc += (ai - off) * 2;
      if (!best || c.sc < best.sc) best = c;
    }
    S.selected = best.ids; paintSelection();
  }
  function checkOrphans() {
    const hint = $('seatOrphan');
    const sel = new Set(S.selected);
    let orphan = false;
    if (sel.size) rows().forEach((row) => runs(row).forEach((run) => {
      const freeAfter = (s) => s && s.free && !sel.has(s.id);
      run.forEach((s, i) => {
        if (!freeAfter(s) || freeAfter(run[i - 1]) || freeAfter(run[i + 1])) return;
        if ((run[i - 1] && sel.has(run[i - 1].id)) || (run[i + 1] && sel.has(run[i + 1].id))) orphan = true;
      });
    }));
    hint.textContent = orphan ? T.seat_orphan : '';
    hint.hidden = !orphan;
  }

  // zoom + pan: the SVG is sized in px inside a scrolling box
  const box = $('seatMap');
  function applyZoom() {
    const svg = $('seatSvg');
    svg.style.width = Math.round(view.w * zoom) + 'px';
    svg.style.height = Math.round(view.h * zoom) + 'px';
  }
  function fitSeats() {
    const cw = (box.clientWidth || 360) - 2, ch = (box.clientHeight || 400) - 2;
    const fit = Math.min(cw / view.w, window.innerWidth > 820 ? ch / view.h : Infinity);
    // On a phone a whole hall at fit-width makes seats too small to tap;
    // keep them at full size and let the map scroll sideways instead.
    zoom = Math.max(0.3, Math.min(2.2, window.innerWidth <= 820 ? Math.max(fit, 1) : fit));
    applyZoom();
    box.scrollLeft = (box.scrollWidth - box.clientWidth) / 2;
  }
  function zoomBy(f, cx, cy) {
    const prev = zoom;
    zoom = Math.max(0.3, Math.min(4, zoom * f));
    if (zoom === prev) return;
    const r = box.getBoundingClientRect();
    const px = cx == null ? r.width / 2 : cx - r.left, py = cy == null ? r.height / 2 : cy - r.top;
    const ox = px + box.scrollLeft, oy = py + box.scrollTop, k = zoom / prev;
    applyZoom();
    box.scrollLeft = ox * k - px; box.scrollTop = oy * k - py;
  }
  function centerOn(id) {
    const g = document.querySelector('#seatSvg .seat[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
    if (!g) return;
    const b = g.getBoundingClientRect(), r = box.getBoundingClientRect();
    box.scrollLeft += b.left + b.width / 2 - (r.left + r.width / 2);
    box.scrollTop += b.top + b.height / 2 - (r.top + r.height / 2);
  }
  $('zIn').addEventListener('click', () => zoomBy(1.3));
  $('zOut').addEventListener('click', () => zoomBy(1 / 1.3));
  $('zFit').addEventListener('click', fitSeats);
  box.addEventListener('wheel', (e) => {
    if (!e.ctrlKey && !e.metaKey) return; // plain wheel scrolls; ctrl/pinch zooms
    e.preventDefault(); zoomBy(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX, e.clientY);
  }, { passive: false });
  const pts = new Map(); let drag = null, pinch = 0;
  box.addEventListener('pointerdown', (e) => {
    pts.set(e.pointerId, e);
    if (pts.size === 1) drag = { x: e.clientX, y: e.clientY, l: box.scrollLeft, t: box.scrollTop };
    panMoved = false;
  });
  box.addEventListener('pointermove', (e) => {
    if (!pts.has(e.pointerId)) return;
    pts.set(e.pointerId, e);
    if (pts.size === 2) {
      const [a, b] = [...pts.values()];
      const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
      if (pinch) { panMoved = true; zoomBy(d / pinch, (a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2); }
      pinch = d; return;
    }
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!panMoved && Math.abs(dx) + Math.abs(dy) > 6) panMoved = true;
    if (panMoved) { box.scrollLeft = drag.l - dx; box.scrollTop = drag.t - dy; }
  });
  const up = (e) => {
    pts.delete(e.pointerId);
    if (pts.size < 2) pinch = 0;
    if (!pts.size) { drag = null; setTimeout(() => { panMoved = false; }, 0); }
  };
  box.addEventListener('pointerup', up);
  box.addEventListener('pointercancel', up);
  window.addEventListener('resize', () => { if (S.step === 'seats' && S.seats) fitSeats(); });

  // theme switch while the card form is open: restyle it
  document.addEventListener('avo:theme', () => { if (S.elements) S.elements.update({ appearance: stripeAppearance() }); });
})();
