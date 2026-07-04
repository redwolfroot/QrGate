<?php
require_once '../../config.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['handheld_access'])) {
    header('Location: ../login.php?redirect=handheld');
    exit;
}
if (!empty($_SESSION['must_change_pw'])) {
    header('Location: ../change_password.php');
    exit;
}

$API_KEY = API_KEY;
$API_ENDPOINT = API_BASE_URL . 'api/ticket/validate';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $ticketId = $data['ticketId'] ?? null;

    if ($ticketId) {
        $ch = curl_init($API_ENDPOINT);
        $requestData = json_encode(['tid' => $ticketId]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $API_KEY
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo $response;
        exit;
    } else {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Keine ticketId angegeben.'
        ]);
        exit;
    }
}

$pageTitle = 'Ticket Scanner';
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
<html lang="de">

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
                    <div class="hh-bar__title">Scanner</div>
                </div>
            </div>
            <div class="hh-bar__actions">
                <span id="hhNet" class="hh-net" role="status" aria-live="polite"
                    title="Verbindungsstatus">
                    <span id="hhNetDot" class="hh-net__dot"></span>
                    <span id="hhNetLabel" class="hh-net__label">Online</span>
                </span>
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
                        placeholder="Ticket-ID manuell eingeben" aria-label="Ticket-ID manuell eingeben">
                    <button id="hhManualBtn" class="hh-manual__btn" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14" /><path d="m12 5 7 7-7 7" />
                        </svg>
                        Prüfen
                    </button>
                </div>
                <!-- auto-advance: VALID results auto-dismiss after ~1.2s (green
                     flash + sound kept). FAIL always waits for a manual tap. -->
                <label class="hh-aa">
                    <input id="hhAutoAdvance" type="checkbox">
                    Auto-Weiter bei gültig
                </label>
            </div>
            <div id="hhClock" class="hh-clock"></div>
            <div class="hh-seg">
                <a href="index.php" class="active">
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
            </div>
        </nav>
    </div>

    <!-- result sheet -->
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
                Weiter scannen
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
        window.HH_CONFIG = {
            mode: 'validate',
            payloadKey: 'ticketId',
            validText: 'Gültig',
            invalidText: 'Ungültig',
            reuseText: 'Bereits eingelöst!',
            reuseHint: 'Zuerst gescannt:',
            showTimeline: false
        };
    </script>
    <script src="./scanner.js"></script>
</body>

</html>
