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
require __DIR__ . '/_live.php';

$API_KEY = API_KEY;
$API_ENDPOINT = API_BASE_URL . 'api/ticket/get';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $ticketId = $data['tid'] ?? null;

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
            'message' => 'Keine tid angegeben.'
        ]);
        exit;
    }
}

$pageTitle = 'Ticket Inspector';
$assetBase = '../../';
$forceDark = true; // handheld is a kiosk-style app — lock to dark
$extraHead = <<<'HTML'
    <link rel="stylesheet" href="./handheld.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>

    <!-- PWA -->
    <link rel="manifest" href="./manifest.json">
    <link rel="apple-touch-icon" href="./icon-192x192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('./sw.js').catch(function () {});
            });
        }
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
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </span>
                <div>
                    <div class="hh-bar__kicker">// handheld</div>
                    <div class="hh-bar__title">Inspector</div>
                </div>
            </div>
            <div class="hh-bar__actions">
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
                <div class="hh-frame__hint">QR-Code zum Prüfen scannen</div>
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
                <a href="inspector.php" class="active">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
                    </svg>
                    Inspector
                </a>
                <a href="kasse.php">
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
                    <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
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
            mode: 'inspect',
            payloadKey: 'tid',
            validText: 'Gefunden',
            invalidText: 'Nicht gefunden',
            showTimeline: true
        };
    </script>
    <script src="./scanner.js"></script>
</body>

</html>
