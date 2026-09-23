<?php
require_once '../../config.php';

// Box office staff (TicketFlow) may use the register scanner too.
if (!isset($_SESSION['admin']) && !isset($_SESSION['handheld_access']) && !isset($_SESSION['ticketflow_access'])) {
    header('Location: ../login.php?redirect=handheld');
    exit;
}
if (!empty($_SESSION['must_change_pw'])) {
    header('Location: ../change_password.php');
    exit;
}

/**
 * Register scanner ("Kasse"): pairs this phone with a TicketFlow register by
 * the 4-digit code the register shows. Each scan is sent to that register,
 * which opens the ticket for payment. The pairing (code + handheld token)
 * lives in this PHP session; the backend keeps the live link.
 */
function hh_pair_api($endpoint, $data)
{
    $ch = curl_init(rtrim(API_BASE_URL, '/') . '/' . $endpoint);
    $headers = ['Content-Type: application/json', 'Authorization: ' . API_KEY];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : json_decode($body, true)];
}

function hh_json($code, $data)
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$pair = $_SESSION['hh_pair'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        hh_json(400, ['status' => 'error', 'message' => 'Ungültige Anfrage.']);
    }
    $action = (string) ($in['action'] ?? 'scan');

    if ($action === 'join') {
        $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
        if (strlen($code) !== 4) {
            hh_json(400, ['status' => 'error', 'message' => 'Der Code hat 4 Ziffern.']);
        }
        [$http, $r] = hh_pair_api('api/boxoffice/pair/join', ['code' => $code]);
        if ($http === 200 && ($r['status'] ?? '') === 'success') {
            $_SESSION['hh_pair'] = ['code' => $code, 'token' => (string) $r['token'], 'seller' => (string) ($r['seller'] ?? '')];
            hh_json(200, ['status' => 'success', 'seller' => $r['seller'] ?? '']);
        }
        if ($http === 429) {
            hh_json(429, ['status' => 'error', 'message' => 'Zu viele Versuche. Bitte kurz warten.']);
        }
        hh_json($http === 410 ? 404 : 502, ['status' => 'error', 'message' => $http === 410
            ? 'Keine Kasse mit diesem Code. Code am PC prüfen.'
            : 'Keine Verbindung zum Server.']);
    }

    if ($action === 'leave') {
        unset($_SESSION['hh_pair']);
        hh_json(200, ['status' => 'success']);
    }

    if (!$pair) {
        hh_json(410, ['status' => 'error', 'message' => 'pair_gone']);
    }

    if ($action === 'ping') {
        [$http, $r] = hh_pair_api('api/boxoffice/pair/ping', ['code' => $pair['code'], 'token' => $pair['token']]);
        if ($http === 410) {
            unset($_SESSION['hh_pair']);
        }
        hh_json($http ?: 502, $r ?: ['status' => 'error', 'message' => 'unavailable']);
    }

    // scan: send the ticket to the paired register
    $tid = trim((string) ($in['tid'] ?? ''));
    if ($tid === '') {
        hh_json(400, ['status' => 'error', 'message' => 'Keine Ticket-ID.']);
    }
    [$http, $r] = hh_pair_api('api/boxoffice/pair/scan', ['code' => $pair['code'], 'token' => $pair['token'], 'tid' => $tid]);
    if ($http === 410) {
        unset($_SESSION['hh_pair']);
        hh_json(410, ['status' => 'error', 'message' => 'pair_gone']);
    }
    if ($http === 404) {
        hh_json(200, ['status' => 'error', 'message' => 'Ticket nicht gefunden.', 'tid' => $tid]);
    }
    if ($http !== 200 || !is_array($r)) {
        hh_json(502, ['status' => 'error', 'message' => 'Keine Verbindung zur Kasse.']);
    }
    hh_json(200, $r);
}

$pageTitle = 'Kassen-Scanner';
$assetBase = '../../';
$forceDark = true; // handheld is a kiosk-style app — lock to dark
$extraHead = <<<'HTML'
    <link rel="stylesheet" href="./handheld.css">
    <style>
        /* connectivity pill — always visible so door staff can see the door's
           link health at a glance. Hint only; never affects ticket verdicts. */
        .hh-net {
            display: inline-flex; align-items: center; gap: 6px;
            height: 42px; padding: 0 12px;
            border-radius: var(--avo-radius-pill);
            border: 1px solid var(--avo-border);
            background: var(--avo-surface);
            font-size: 0.72rem; font-weight: 700; line-height: 1;
            white-space: nowrap; flex-shrink: 0;
        }
        .hh-net__dot {
            width: 9px; height: 9px; border-radius: 999px;
            background: var(--avo-text-muted); flex-shrink: 0;
        }
        .hh-net.is-online {
            color: var(--avo-success);
            border-color: color-mix(in oklab, var(--avo-success) 45%, var(--avo-border));
        }
        .hh-net.is-online .hh-net__dot { background: var(--avo-success); }
        .hh-net.is-offline {
            color: var(--avo-error);
            border-color: color-mix(in oklab, var(--avo-error) 50%, var(--avo-border));
        }
        .hh-net.is-offline .hh-net__dot { background: var(--avo-error); }
        .hh-net.is-reconnecting {
            color: #d98a00;
            border-color: color-mix(in oklab, #d98a00 50%, var(--avo-border));
        }
        .hh-net.is-reconnecting .hh-net__dot {
            background: #d98a00; animation: hh-net-pulse 0.9s ease-in-out infinite;
        }
        @keyframes hh-net-pulse { 50% { opacity: 0.25; } }

        /* dock extras: manual entry + auto-advance toggle */
        .hh-dock__extras { display: flex; flex-direction: column; gap: 10px; }
        .hh-manual {
            display: none; gap: 8px; align-items: stretch;
        }
        .hh-manual.show { display: flex; }
        .hh-manual__input {
            flex: 1 1 auto; min-width: 0;
            padding: 12px 14px;
            border: 1px solid var(--avo-border);
            border-radius: var(--avo-radius-md);
            background: var(--avo-bg);
            color: var(--avo-text);
            font-family: var(--avo-font-mono); font-size: 0.95rem;
        }
        .hh-manual__input:focus {
            outline: none;
            border-color: var(--avo-primary);
        }
        .hh-manual__input::placeholder { color: var(--avo-text-muted); }
        .hh-manual__btn {
            flex: 0 0 auto;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 16px;
            border: 0; border-radius: var(--avo-radius-md);
            background: var(--avo-primary); color: #fff;
            font-weight: 700; font-size: 0.9rem; cursor: pointer;
        }
        .hh-manual__btn:active { transform: translateY(1px); }
        .hh-aa {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            font-size: 0.72rem; font-weight: 700;
            color: var(--avo-text-muted);
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .hh-aa input { width: 16px; height: 16px; accent-color: var(--avo-primary); }
        /* register pairing (Kasse) */
        .hh-pair {
            position: fixed; inset: 0; z-index: 40;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 18px; padding: 28px 24px;
            background: var(--avo-bg); text-align: center;
        }
        .hh-pair[hidden] { display: none; }
        .hh-pair h2 { margin: 0; font-size: 1.35rem; }
        .hh-pair p { margin: 0; max-width: 30ch; color: var(--avo-text-muted); font-size: 0.9rem; line-height: 1.5; }
        .hh-pair__code {
            width: 100%; max-width: 260px; height: 72px;
            text-align: center; font-family: var(--avo-font-mono);
            font-size: 2.4rem; letter-spacing: 0.5em; padding-left: 0.5em;
            color: var(--avo-text); background: var(--avo-surface);
            border: 1px solid var(--avo-border); border-radius: var(--avo-radius-md);
        }
        .hh-pair__code:focus { outline: none; border-color: var(--avo-primary); }
        .hh-pair__err { min-height: 1.3em; color: var(--avo-error); font-size: 0.85rem; }
        .hh-pair .hh-bigbtn { width: 100%; max-width: 260px; justify-content: center; }
        .hh-pair__back { color: var(--avo-text-muted); font-size: 0.8rem; text-decoration: underline; }
        .hh-reg {
            display: inline-flex; align-items: center; gap: 8px;
            height: 42px; padding: 0 5px 0 11px;
            border-radius: var(--avo-radius-pill);
            border: 1px solid color-mix(in oklab, var(--avo-success) 45%, var(--avo-border));
            background: var(--avo-surface); color: var(--avo-success);
            font-family: var(--avo-font-mono); font-size: 0.78rem; font-weight: 700;
            white-space: nowrap; flex-shrink: 0;
        }
        .hh-reg.is-lost { color: var(--avo-error); border-color: color-mix(in oklab, var(--avo-error) 50%, var(--avo-border)); }
        .hh-reg__dot { width: 9px; height: 9px; border-radius: 999px; background: currentColor; }
        .hh-reg button {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; padding: 0; border: 0; border-radius: var(--avo-radius-pill);
            background: var(--avo-surface-raised, rgba(255,255,255,.06)); color: var(--avo-text-muted);
            font: inherit; font-weight: 600; cursor: pointer;
        }
        .hh-sent { font-size: 0.95rem; }
        .hh-sent .mono { font-family: var(--avo-font-mono); }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>

    <!-- PWA -->
    <link rel="manifest" href="./manifest.json">
    <link rel="apple-touch-icon" href="./icon-192x192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="QR Scanner">
    <meta name="mobile-web-app-capable" content="yes">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('./sw.js').catch(function () {});
            });
        }
        // PWA install — surfaces the install icon in the top bar when offered
        let hhDeferredPrompt = null;
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            hhDeferredPrompt = e;
            var b = document.getElementById('hhInstall');
            if (b) {
                b.style.display = 'inline-flex';
                b.onclick = function () {
                    hhDeferredPrompt.prompt();
                    hhDeferredPrompt.userChoice.finally(function () {
                        hhDeferredPrompt = null;
                        b.style.display = 'none';
                    });
                };
            }
        });
        window.addEventListener('appinstalled', function () {
            var b = document.getElementById('hhInstall');
            if (b) b.style.display = 'none';
        });
    </script>
HTML;
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">

<?php include __DIR__ . '/../../partials/head.php'; ?>

<body>
    <div class="hh-app">
        <!-- top bar -->
        <header class="hh-bar">
            <div class="hh-bar__brand">
                <span class="hh-bar__icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 12v4a1 1 0 0 1-1 1h-4" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M17 8V7" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M7 17h.01" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                        <rect x="7" y="7" width="5" height="5" rx="1" />
                    </svg>
                </span>
                <div>
                    <div class="hh-bar__kicker">// handheld</div>
                    <div class="hh-bar__title">Kasse</div>
                </div>
            </div>
            <div class="hh-bar__actions">
                <?php if ($pair): ?>
                <span id="hhReg" class="hh-reg" role="status" aria-live="polite" title="Gekoppelte Kasse">
                    <span class="hh-reg__dot"></span>
                    <span id="hhRegLabel"><?php echo htmlspecialchars($pair['code']); ?></span>
                    <button type="button" id="hhLeave" aria-label="Von der Kasse trennen" title="Trennen">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                        </svg>
                    </button>
                </span>
                <?php endif; ?>
                <button id="hhInstall" class="hh-iconbtn" style="display:none" aria-label="App installieren">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7" />
                        <path d="M12 2v12" />
                        <path d="m8 10 4 4 4-4" />
                    </svg>
                </button>
                <button id="hhTorch" class="hh-iconbtn" style="display:none" aria-label="Taschenlampe">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6c0 2-2 2-2 4v9a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-9c0-2-2-2-2-4V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1z" />
                        <path d="M6 6h12" />
                        <path d="M12 12v3" />
                    </svg>
                </button>
                <a href="../apps.php" class="hh-iconbtn" aria-label="App wechseln">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/>
                        <rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>
                    </svg>
                </a>
                <a href="../logout.php" class="hh-iconbtn hh-iconbtn--danger" aria-label="Logout">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m16 17 5-5-5-5" />
                        <path d="M21 12H9" />
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    </svg>
                </a>
            </div>
        </header>

        <!-- camera stage -->
        <div class="hh-stage">
            <div id="reader"></div>
            <div class="hh-frame">
                <div class="hh-frame__box">
                    <span></span><span></span><span></span><span></span>
                    <div class="hh-frame__laser"></div>
                </div>
                <div class="hh-frame__hint">QR-Code im Rahmen positionieren</div>
            </div>
            <div id="hhStart" class="hh-start">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m2 2 20 20" />
                    <path d="M9 9a3 3 0 0 0 4.24 4.24" />
                    <path d="M16.07 16.07A6.5 6.5 0 0 1 6 12V8" />
                    <path d="M3.59 3.59A2 2 0 0 0 3 5v3" />
                    <path d="M14 6h6a2 2 0 0 1 2 2v3" />
                </svg>
                <h2>Kamera starten</h2>
                <p id="hhStartMsg">Tippe, um den Scanner zu starten.</p>
                <button id="hhStartBtn" class="hh-bigbtn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="6 3 20 12 6 21 6 3" />
                    </svg>
                    Scanner starten
                </button>
            </div>
        </div>

        <!-- bottom dock -->
        <nav class="hh-dock">
            <div class="hh-dock__extras">
                <!-- manual ticket-id fallback: damaged/unscannable QR, or a phone
                     whose camera permission was denied. Goes through the SAME
                     server validate endpoint as a scan — no client-side admit. -->
                <div id="hhManual" class="hh-manual show">
                    <input id="hhManualInput" class="hh-manual__input" type="text"
                        inputmode="text" autocomplete="off" autocapitalize="characters"
                        spellcheck="false" enterkeyhint="go"
                        placeholder="Ticket-ID eingeben" aria-label="Ticket-ID eingeben">
                    <button id="hhManualBtn" class="hh-manual__btn" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14" /><path d="m12 5 7 7-7 7" />
                        </svg>
                        Senden
                    </button>
                </div>
                <!-- auto-advance: VALID results auto-dismiss after ~1.2s (green
                     flash + sound kept). FAIL always waits for a manual tap. -->
                <label class="hh-aa">
                    <input id="hhAutoAdvance" type="checkbox" checked>
                    Nach dem Senden weiter scannen
                </label>
            </div>
            <div id="hhClock" class="hh-clock"></div>
            <div class="hh-seg">
                <a href="index.php">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 12v4a1 1 0 0 1-1 1h-4" /><path d="M17 3h2a2 2 0 0 1 2 2v2" /><path d="M17 8V7" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" /><path d="M3 7V5a2 2 0 0 1 2-2h2" /><path d="M7 17h.01" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" /><rect x="7" y="7" width="5" height="5" rx="1" />
                    </svg>
                    Scanner
                </a>
                <a href="inspector.php">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
                    </svg>
                    Inspector
                </a>
                <a href="kasse.php" class="active">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="20" height="14" x="2" y="5" rx="2" /><path d="M2 10h20" />
                    </svg>
                    Kasse
                </a>
            </div>
        </nav>
    </div>

    <!-- result sheet -->
    <!-- pairing: shown until this phone is linked to a register -->
    <section id="hhPair" class="hh-pair" <?php echo $pair ? 'hidden' : ''; ?>>
        <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect width="20" height="14" x="2" y="3" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" />
        </svg>
        <h2>Mit Kasse verbinden</h2>
        <p>In TicketFlow am PC auf „Scanner koppeln“ tippen und den 4-stelligen Code hier eingeben.</p>
        <form id="hhPairForm" autocomplete="off" style="display:contents">
            <input id="hhPairCode" class="hh-pair__code" type="text" inputmode="numeric" pattern="[0-9]*"
                maxlength="4" autocomplete="one-time-code" aria-label="Kassen-Code" placeholder="····">
            <div id="hhPairErr" class="hh-pair__err" role="alert"></div>
            <button type="submit" class="hh-bigbtn">Verbinden</button>
        </form>
        <a class="hh-pair__back" href="index.php">Zurück zum Einlass-Scanner</a>
    </section>

    <div id="hhResult" class="hh-result">
        <div class="hh-result__head">
            <div id="hhResultIcon" class="hh-result__icon"></div>
            <div id="hhResultStatus" class="hh-result__status"></div>
            <div id="hhResultMsg" class="hh-result__msg"></div>
        </div>
        <div id="hhResultBody" class="hh-result__body"></div>
        <div class="hh-result__foot">
            <button id="hhDismiss" class="hh-bigbtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 12v4a1 1 0 0 1-1 1h-4" /><path d="M17 3h2a2 2 0 0 1 2 2v2" /><path d="M17 8V7" />
                    <path d="M21 17v2a2 2 0 0 1-2 2h-2" /><path d="M3 7V5a2 2 0 0 1 2-2h2" /><path d="M7 17h.01" />
                    <path d="M7 21H5a2 2 0 0 1-2-2v-2" /><rect x="7" y="7" width="5" height="5" rx="1" />
                </svg>
                Nächstes Ticket
            </button>
        </div>
    </div>

    <div id="hhSpinner" class="hh-spinner">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Loading">
            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
        </svg>
    </div>
    <div id="hhToast" class="hh-toast"></div>

    <script>
        (function () {
            var PAIRED = <?php echo $pair ? 'true' : 'false'; ?>;
            function esc(s) {
                return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
                    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
                });
            }
            function money(n) {
                return Number(n).toLocaleString("de-DE", { style: "currency", currency: "EUR" });
            }
            function day(iso) {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || "");
                return m ? m[3] + "." + m[2] + "." + m[1] : (iso || "");
            }
            function post(body) {
                return fetch(location.href, {
                    method: "POST", headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(body), cache: "no-store"
                }).then(function (r) {
                    return r.json().then(function (d) { return { status: r.status, data: d }; },
                        function () { return { status: r.status, data: null }; });
                });
            }
            function showPairing(msg) {
                document.getElementById("hhPair").hidden = false;
                document.getElementById("hhPairErr").textContent = msg || "";
                setTimeout(function () { document.getElementById("hhPairCode").focus(); }, 50);
            }

            window.HH_CONFIG = {
                mode: "register",
                payloadKey: "tid",
                validText: "An Kasse gesendet",
                invalidText: "Nicht gesendet",
                showTimeline: false,
                autostart: PAIRED,
                onGone: function () {
                    var chip = document.getElementById("hhReg");
                    if (chip) chip.classList.add("is-lost");
                    showPairing("Die Kasse ist nicht mehr verbunden. Bitte neu koppeln.");
                },
                renderBody: function (d) {
                    var t = d.ticket;
                    if (!t) return d.tid ? '<p class="hh-sent">Ticket <span class="mono">' + esc(d.tid) + "</span></p>" : "";
                    var state = t.cancelled ? '<span class="hh-pill no">Storniert</span>'
                        : t.paid ? '<span class="hh-pill ok">Schon bezahlt</span>'
                        : '<span class="hh-pill no">Offen' + (t.price != null ? " · " + esc(money(t.price)) : "") + "</span>";
                    var rows = [
                        ["Ticket", '<span class="mono">' + esc(t.tid) + "</span>"],
                        ["Name", esc(t.name) || "&mdash;"],
                        ["Termin", esc(day(t.valid_date))],
                        ["Status", state]
                    ];
                    if (t.seat_label) rows.splice(3, 0, ["Platz", esc(t.seat_label)]);
                    return '<div class="hh-rows">' + rows.map(function (r) {
                        return '<div class="hh-row"><div class="hh-row__main"><div class="hh-row__label">' + r[0] +
                            '</div><div class="hh-row__value">' + r[1] + "</div></div></div>";
                    }).join("") + "</div>";
                }
            };

            document.addEventListener("DOMContentLoaded", function () {
                var form = document.getElementById("hhPairForm");
                var input = document.getElementById("hhPairCode");
                input.addEventListener("input", function () {
                    input.value = input.value.replace(/\D/g, "").slice(0, 4);
                    if (input.value.length === 4) form.requestSubmit();
                });
                form.addEventListener("submit", function (e) {
                    e.preventDefault();
                    var err = document.getElementById("hhPairErr");
                    err.textContent = "";
                    post({ action: "join", code: input.value }).then(function (res) {
                        if (res.data && res.data.status === "success") { location.reload(); return; }
                        err.textContent = (res.data && res.data.message) || "Verbindung fehlgeschlagen.";
                        input.select();
                    }, function () { err.textContent = "Keine Verbindung."; });
                });
                var leave = document.getElementById("hhLeave");
                if (leave) leave.addEventListener("click", function () {
                    post({ action: "leave" }).then(function () { location.reload(); });
                });
                if (!PAIRED) { showPairing(""); return; }
                // heartbeat: the register shows the scanner as connected, and
                // a register that went away is noticed before the next scan
                function ping() {
                    if (document.hidden) return;
                    post({ action: "ping" }).then(function (res) {
                        if (res.status === 410) window.HH_CONFIG.onGone();
                    }, function () {});
                }
                ping();
                setInterval(ping, 20000);
                document.addEventListener("visibilitychange", ping);
            });
        })();
    </script>
    <script src="./scanner.js"></script>
</body>

</html>
