<?php
/**
 * Live dashboard for the evening (backstage monitor, tablet of the house
 * manager): door ring, seats left per date, box-office takings today, active
 * scanners, the last admits and the running announcement.
 *
 * Access: an admin session, or ?token=<display token> generated in the admin
 * (Screens). The token only reads; the panel for sending announcements is
 * shown to an admin session only and goes through the admin proxy (CSRF).
 */
require_once '../config.php';

$isAdmin = !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
    $token = '';
}

/** Dashboard data from the backend; a monitor without admin session must
 *  bring a display token, which the backend checks on every call. */
function live_fetch(bool $isAdmin, string $token): array
{
    $query = ($isAdmin && $token === '') ? '' : '?display_token=' . urlencode($token);
    $ch = curl_init(rtrim(API_BASE_URL, '/') . '/api/live/dashboard' . $query);
    $headers = ['Authorization: ' . API_KEY];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : json_decode($body, true)];
}

if (isset($_GET['data'])) {
    session_write_close();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!$isAdmin && $token === '') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'invalid_token']);
        exit;
    }
    [$code, $data] = live_fetch($isAdmin, $token);
    http_response_code($code === 200 && is_array($data) ? 200 : ($code === 403 ? 403 : 502));
    echo json_encode(is_array($data) ? $data : ['status' => 'error', 'message' => 'unavailable']);
    exit;
}

// Without a session the link must carry a valid token; checked once here so a
// wrong link gets a clear page instead of an empty dashboard.
if (!$isAdmin) {
    [$code] = $token === '' ? [403] : live_fetch(false, $token);
    if ($code === 403) {
        http_response_code(403);
        $pageTitle = 'Live-Dashboard';
        $assetBase = '../';
        $forceDark = true;
        ?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body style="min-height:100vh;display:grid;place-items:center;background:var(--avo-bg);color:var(--avo-text);padding:24px;text-align:center">
    <div style="max-width:520px">
        <div class="avo-kicker"><span>Live-Dashboard</span></div>
        <h1 class="avo-display-2" style="margin:12px 0">Kein Zugriff</h1>
        <p class="avo-small">Dieser Link ist ungültig oder wurde zurückgezogen. Im Admin unter „Screens“ gibt es den aktuellen Link, oder <a class="avo-link" href="../admin/login.php">als Admin anmelden</a>.</p>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

$csrf = $isAdmin ? generateCsrfToken() : '';
$pageTitle = 'Live-Dashboard';
$assetBase = '../';
$forceDark = true;
$extraHead = '';
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="lv-body">
<style>
    html, body { height: 100%; }
    .lv-body { margin: 0; background: var(--avo-bg); color: var(--avo-text); overflow: hidden; }
    .lv {
        height: 100vh; height: 100dvh; box-sizing: border-box;
        display: grid; gap: clamp(8px, 1.2vw, 20px); padding: clamp(10px, 1.4vw, 24px);
        grid-template-columns: 1.15fr 1fr 1fr;
        grid-template-rows: auto minmax(0, 1.55fr) minmax(0, 1fr);
        grid-template-areas: "head head head" "ring cast recent" "dates reg scan";
    }
    .lv-card {
        min-width: 0; min-height: 0; overflow: hidden;
        display: flex; flex-direction: column; gap: clamp(6px, 1vh, 14px);
        padding: clamp(12px, 1.4vw, 26px);
        background: var(--avo-surface); border: 1px solid var(--avo-border); border-radius: var(--avo-radius-lg, 12px);
    }
    .lv-k {
        font-family: var(--avo-font-mono); font-size: clamp(.7rem, 1vw, 1rem); font-weight: 600;
        letter-spacing: .16em; text-transform: uppercase; color: var(--avo-text-muted);
    }
    .lv-head { grid-area: head; display: flex; align-items: center; gap: 16px; }
    .lv-head h1 { margin: 0; font-family: var(--avo-font-display); font-size: clamp(1.2rem, 2.2vw, 2.4rem); font-weight: 800; line-height: 1.1; }
    .lv-head .lv-sp { flex: 1; }
    .lv-clock { font-family: var(--avo-font-mono); font-size: clamp(1.2rem, 2.4vw, 2.6rem); font-weight: 600; font-variant-numeric: tabular-nums; }
    .lv-net { display: inline-flex; align-items: center; gap: 8px; font-family: var(--avo-font-mono); font-size: clamp(.7rem, .9vw, .95rem); text-transform: uppercase; letter-spacing: .1em; color: var(--avo-success); }
    .lv-net::before { content: ""; width: 10px; height: 10px; border-radius: 99px; background: currentColor; }
    .lv.is-offline .lv-net { color: var(--avo-warning); }
    .lv.is-offline .lv-net::before { animation: lvPulse 1s ease-in-out infinite; }
    .lv.is-offline .lv-card { opacity: .55; }
    @keyframes lvPulse { 50% { opacity: .2; } }

    /* door ring */
    .lv-ring { grid-area: ring; align-items: center; justify-content: center; text-align: center; }
    .lv-ring .lv-k { align-self: stretch; text-align: left; }
    .lv-ring__box { position: relative; flex: 1; min-height: 0; width: 100%; }
    .lv-ring .lv-ring__svg { position: absolute; inset: 0; width: 100%; height: 100%; }
    .lv-ring circle { fill: none; stroke-width: 10; }
    .lv-ring .lv-ring__bg { stroke: color-mix(in oklab, var(--avo-text) 12%, transparent); }
    .lv-ring .lv-ring__fg { stroke: var(--avo-success); stroke-linecap: round; transition: stroke-dashoffset .6s ease; }
    .lv-ring__num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .lv-ring__big { font-family: var(--avo-font-display); font-weight: 800; font-size: clamp(2.4rem, 6.5vw, 8rem); line-height: 1; font-variant-numeric: tabular-nums; }
    .lv-ring__of { font-family: var(--avo-font-mono); font-size: clamp(.9rem, 1.6vw, 1.8rem); color: var(--avo-text-muted); margin-top: .3em; }
    .lv-ring__sub { font-size: clamp(.85rem, 1.2vw, 1.3rem); color: var(--avo-text-muted); }

    /* announcement */
    .lv-cast { grid-area: cast; }
    .lv-cast__now { flex: 1; min-height: 0; display: flex; flex-direction: column; justify-content: center; gap: 10px; padding: clamp(12px, 1.4vw, 24px); border-radius: var(--avo-radius-md, 10px); background: color-mix(in oklab, var(--avo-text) 6%, transparent); }
    .lv-cast__now[data-cat] { background: var(--lvc); color: var(--lvc-fg); }
    .lv-cast__tag { font-family: var(--avo-font-mono); font-size: clamp(.7rem, .95vw, 1rem); letter-spacing: .14em; text-transform: uppercase; }
    .lv-cast__text { font-family: var(--avo-font-display); font-weight: 800; font-size: clamp(1.1rem, 2.3vw, 2.6rem); line-height: 1.15; overflow-wrap: anywhere; }
    .lv-cast__meta { font-family: var(--avo-font-mono); font-size: clamp(.7rem, .9vw, .95rem); opacity: .85; }
    .lv-cast__idle { color: var(--avo-text-muted); font-size: clamp(.9rem, 1.3vw, 1.4rem); }
    [data-cat="info"] { --lvc: #1d4ed8; --lvc-fg: #fff; }
    [data-cat="attention"] { --lvc: #f5b400; --lvc-fg: #111; }
    [data-cat="alert"] { --lvc: #c81e1e; --lvc-fg: #fff; }
    [data-cat="success"] { --lvc: #15803d; --lvc-fg: #fff; }
    .lv-panel { display: flex; flex-wrap: wrap; gap: 8px; }
    .lv-panel button {
        flex: 1 1 auto; min-height: 44px; padding: 8px 12px; border-radius: var(--avo-radius-md, 10px);
        border: 1px solid var(--avo-border); border-left: 4px solid var(--lvc, var(--avo-border));
        background: var(--avo-bg); color: var(--avo-text); font: inherit; font-weight: 700; font-size: clamp(.8rem, 1vw, 1rem); cursor: pointer;
    }
    .lv-panel button:disabled { opacity: .5; }
    .lv-panel .lv-end { border-left-color: var(--avo-text-muted); }

    /* lists */
    .lv-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
    .lv-list li { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: clamp(5px, .8vh, 10px) 0; border-bottom: 1px solid var(--avo-border); font-size: clamp(.9rem, 1.3vw, 1.45rem); }
    .lv-list li:last-child { border-bottom: 0; }
    .lv-list .lv-main { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
    .lv-list .lv-side { flex-shrink: 0; font-family: var(--avo-font-mono); color: var(--avo-text-muted); font-size: .85em; font-variant-numeric: tabular-nums; }
    .lv-list .lv-muted { color: var(--avo-text-muted); font-weight: 400; }
    .lv-recent { grid-area: recent; }
    .lv-scan { grid-area: scan; }
    .lv-scan .lv-dot { display: inline-block; width: .55em; height: .55em; border-radius: 99px; background: var(--avo-success); margin-right: .5em; }
    .lv-scan .lv-dot.lv-late { background: var(--avo-warning); }
    .lv-dates { grid-area: dates; }
    .lv-dates .lv-bar { height: 6px; margin-top: 4px; border-radius: 99px; background: color-mix(in oklab, var(--avo-text) 12%, transparent); overflow: hidden; }
    .lv-dates .lv-bar span { display: block; height: 100%; background: var(--avo-primary); transform-origin: left; }
    .lv-dates li { flex-direction: column; align-items: stretch; gap: 0; }
    .lv-dates .lv-row { display: flex; justify-content: space-between; gap: 12px; }
    .lv-reg { grid-area: reg; justify-content: center; }
    .lv-reg__big { font-family: var(--avo-font-display); font-weight: 800; font-size: clamp(1.8rem, 3.6vw, 4.2rem); line-height: 1; font-variant-numeric: tabular-nums; }
    .lv-reg__sub { color: var(--avo-text-muted); font-size: clamp(.85rem, 1.2vw, 1.3rem); }
    .lv-msg { position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%); padding: 10px 18px; border-radius: 999px; background: var(--avo-warning); color: #111; font-weight: 800; z-index: 10; }
    .lv-msg[hidden] { display: none; }

    @media (max-width: 900px), (orientation: portrait) and (max-width: 1100px) {
        .lv-body { overflow: auto; }
        .lv { height: auto; min-height: 100dvh; grid-template-columns: 1fr 1fr; grid-template-rows: auto; grid-template-areas: "head head" "ring ring" "cast cast" "recent scan" "dates reg"; }
        .lv-ring__box { flex: none; height: min(42vh, 80vw); }
    }
    @media (max-width: 560px) {
        .lv { grid-template-columns: 1fr; grid-template-areas: "head" "ring" "cast" "recent" "scan" "dates" "reg"; }
        .lv-head { flex-wrap: wrap; }
    }
    @media (prefers-reduced-motion: reduce) { .lv-ring .lv-ring__fg { transition: none; } .lv.is-offline .lv-net::before { animation: none; } }
</style>

<main class="lv" id="lv">
    <header class="lv-head">
        <div>
            <div class="lv-k" id="lvOrga">Live</div>
            <h1 id="lvTitle">Live-Dashboard</h1>
        </div>
        <span class="lv-sp"></span>
        <span class="lv-net" id="lvNet">Live</span>
        <span class="lv-clock" id="lvClock"></span>
    </header>

    <section class="lv-card lv-ring" aria-label="Einlass">
        <div class="lv-k" id="lvRingK">Einlass heute</div>
        <div class="lv-ring__box">
            <svg class="lv-ring__svg" viewBox="0 0 120 120" aria-hidden="true"><circle class="lv-ring__bg" cx="60" cy="60" r="52"/><circle class="lv-ring__fg" id="lvRingFg" cx="60" cy="60" r="52" transform="rotate(-90 60 60)" stroke-dasharray="326.73" stroke-dashoffset="326.73"/></svg>
            <div class="lv-ring__num">
                <div class="lv-ring__big" id="lvIn">–</div>
                <div class="lv-ring__of" id="lvOf">von –</div>
            </div>
        </div>
        <div class="lv-ring__sub" id="lvPending">&nbsp;</div>
    </section>

    <section class="lv-card lv-cast" aria-label="Durchsage">
        <div class="lv-k">Durchsage</div>
        <div class="lv-cast__now" id="lvCast"><span class="lv-cast__idle">Keine Durchsage aktiv.</span></div>
        <?php if ($isAdmin): ?>
        <div class="lv-panel" id="lvPanel"></div>
        <?php endif; ?>
    </section>

    <section class="lv-card lv-recent" aria-label="Letzte Einlässe">
        <div class="lv-k">Letzte Einlässe</div>
        <ul class="lv-list" id="lvRecent"></ul>
    </section>

    <section class="lv-card lv-dates" aria-label="Restplätze">
        <div class="lv-k">Restplätze</div>
        <ul class="lv-list" id="lvDates"></ul>
    </section>

    <section class="lv-card lv-reg" aria-label="Kasse heute">
        <div class="lv-k">Kasse heute</div>
        <div class="lv-reg__big" id="lvReg">–</div>
        <div class="lv-reg__sub" id="lvRegSub">&nbsp;</div>
    </section>

    <section class="lv-card lv-scan" aria-label="Aktive Scanner">
        <div class="lv-k">Aktive Geräte</div>
        <ul class="lv-list" id="lvScan"></ul>
    </section>
</main>
<div class="lv-msg" id="lvMsg" role="status" hidden>Verbindung verloren, versuche erneut …</div>

<script>
(function () {
    'use strict';
    var TOKEN = <?php echo json_encode($token); ?>;
    var ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var CSRF = <?php echo json_encode($csrf); ?>;
    var POLL_MS = 3000;
    var LABEL = { info: 'Info', attention: 'Achtung', alert: 'Dringend', success: 'Hinweis' };
    var ROLE = { scanner: 'Einlass', inspector: 'Inspector', kasse: 'Kasse (Handy)', ticketflow: 'Kasse' };
    var WD = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var num = function (n) { return new Intl.NumberFormat('de-DE').format(Number(n) || 0); };
    var eur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
    var fails = 0, presets = [], busy = false;

    function fmtDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) return iso || '';
        return WD[new Date(+m[1], +m[2] - 1, +m[3]).getDay()] + ', ' + m[3] + '.' + m[2] + '.';
    }
    function tick() { $('lvClock').textContent = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }

    function render(d) {
        if (d.title) $('lvTitle').textContent = d.title;
        if (d.orga) $('lvOrga').textContent = d.orga + ' · Live';
        // door ring
        var sold = d.sold || 0, inn = d.checked_in || 0;
        $('lvIn').textContent = d.event_today === false && !sold ? '–' : num(inn);
        $('lvOf').textContent = d.event_today === false && !sold ? 'kein Termin heute' : 'von ' + num(sold) + ' drin';
        var pct = sold ? Math.min(1, inn / sold) : 0;
        $('lvRingFg').style.strokeDashoffset = String(326.73 * (1 - pct));
        $('lvPending').textContent = sold ? Math.round(pct * 100) + ' % · ' + num(d.pending) + ' fehlen noch' : ' ';
        // announcement
        var b = d.broadcast, box = $('lvCast');
        if (b && LABEL[b.category]) {
            box.dataset.cat = b.category;
            var left = b.expires_in == null ? 'bis beendet' : 'noch ' + (b.expires_in >= 60 ? Math.ceil(b.expires_in / 60) + ' min' : b.expires_in + ' s');
            var to = (b.targets || []).map(function (t) { return t === 'screens' ? 'Screens' : 'Personal'; }).join(' + ');
            box.innerHTML = '<span class="lv-cast__tag">' + esc(LABEL[b.category]) + '</span>' +
                '<span class="lv-cast__text">' + esc(b.text) + '</span>' +
                '<span class="lv-cast__meta">' + esc(to + ' · ' + left) + '</span>';
        } else {
            delete box.dataset.cat;
            box.innerHTML = '<span class="lv-cast__idle">Keine Durchsage aktiv.</span>';
        }
        if (ADMIN) renderPanel(d.presets || [], !!b);
        // last admits
        var rec = d.recent || [];
        $('lvRecent').innerHTML = rec.length ? rec.map(function (r) {
            return '<li><span class="lv-main">' + esc(r.name || 'Gast') + (r.seat ? ' <span class="lv-muted">· ' + esc(r.seat) + '</span>' : '') + '</span><span class="lv-side">' + esc(r.time) + '</span></li>';
        }).join('') : '<li><span class="lv-main lv-muted">Noch keine Einlässe heute.</span></li>';
        // seats left
        var dates = d.dates || [];
        $('lvDates').innerHTML = dates.length ? dates.map(function (x) {
            var p = x.tickets ? Math.min(1, x.sold / x.tickets) : 0;
            return '<li><div class="lv-row"><span class="lv-main">' + esc(fmtDate(x.date) + ' ' + x.time) + '</span><span class="lv-side">' + num(x.available) + ' frei</span></div>' +
                '<div class="lv-bar"><span style="transform:scaleX(' + p + ')"></span></div></li>';
        }).join('') : '<li><span class="lv-main lv-muted">Keine kommenden Termine.</span></li>';
        // register takings
        var reg = d.register || {};
        $('lvReg').textContent = eur.format(reg.revenue || 0);
        $('lvRegSub').textContent = num(reg.tickets) + (reg.tickets === 1 ? ' Ticket' : ' Tickets') + ' an der Kasse';
        // devices
        var sc = d.scanners || [];
        $('lvScan').innerHTML = sc.length ? sc.map(function (s) {
            return '<li><span class="lv-main"><span class="lv-dot' + (s.last_seen_s > 8 ? ' lv-late' : '') + '"></span>' + esc(s.name) + ' <span class="lv-muted">· ' + esc(ROLE[s.role] || s.role) + '</span></span>' +
                '<span class="lv-side">' + num(s.scans) + ' · ' + s.last_seen_s + ' s</span></li>';
        }).join('') : '<li><span class="lv-main lv-muted">Kein Gerät verbunden.</span></li>';
    }

    function renderPanel(list, running) {
        var sig = JSON.stringify(list) + running;
        if (renderPanel.sig === sig) return;
        renderPanel.sig = sig;
        presets = list;
        $('lvPanel').innerHTML = list.map(function (p, i) {
            return '<button type="button" data-cat="' + esc(p.category) + '" data-i="' + i + '" title="' + esc(p.text) + '">' + esc(p.label) + '</button>';
        }).join('') + (running ? '<button type="button" class="lv-end" data-end="1">Durchsage beenden</button>' : '');
    }

    function send(endpoint, body) {
        if (busy) return;
        busy = true;
        $('lvPanel').querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch('../admin/admin-api-proxy.php?endpoint=' + endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(body)
        }).catch(function () {}).then(function () {
            busy = false;
            renderPanel.sig = null;
            poll();
        });
    }
    if (ADMIN) {
        $('lvPanel').addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b || busy) return;
            if (b.dataset.end) { send('broadcast_clear', {}); return; }
            var p = presets[Number(b.dataset.i)];
            // Presets from the dashboard run 5 minutes, for screens and staff.
            if (p) send('broadcast_send', { category: p.category, text: p.text, duration_min: 5, targets: ['screens', 'staff'] });
        });
    }

    var timer = null;
    function poll() {
        clearTimeout(timer);
        fetch('live.php?data=1' + (TOKEN ? '&token=' + encodeURIComponent(TOKEN) : ''), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 403) { location.reload(); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function (d) {
                if (!d || d.status !== 'success') throw new Error('bad');
                fails = 0;
                $('lv').classList.remove('is-offline');
                $('lvNet').textContent = 'Live';
                $('lvMsg').hidden = true;
                render(d);
            })
            .catch(function () {
                fails++;
                if (fails >= 2) {
                    $('lv').classList.add('is-offline');
                    $('lvNet').textContent = 'Offline';
                    $('lvMsg').hidden = false;
                }
            })
            .then(function () { clearTimeout(timer); timer = setTimeout(poll, fails ? 5000 : POLL_MS); });
    }
    tick(); setInterval(tick, 1000);
    poll();
})();
</script>
</body>
</html>
