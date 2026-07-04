<?php
require_once 'config.php';

// Language toggle (session-backed, ?lang override), mirrors datenschutz.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $_SESSION['language'] = in_array($_POST['language'], ['de', 'en'], true) ? $_POST['language'] : 'en';
    $q = http_build_query(['tid' => $_GET['tid'] ?? '', 'token' => $_GET['token'] ?? '']);
    header('Location: cancel.php?' . $q);
    exit();
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['de', 'en'], true)) {
    $_SESSION['language'] = $_GET['lang'];
}

$shows = getShows();
$orga_name = htmlspecialchars($shows['orga_name'] ?? 'QrGate');

$current_language = $_SESSION['language'] ?? 'en';
if (!in_array($current_language, ['de', 'en'], true)) {
    $current_language = 'en';
}
$is_de = $current_language === 'de';

// tid + token come from the email link. Kept opaque; the backend validates them.
$tid = (string) ($_GET['tid'] ?? '');
$token = (string) ($_GET['token'] ?? '');
$hasParams = $tid !== '' && $token !== '';

$pageTitle = $orga_name . ' – ' . ($is_de ? 'Ticket stornieren' : 'Cancel ticket');
$assetBase = '';

$extraHead = <<<HTML
<style>
  /* ---- entrance + ambient motion ---- */
  @keyframes cx-rise { from { opacity:0; transform:translateY(22px) scale(.98); } to { opacity:1; transform:none; } }
  @keyframes cx-drift1 { 0%,100% { transform:translate(0,0); } 50% { transform:translate(6%, 8%); } }
  @keyframes cx-drift2 { 0%,100% { transform:translate(0,0); } 50% { transform:translate(-7%, -5%); } }
  @keyframes cx-shimmer { 0% { background-position:-450px 0; } 100% { background-position:450px 0; } }
  @keyframes cx-spin { to { transform:rotate(360deg); } }
  @keyframes cx-shake { 10%,90% { transform:translateX(-2px); } 20%,80% { transform:translateX(4px); } 30%,50%,70% { transform:translateX(-8px); } 40%,60% { transform:translateX(8px); } }
  @keyframes cx-pop { 0% { transform:scale(.6); opacity:0; } 60% { transform:scale(1.08); } 100% { transform:scale(1); opacity:1; } }
  @keyframes cx-check { to { stroke-dashoffset:0; } }
  @keyframes cx-stamp { 0% { opacity:0; transform:translate(-50%,-50%) rotate(-18deg) scale(2.6); } 55% { opacity:1; } 70% { transform:translate(-50%,-50%) rotate(-14deg) scale(.92); } 100% { opacity:1; transform:translate(-50%,-50%) rotate(-14deg) scale(1); } }
  @keyframes cx-pulse { 0%,100% { box-shadow:0 0 0 0 color-mix(in oklab,var(--avo-primary) 45%,transparent); } 50% { box-shadow:0 0 0 10px transparent; } }

  .cx-wrap { position:relative; min-height:calc(100vh - 4rem); display:flex; align-items:center; justify-content:center; padding:6rem 1rem 3rem; overflow:hidden; }
  .cx-glow { position:absolute; border-radius:50%; filter:blur(70px); opacity:.5; pointer-events:none; z-index:0; }
  .cx-glow.a { width:420px; height:420px; top:-80px; left:-60px; background:color-mix(in oklab,var(--avo-primary) 45%,transparent); animation:cx-drift1 16s ease-in-out infinite; }
  .cx-glow.b { width:360px; height:360px; bottom:-90px; right:-40px; background:color-mix(in oklab,var(--avo-primary) 22%,transparent); animation:cx-drift2 19s ease-in-out infinite; }

  .cx-card { position:relative; z-index:1; width:100%; max-width:430px; animation:cx-rise .6s cubic-bezier(.22,1,.36,1) both; }

  /* ticket stub */
  .cx-ticket { position:relative; background:var(--avo-surface); border:1px solid var(--avo-border); border-radius:20px; overflow:hidden; box-shadow:0 24px 60px -30px rgba(0,0,0,.55); }
  .cx-ticket__top { position:relative; padding:1.6rem 1.5rem 1.3rem; background:linear-gradient(135deg,var(--avo-primary),color-mix(in oklab,var(--avo-primary) 60%,#000)); color:#fff; }
  .cx-eyebrow { font-family:var(--avo-font-mono,monospace); font-size:.7rem; font-weight:700; letter-spacing:.22em; opacity:.85; }
  .cx-event { font-family:var(--avo-font-display); font-size:1.5rem; font-weight:800; line-height:1.1; margin-top:.35rem; word-break:break-word; }
  /* perforation */
  .cx-perf { position:relative; height:0; border-top:2.5px dashed color-mix(in oklab,var(--avo-text-muted) 65%,transparent); margin:0; }
  /* Half-circles punched out of the card at the seam: centred on the dashed line
     and filled with the page background so they read as a real die-cut. */
  .cx-notch { position:absolute; top:-13px; width:26px; height:26px; border-radius:50%; background:var(--avo-bg); }
  .cx-notch.l { left:-13px; } .cx-notch.r { right:-13px; }
  .cx-ticket__body { padding:1.4rem 1.5rem 1.6rem; }

  .cx-rows { display:flex; flex-direction:column; gap:.1rem; }
  .cx-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.6rem 0; border-bottom:1px solid color-mix(in oklab,var(--avo-border) 55%,transparent); }
  .cx-row:last-child { border-bottom:0; }
  .cx-row__k { font-size:.68rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--avo-text-muted); }
  .cx-row__v { font-weight:700; color:var(--avo-text); text-align:right; word-break:break-word; }
  .cx-row__v.mono { font-family:var(--avo-font-mono,monospace); font-weight:600; font-size:.9rem; }

  .cx-seat { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .7rem; border-radius:999px; font-family:var(--avo-font-display); font-weight:800; font-size:1rem; color:var(--avo-primary); background:color-mix(in oklab,var(--avo-primary) 14%,transparent); border:1px solid color-mix(in oklab,var(--avo-primary) 40%,transparent); }

  /* skeleton */
  .cx-sk { background:color-mix(in oklab,var(--avo-text-muted) 18%,transparent); border-radius:7px; background-image:linear-gradient(90deg,transparent,color-mix(in oklab,var(--avo-text) 12%,transparent),transparent); background-size:450px 100%; background-repeat:no-repeat; animation:cx-shimmer 1.3s linear infinite; }

  /* buttons */
  .cx-btn { position:relative; width:100%; min-height:52px; border:0; border-radius:13px; font-weight:800; font-size:1rem; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:.5rem; transition:transform .12s ease, box-shadow .2s ease, filter .2s ease; }
  .cx-btn:active { transform:translateY(1px) scale(.995); }
  .cx-btn--danger { background:var(--avo-error); color:#fff; animation:cx-pulse 2.6s ease-in-out infinite; }
  .cx-btn--danger:hover { filter:brightness(1.07); box-shadow:0 14px 30px -12px var(--avo-error); }
  .cx-btn--ghost { background:transparent; color:var(--avo-text-muted); border:1px solid var(--avo-border); }
  .cx-btn--ghost:hover { color:var(--avo-text); border-color:var(--avo-primary); }
  .cx-btn[disabled] { opacity:.7; cursor:default; animation:none; }
  .cx-spin { width:20px; height:20px; border-radius:50%; border:2.5px solid rgba(255,255,255,.35); border-top-color:#fff; animation:cx-spin .7s linear infinite; }

  .cx-note { font-size:.85rem; line-height:1.6; color:var(--avo-text-muted); }

  /* result overlay inside body */
  .cx-result { display:flex; flex-direction:column; align-items:center; text-align:center; gap:.5rem; padding:.5rem 0 .25rem; animation:cx-pop .45s cubic-bezier(.22,1,.36,1) both; }
  .cx-badge { width:76px; height:76px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
  .cx-badge.ok { background:color-mix(in oklab,var(--avo-success) 18%,transparent); }
  .cx-badge.err { background:color-mix(in oklab,var(--avo-error) 18%,transparent); }
  .cx-badge svg { width:44px; height:44px; }
  .cx-badge .cx-draw { stroke-dasharray:48; stroke-dashoffset:48; animation:cx-check .5s .15s ease forwards; }
  .cx-res-title { font-family:var(--avo-font-display); font-size:1.35rem; font-weight:800; }
  .cx-res-title.ok { color:var(--avo-success); } .cx-res-title.err { color:var(--avo-error); }

  /* CANCELLED stamp over the stub */
  .cx-stamp { position:absolute; top:42%; left:50%; z-index:3; transform:translate(-50%,-50%) rotate(-14deg); border:4px solid var(--avo-error); color:var(--avo-error); font-family:var(--avo-font-display); font-weight:800; font-size:1.5rem; letter-spacing:.12em; padding:.3rem 1rem; border-radius:10px; text-transform:uppercase; opacity:0; background:color-mix(in oklab,var(--avo-bg) 55%,transparent); backdrop-filter:blur(1px); }
  .cx-stamp.show { animation:cx-stamp .6s cubic-bezier(.3,1.4,.5,1) both; }

  .cx-dim { transition:opacity .4s ease, filter .4s ease; }
  .cx-dim.off { opacity:.55; filter:saturate(.6); }
  .cx-fade-in { animation:cx-rise .4s ease both; }

  .cx-statusbadge { display:inline-flex; align-items:center; gap:.4rem; align-self:center; padding:.3rem .8rem; border-radius:999px; font-size:.8rem; font-weight:700; }
  .cx-statusbadge.warn { color:#b45309; background:color-mix(in oklab,#f59e0b 16%,transparent); }
  .cx-statusbadge.err { color:var(--avo-error); background:color-mix(in oklab,var(--avo-error) 14%,transparent); }

  @media (prefers-reduced-motion: reduce) {
    .cx-glow, .cx-btn--danger, .cx-sk { animation:none; }
    .cx-card, .cx-result, .cx-fade-in { animation:none; }
    .cx-stamp.show { opacity:1; animation:none; }
    .cx-badge .cx-draw { stroke-dashoffset:0; animation:none; }
  }
</style>
HTML;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language; ?>">
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="bg-background text-foreground min-h-screen flex flex-col pt-1">

    <div class="avo-topbar fixed top-0 left-0 right-0 z-50"></div>

    <nav class="fixed top-1 left-0 right-0 z-40 flex items-center justify-between px-7 py-2.5"
         style="background:color-mix(in oklab, var(--avo-bg) 80%, transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--avo-border);">
        <a href="index.php" class="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            <?php echo $is_de ? 'Zurück' : 'Back'; ?>
        </a>
        <span class="absolute left-1/2 -translate-x-1/2 text-sm font-bold text-muted-foreground" style="font-family:var(--avo-font-display)">
            <?php echo $orga_name; ?>
        </span>
        <form method="POST" class="ml-auto">
            <button type="submit" name="language" value="<?php echo $is_de ? 'en' : 'de'; ?>"
                class="h-8 px-3 rounded-full text-xs font-bold tracking-wider text-muted-foreground border bg-transparent hover:text-foreground transition-colors cursor-pointer avo-bordered hover:border-[var(--avo-primary)]">
                <?php echo $is_de ? '🇬🇧 EN' : '🇩🇪 DE'; ?>
            </button>
        </form>
    </nav>

    <main class="flex-1">
      <div class="cx-wrap">
        <div class="cx-glow a"></div>
        <div class="cx-glow b"></div>

        <div class="cx-card">
          <?php if (!$hasParams): ?>
            <div class="cx-ticket">
              <div class="cx-ticket__top"><div class="cx-eyebrow">// <?php echo $is_de ? 'STORNO' : 'CANCELLATION'; ?></div>
                <div class="cx-event"><?php echo $is_de ? 'Link unvollständig' : 'Incomplete link'; ?></div></div>
              <div class="cx-ticket__body">
                <p class="cx-note"><?php echo $is_de
                    ? 'Bitte öffne den Stornierungs-Link direkt aus deiner Ticket-E-Mail.'
                    : 'Please open the cancellation link directly from your ticket email.'; ?></p>
              </div>
            </div>
          <?php else: ?>
            <div class="cx-ticket" id="cxTicket">
              <span class="cx-stamp" id="cxStamp"><?php echo $is_de ? 'Storniert' : 'Cancelled'; ?></span>
              <div class="cx-ticket__top cx-dim" id="cxTop">
                <div class="cx-eyebrow">// TICKET</div>
                <div class="cx-event" id="cxEvent"><span class="cx-sk" style="display:inline-block;width:60%;height:1.4rem;">&nbsp;</span></div>
              </div>
              <div class="cx-perf"><span class="cx-notch l"></span><span class="cx-notch r"></span></div>
              <div class="cx-ticket__body">
                <!-- preview rows -->
                <div id="cxRows" class="cx-rows cx-dim">
                  <div class="cx-row"><span class="cx-row__k"><?php echo $is_de ? 'Datum' : 'Date'; ?></span><span class="cx-row__v"><span class="cx-sk" style="display:inline-block;width:90px;height:.9rem;">&nbsp;</span></span></div>
                  <div class="cx-row"><span class="cx-row__k">Ticket</span><span class="cx-row__v mono"><span class="cx-sk" style="display:inline-block;width:110px;height:.9rem;">&nbsp;</span></span></div>
                </div>

                <!-- action / result region -->
                <div id="cxAction" style="margin-top:1.4rem;"></div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <?php
    $orgName = $shows['orga_name'] ?? '';
    include __DIR__ . '/partials/footer.php';
    ?>

    <?php if ($hasParams): ?>
    <script>
        (function () {
            var IS_DE = <?php echo $is_de ? 'true' : 'false'; ?>;
            var TID = <?php echo json_encode($tid); ?>;
            var TOKEN = <?php echo json_encode($token); ?>;

            var T = {
                confirm:  IS_DE ? 'Ja, stornieren'            : 'Yes, cancel',
                keep:     IS_DE ? 'Ticket behalten'           : 'Keep ticket',
                working:  IS_DE ? 'Storniere…'                : 'Cancelling…',
                back:     IS_DE ? 'Zur Startseite'            : 'Back to start',
                warn:     IS_DE ? 'Der Platz wird freigegeben. Bei Online-Zahlung wird automatisch zurückerstattet. Stornierung bis 24 h vor der Veranstaltung möglich.'
                                : 'The seat is released. Online payments are refunded automatically. Cancellation is possible up to 24 h before the event.',
                okTitle:  IS_DE ? 'Ticket storniert'          : 'Ticket cancelled',
                okBody:   IS_DE ? 'Dein Platz wurde freigegeben.' : 'Your seat has been released.',
                okRefund: IS_DE ? ' Der Betrag wird zurückerstattet.' : ' The amount is being refunded.',
                errTitle: IS_DE ? 'Hat nicht geklappt'        : 'Something went wrong',
                network:  IS_DE ? 'Verbindung fehlgeschlagen. Bitte später erneut versuchen.' : 'Connection failed. Please try again later.',
                deadline: IS_DE ? 'Die Stornofrist ist abgelaufen (bis 24 h vor der Veranstaltung). Bitte wende dich vor Ort an die Kasse.'
                                : 'The cancellation deadline has passed (up to 24 h before the event). Please ask at the box office on site.',
                already:  IS_DE ? 'Dieses Ticket wurde bereits storniert.' : 'This ticket has already been cancelled.',
                used:     IS_DE ? 'Dieses Ticket wurde bereits am Einlass verwendet und kann nicht storniert werden.' : 'This ticket was already used at the door and can\'t be cancelled.',
                cannot:   IS_DE ? 'Dieses Ticket kann nicht online storniert werden.' : 'This ticket can\'t be cancelled online.',
                notfound: IS_DE ? 'Ticket nicht gefunden.'    : 'Ticket not found.',
                forbidden:IS_DE ? 'Ungültiger oder abgelaufener Link.' : 'Invalid or expired link.',
                seat:     IS_DE ? 'Sitzplatz'                 : 'Seat',
                date:     IS_DE ? 'Datum'                     : 'Date',
                name:     IS_DE ? 'Name'                      : 'Name',
                loc:      IS_DE ? 'Ort'                       : 'Location'
            };
            var MONTHS = ['01','02','03','04','05','06','07','08','09','10','11','12'];

            var el = function (id) { return document.getElementById(id); };
            function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
            function fmtDate(s) {
                var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
                return m ? (m[3] + '.' + m[2] + '.' + m[1]) : (s || '');
            }
            var SVG_CHECK = '<svg viewBox="0 0 52 52" fill="none" stroke="var(--avo-success)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path class="cx-draw" d="M14 27l8 8 16-18"/></svg>';
            var SVG_X = '<svg viewBox="0 0 52 52" fill="none" stroke="var(--avo-error)" stroke-width="4" stroke-linecap="round"><path class="cx-draw" style="stroke-dasharray:24;stroke-dashoffset:24;" d="M18 18l16 16"/><path class="cx-draw" style="stroke-dasharray:24;stroke-dashoffset:24;animation-delay:.25s;" d="M34 18L18 34"/></svg>';

            function renderRows(d) {
                var rows = '';
                var dateStr = fmtDate(d.valid_date) + (d.event_time ? ' · ' + esc(d.event_time) : '');
                rows += '<div class="cx-row"><span class="cx-row__k">' + T.date + '</span><span class="cx-row__v">' + (esc(dateStr) || '—') + '</span></div>';
                if (d.seat_label) {
                    rows += '<div class="cx-row"><span class="cx-row__k">' + T.seat + '</span><span class="cx-row__v"><span class="cx-seat">🎟 ' + esc(d.seat_label) + '</span></span></div>';
                }
                if (d.location) {
                    rows += '<div class="cx-row"><span class="cx-row__k">' + T.loc + '</span><span class="cx-row__v">' + esc(d.location) + '</span></div>';
                }
                if (d.first_name) {
                    rows += '<div class="cx-row"><span class="cx-row__k">' + T.name + '</span><span class="cx-row__v">' + esc(d.first_name) + '</span></div>';
                }
                rows += '<div class="cx-row"><span class="cx-row__k">Ticket</span><span class="cx-row__v mono">' + esc(TID) + '</span></div>';
                var rowsEl = el('cxRows');
                rowsEl.classList.add('cx-fade-in');
                rowsEl.innerHTML = rows;
            }

            function setEvent(name) {
                el('cxEvent').textContent = name || (IS_DE ? 'Ticket' : 'Ticket');
            }

            function showConfirm() {
                el('cxAction').innerHTML =
                    '<p class="cx-note" style="margin-bottom:1rem;">' + esc(T.warn) + '</p>' +
                    '<button type="button" id="cxGo" class="cx-btn cx-btn--danger">' + esc(T.confirm) + '</button>' +
                    '<a href="index.php" class="cx-btn cx-btn--ghost" style="margin-top:.6rem;text-decoration:none;">' + esc(T.keep) + '</a>';
                el('cxGo').addEventListener('click', doCancel);
            }

            function showBlocked(kind, text) {
                var cls = kind === 'err' ? 'err' : 'warn';
                el('cxTop').classList.add('off');
                el('cxRows').classList.add('off');
                el('cxAction').innerHTML =
                    '<div class="cx-fade-in" style="display:flex;flex-direction:column;gap:.9rem;">' +
                    '<span class="cx-statusbadge ' + cls + '">' + esc(text) + '</span>' +
                    '<a href="index.php" class="cx-btn cx-btn--ghost" style="text-decoration:none;">' + esc(T.back) + '</a></div>';
            }

            function showResult(ok, extraHtml) {
                if (ok) {
                    el('cxStamp').classList.add('show');
                    el('cxTop').classList.add('off');
                    el('cxRows').classList.add('off');
                }
                el('cxAction').innerHTML =
                    '<div class="cx-result">' +
                    '<div class="cx-badge ' + (ok ? 'ok' : 'err') + '">' + (ok ? SVG_CHECK : SVG_X) + '</div>' +
                    '<div class="cx-res-title ' + (ok ? 'ok' : 'err') + '">' + esc(ok ? T.okTitle : T.errTitle) + '</div>' +
                    '<p class="cx-note">' + extraHtml + '</p>' +
                    '<a href="index.php" class="cx-btn cx-btn--ghost" style="margin-top:.6rem;text-decoration:none;">' + esc(T.back) + '</a>' +
                    '</div>';
            }

            function doCancel() {
                var btn = el('cxGo');
                btn.disabled = true;
                btn.innerHTML = '<span class="cx-spin"></span>' + esc(T.working);
                fetch('cancel-proxy.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ tid: TID, token: TOKEN }),
                    cache: 'no-store'
                })
                .then(function (r) { return r.json().then(function (j) { return j; }); })
                .then(function (j) {
                    j = j || {};
                    if (j.status === 'success') {
                        var body = esc(T.okBody) + (j.refund_id ? esc(T.okRefund) : '');
                        showResult(true, body);
                        return;
                    }
                    var code = j.code || '', m = String(j.message || ''), text;
                    if (code === 'deadline_passed') text = T.deadline;
                    else if (/already cancelled/i.test(m)) text = T.already;
                    else if (/not found/i.test(m)) text = T.notfound;
                    else if (/forbidden/i.test(m)) text = T.forbidden;
                    else if (/online/i.test(m)) text = T.cannot;
                    else text = T.network;
                    // shake the card, then show the error result
                    var card = el('cxTicket');
                    card.style.animation = 'cx-shake .5s';
                    setTimeout(function () { card.style.animation = ''; showResult(false, '<span style="color:var(--avo-error)">' + esc(text) + '</span>'); }, 480);
                })
                .catch(function () {
                    showResult(false, '<span style="color:var(--avo-error)">' + esc(T.network) + '</span>');
                });
            }

            // ---- initial preview load (read-only) ----
            fetch('cancel-proxy.php?tid=' + encodeURIComponent(TID) + '&token=' + encodeURIComponent(TOKEN), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.status !== 'success' || !j.data) {
                    var mm = String((j && j.message) || '');
                    setEvent(IS_DE ? 'Ticket' : 'Ticket');
                    showBlocked('err', /not found/i.test(mm) ? T.notfound : (/forbidden/i.test(mm) ? T.forbidden : T.network));
                    return;
                }
                var d = j.data;
                setEvent(d.event_name);
                renderRows(d);
                if (d.cancellable) { showConfirm(); }
                else if (d.cancelled) { showResult(true, esc(IS_DE ? 'Dieses Ticket ist bereits storniert.' : 'This ticket is already cancelled.')); el('cxStamp').classList.add('show'); }
                else if (d.used) { showBlocked('warn', T.used); }
                else if (d.deadline_passed) { showBlocked('warn', T.deadline); }
                else { showBlocked('warn', T.cannot); }
            })
            .catch(function () { setEvent(IS_DE ? 'Ticket' : 'Ticket'); showBlocked('err', T.network); });
        })();
    </script>
    <?php endif; ?>

</body>
</html>
