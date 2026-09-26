<?php
require_once "../config.php";

if (!isset($_SESSION["admin"]) || $_SESSION["admin"] !== true) {
    header("Location: login.php");
    exit();
}
if (!empty($_SESSION["must_change_pw"])) {
    header("Location: change_password.php");
    exit();
}

// Admin session only: the full show is read server-side. Nothing secret is
// handed to the page — the Stripe keys are reduced to "set / not set".
$shows = getShows();
$csrf = generateCsrfToken();
$username = $_SESSION['username'] ?? 'admin';

$stripe = $shows['stripe'] ?? [];
$pub = (string)($stripe['publishable_key'] ?? '');
$dates = [];
foreach (($shows['dates'] ?? []) as $id => $d) {
    $dates[] = [
        'id' => (string)$id,
        'date' => $d['date'] ?? '',
        'time' => $d['time'] ?? '',
        'tickets' => (int)($d['tickets'] ?? 0),
        'available' => (int)($d['tickets_available'] ?? 0),
        'price' => (float)($d['price'] ?? 0),
        'location' => (string)($d['location'] ?? ''),
        'seating' => !empty($d['seating']),
    ];
}
usort($dates, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));

$boot = [
    'csrf' => $csrf,
    'imageBase' => PUBLIC_API_BASE,
    'ok' => $shows !== null,
    'show' => [
        'orga_name' => $shows['orga_name'] ?? '',
        'title' => $shows['title'] ?? '',
        'subtitle' => $shows['subtitle'] ?? '',
        'contact_email' => $shows['contact_email'] ?? '',
        'app_domain' => $shows['app_domain'] ?? '',
        'reminder_enabled' => !empty($shows['reminder_enabled']),
        'reminder_days' => (int)($shows['reminder_days'] ?? 1),
        'store_lock' => !empty($shows['store_lock']),
        'payment_methods' => $shows['payment_methods'] ?? 'both',
        'locations' => (object)($shows['locations'] ?? []),
        'dates' => $dates,
        'boxoffice_categories' => array_values($shows['boxoffice_categories'] ?? []),
        'screens' => $shows['screens'] ?? null,
    ],
    'stripe' => [
        'publishable_key' => $pub,
        'secret_set' => !empty($stripe['secret_key']),
        'webhook_set' => !empty($stripe['webhook_secret']),
        'live' => strpos($pub, 'pk_live_') === 0,
    ],
];

$pageTitle = 'QrGate · Admin';
$assetBase = '../';
$extraHead = '<link rel="stylesheet" href="admin.css?v=8">'
    . '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// Lucide paths, stroke 1.5 via CSS.
$ico = [
    'dash'  => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    'event' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
    'cal'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    'screen'=> '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
    'card'  => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'shield'=> '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'box'   => '<path d="M3 3h18v4H3z"/><path d="M5 7v13h14V7"/><path d="M10 12h4"/>',
    'scan'  => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
    'ext'   => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'grid'  => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    'out'   => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
    'plus'  => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'save'  => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'upload'=> '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    'down'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    'pin'   => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    'key'   => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    'mail'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'user'  => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'tag'   => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'menu'  => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
];
$svg = fn($k, $cls = '') => '<svg viewBox="0 0 24 24" class="' . $cls . '" aria-hidden="true">' . $ico[$k] . '</svg>';

$nav = [
    ['ÜBERSICHT', [['dashboard', 'Dashboard', 'dash'], ['stats', 'Statistik', 'chart']]],
    ['VERANSTALTUNG', [['event', 'Veranstaltung', 'event'], ['dates', 'Termine & Orte', 'cal'], ['images', 'Bilder', 'image'], ['screens', 'Screens', 'screen']]],
    ['SYSTEM', [['payments', 'Zahlung', 'card'], ['accounts', 'Konten', 'users'], ['system', 'Wartung', 'shield']]],
];
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="avo-ui adm">

    <nav class="avo-plate avo-rail adm-rail" aria-label="Admin">
        <a class="adm-brand" href="#dashboard" data-nav="dashboard" aria-label="QrGate Admin">
            <svg viewBox="0 0 72 72" fill="none" aria-hidden="true"><path d="M22 18 L12 18 L12 54 L22 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M50 18 L60 18 L60 54 L50 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M26 30 L33 36 L26 42" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><rect x="40" y="28" width="4" height="16" rx="2" fill="#FF6B4A" stroke="none"/></svg>
            <b>QRGATE<span>.ADMIN</span></b>
        </a>
        <?php foreach ($nav as [$group, $items]): ?>
            <div class="avo-kicker"><span><?php echo $group; ?></span></div>
            <?php foreach ($items as [$id, $label, $icon]): ?>
                <a href="#<?php echo $id; ?>" data-nav="<?php echo $id; ?>"><?php echo $svg($icon); ?><span><?php echo $label; ?></span></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="avo-kicker"><span>APPS</span></div>
        <a href="ticketflow/"><?php echo $svg('box'); ?><span>Abendkasse</span></a>
        <a href="handheld/"><?php echo $svg('scan'); ?><span>Einlass-Scanner</span></a>
        <a href="../index.php" target="_blank" rel="noopener"><?php echo $svg('ext'); ?><span>Shop ansehen</span></a>
        <div class="adm-rail__foot">
            <a href="apps.php"><?php echo $svg('grid'); ?><span>App wechseln</span></a>
            <a href="logout.php"><?php echo $svg('out'); ?><span>Abmelden</span></a>
            <div class="adm-rail__meta">
                <span class="avo-serial"><?php echo $h(strtoupper($username)); ?> · <span class="avo-serial live" id="liveSerial">LIVE</span></span>
                <button type="button" class="avo-theme-toggle" data-avo-theme-toggle aria-label="Theme">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                </button>
            </div>
        </div>
    </nav>

    <div class="adm-main">
        <header class="adm-bar">
            <ol class="avo-crumbs"><li><?php echo $h($shows['orga_name'] ?? 'qrgate'); ?></li><li aria-current="page" id="crumb">Dashboard</li></ol>
            <span class="adm-bar__sp"></span>
            <span class="avo-chip" id="storeChip"></span>
        </header>

        <main class="adm-body">
        <?php if (!$shows): ?>
            <div class="avo-plate avo-status error adm-pad">
                <span class="avo-serial">ERR · BACKEND</span>
                <h1 class="avo-title">Backend nicht erreichbar</h1>
                <p class="avo-small">Die Veranstaltungsdaten konnten nicht geladen werden. Prüfe, ob das Backend läuft, und lade die Seite neu.</p>
            </div>
        <?php endif; ?>

        <!-- ============================================ DASHBOARD -->
        <section class="adm-view" data-view="dashboard" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Übersicht</span><i class="rule"></i></div>
                <h1 class="avo-display-2"><?php echo $h(($shows['title'] ?? '') ?: 'Dashboard'); ?></h1>
                <p class="avo-small" id="dashSub"></p>
            </div>
            <div class="adm-tiles">
                <div class="avo-plate adm-tile"><span class="avo-mk tl"></span><span class="avo-mk br"></span>
                    <div class="avo-kicker"><span>Verkauft</span></div>
                    <div class="avo-stat" id="tSold">–</div>
                    <div class="adm-meter"><span id="tSoldBar"></span></div>
                    <span class="avo-serial" id="tSoldSub">&nbsp;</span></div>
                <div class="avo-plate adm-tile"><span class="avo-mk tl"></span><span class="avo-mk br"></span>
                    <div class="avo-kicker"><span>Umsatz bezahlt</span></div>
                    <div class="avo-stat" id="tRev">–</div>
                    <span class="avo-serial" id="tRevSub">&nbsp;</span></div>
                <div class="avo-plate adm-tile"><span class="avo-mk tl"></span><span class="avo-mk br"></span>
                    <div class="avo-kicker"><span>Offen an der Kasse</span></div>
                    <div class="avo-stat" id="tOpen">–</div>
                    <span class="avo-serial" id="tOpenSub">&nbsp;</span></div>
                <div class="avo-plate adm-tile"><span class="avo-mk tl"></span><span class="avo-mk br"></span>
                    <div class="avo-kicker"><span>Einlass</span></div>
                    <div class="avo-stat" id="tIn">–</div>
                    <div class="adm-meter"><span id="tInBar"></span></div>
                    <span class="avo-serial" id="tInSub">&nbsp;</span></div>
            </div>

            <div class="avo-plate adm-table-plate">
                <div class="avo-toolbar">
                    <span class="avo-kicker"><span>Termine</span></span>
                    <span class="spacer"></span>
                    <span class="avo-serial" id="dashUpdated"></span>
                </div>
                <div class="avo-table-scroll">
                    <table class="avo-table">
                        <thead><tr><th>Termin</th><th>Ort</th><th>Auslastung</th><th class="num">Verkauft</th><th class="num">Im Checkout</th><th class="num">Frei</th><th class="num">Offen</th><th class="num">Umsatz</th><th class="num">Einlass</th></tr></thead>
                        <tbody id="dashDates"></tbody>
                    </table>
                </div>
            </div>

            <div class="adm-cols">
                <div class="avo-plate adm-table-plate">
                    <div class="avo-toolbar"><span class="avo-kicker"><span>Letzte Bestellungen</span></span></div>
                    <div class="avo-table-scroll">
                        <table class="avo-table">
                            <thead><tr><th>Zeit</th><th>Name</th><th>Termin</th><th>Status</th><th class="num">Tickets</th><th class="num">Betrag</th></tr></thead>
                            <tbody id="dashOrders"></tbody>
                        </table>
                    </div>
                </div>
                <div class="avo-plate adm-pad adm-live">
                    <div class="avo-kicker"><span>Live-Einlass</span><i class="rule"></i></div>
                    <div class="adm-live__num"><span class="avo-stat" id="liveIn">–</span><span class="avo-small" id="liveOf"></span></div>
                    <span class="avo-serial" id="liveDate"></span>
                    <ul class="adm-list" id="liveList"></ul>
                </div>
            </div>
        </section>

        <!-- ============================================ STATISTIK -->
        <section class="adm-view" data-view="stats" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Statistik</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Verkauf nach Tagen</h1>
                <p class="avo-small">Umsatz und Tickets pro Verkaufstag, so wie sie gebucht wurden (online und an der Kasse, abzüglich Stornos).</p>
            </div>
            <div class="adm-cols adm-cols--even">
                <div class="avo-plate adm-pad adm-chart">
                    <div class="adm-chart__head"><div class="avo-kicker"><span>Umsatz</span><i class="rule"></i></div><span class="avo-serial" id="chRange1"></span></div>
                    <div class="adm-chart__box"><canvas id="chIncome"></canvas></div>
                </div>
                <div class="avo-plate adm-pad adm-chart">
                    <div class="adm-chart__head"><div class="avo-kicker"><span>Tickets</span><i class="rule"></i></div><span class="avo-serial" id="chRange2"></span></div>
                    <div class="adm-chart__box"><canvas id="chSales"></canvas></div>
                </div>
            </div>
            <div class="avo-plate adm-table-plate">
                <div class="avo-toolbar"><span class="avo-kicker"><span>Verkaufstage</span></span></div>
                <div class="avo-table-scroll adm-scroll-y">
                    <table class="avo-table">
                        <thead><tr><th>Tag</th><th class="num">Tickets</th><th class="num">Umsatz</th></tr></thead>
                        <tbody id="statDays"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ============================================ VERANSTALTUNG -->
        <section class="adm-view" data-view="event" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Veranstaltung</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Stammdaten & Shop</h1>
            </div>
            <div class="adm-cols">
                <form class="avo-plate adm-pad adm-form" id="eventForm">
                    <h2 class="avo-title"><?php echo $svg('event'); ?>Veranstaltung</h2>
                    <div class="avo-grid c2">
                        <div class="avo-field"><label class="avo-label" for="evOrga">Veranstalter <span class="req">*</span></label>
                            <input class="avo-input" id="evOrga" name="orga_name" maxlength="80" required></div>
                        <div class="avo-field"><label class="avo-label" for="evTitle">Titel <span class="req">*</span></label>
                            <input class="avo-input" id="evTitle" name="title" maxlength="120" required></div>
                    </div>
                    <div class="avo-field"><label class="avo-label" for="evSub">Untertitel</label>
                        <input class="avo-input" id="evSub" name="subtitle" maxlength="160"></div>
                    <div class="avo-grid c2">
                        <div class="avo-field"><label class="avo-label" for="evMail">Kontakt-E-Mail</label>
                            <div class="avo-field-icon"><?php echo $svg('mail'); ?><input class="avo-input" id="evMail" name="contact_email" type="email"></div>
                            <p class="avo-help">Steht im Shop und in den Ticket-Mails, für Storno und Fragen.</p></div>
                        <div class="avo-field"><label class="avo-label" for="evDomain">Shop-Adresse</label>
                            <div class="avo-field-icon"><?php echo $svg('globe'); ?><input class="avo-input" id="evDomain" name="app_domain" placeholder="https://tickets.example.org"></div>
                            <p class="avo-help">Für Links in E-Mails, z. B. den Storno-Link.</p></div>
                    </div>
                    <div class="avo-rule"></div>
                    <h2 class="avo-title"><?php echo $svg('tag'); ?>Verkauf</h2>
                    <div class="avo-field"><label class="avo-label" for="evMethods">Zahlungsarten im Shop</label>
                        <select class="avo-select" id="evMethods" name="payment_methods">
                            <option value="both">Karte und Bar an der Abendkasse</option>
                            <option value="online">Nur Karte</option>
                            <option value="cash">Nur Bar an der Abendkasse</option>
                        </select>
                        <p class="avo-help">Kartenzahlung erscheint nur, wenn unter „Zahlung“ Stripe eingerichtet ist.</p></div>
                    <label class="avo-choice adm-switchrow">
                        <input type="checkbox" class="avo-switch" id="evLock" name="store_lock">
                        <span><b>Shop sperren</b><span class="avo-help">Keine neuen Bestellungen. Laufende Reservierungen können noch abgeschlossen werden.</span></span>
                    </label>
                    <div class="avo-rule"></div>
                    <h2 class="avo-title"><?php echo $svg('mail'); ?>Erinnerungs-Mail</h2>
                    <label class="avo-choice adm-switchrow">
                        <input type="checkbox" class="avo-switch" id="evRemind" name="reminder_enabled">
                        <span><b>Erinnerung vor dem Termin senden</b><span class="avo-help">Käufer bekommen eine Mail mit ihren Tickets als PDF, ab 9 Uhr am gewählten Tag. Wer erst in diesem Zeitraum gekauft hat, bekommt keine.</span></span>
                    </label>
                    <div class="avo-field"><label class="avo-label" for="evRemindDays">Zeitpunkt</label>
                        <select class="avo-select" id="evRemindDays" name="reminder_days">
                            <option value="1">1 Tag vorher</option>
                            <?php for ($i = 2; $i <= 7; $i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> Tage vorher</option><?php endfor; ?>
                        </select></div>
                    <div class="adm-actions"><button type="submit" class="avo-btn primary"><?php echo $svg('save'); ?><span>Speichern</span></button></div>
                </form>

                <div class="avo-plate adm-pad adm-form">
                    <h2 class="avo-title"><?php echo $svg('box'); ?>Kassen-Preiskategorien</h2>
                    <p class="avo-small">Zusätzliche Preise an der Abendkasse neben „Normal“ (Preis des Termins bzw. Sitzplatzes), z. B. Ermäßigt, Kind oder Freikarte. Sie erscheinen in der Kasse als eigene Kacheln.</p>
                    <div id="catList" class="adm-cats"></div>
                    <div class="adm-actions">
                        <button type="button" class="avo-btn compact" id="catAdd"><?php echo $svg('plus'); ?><span>Kategorie</span></button>
                        <span class="adm-bar__sp"></span>
                        <button type="button" class="avo-btn" id="catSave"><?php echo $svg('save'); ?><span>Kategorien speichern</span></button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================ TERMINE & ORTE -->
        <section class="adm-view" data-view="dates" hidden>
            <div class="adm-head adm-head--row">
                <div>
                    <div class="avo-kicker"><span>Termine & Orte</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Termine</h1>
                </div>
                <button type="button" class="avo-btn primary" id="dayNew"><?php echo $svg('plus'); ?><span>Neuer Termin</span></button>
            </div>
            <div class="avo-plate adm-table-plate">
                <div class="avo-table-scroll">
                    <table class="avo-table">
                        <thead><tr><th>Datum</th><th>Zeit</th><th>Ort</th><th>Modus</th><th class="num">Preis</th><th class="num">Kapazität</th><th class="num">Frei</th><th></th></tr></thead>
                        <tbody id="dayRows"></tbody>
                    </table>
                </div>
            </div>
            <p class="avo-help">Kapazität ändern verschiebt „frei“ um die Differenz; verkaufte und gerade reservierte Tickets bleiben gezählt. Datum und Platzwahl lassen sich nur ändern, solange es für den Termin keine Tickets gibt.</p>

            <div class="adm-head adm-head--row">
                <div><h2 class="avo-display-2 adm-h2">Orte</h2></div>
                <button type="button" class="avo-btn" id="locNew"><?php echo $svg('plus'); ?><span>Neuer Ort</span></button>
            </div>
            <div class="avo-plate adm-table-plate">
                <div class="avo-table-scroll">
                    <table class="avo-table">
                        <thead><tr><th>Name</th><th>Adresse</th><th>Saalplan</th><th class="num">Termine</th><th></th></tr></thead>
                        <tbody id="locRows"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ============================================ BILDER -->
        <section class="adm-view" data-view="images" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Bilder</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Banner & Logo</h1>
                <p class="avo-small">Das Banner steht im Shop neben dem Titel (16:9 wirkt am besten), das Logo im Kopf des Shops, auf den Tickets und als Favicon.</p>
            </div>
            <div class="adm-cols adm-cols--even">
                <?php foreach ([['banner', 'Banner'], ['logo', 'Logo']] as [$k, $label]): ?>
                <div class="avo-plate adm-pad adm-img">
                    <div class="adm-img__head">
                        <h2 class="avo-title"><?php echo $svg('image'); ?><?php echo $label; ?></h2>
                        <label class="avo-btn compact"><?php echo $svg('upload'); ?><span>Hochladen</span>
                            <input type="file" accept="image/png,image/jpeg,image/webp" data-upload="<?php echo $k; ?>" hidden></label>
                    </div>
                    <div class="adm-img__box adm-img__box--<?php echo $k; ?>"><img data-preview="<?php echo $k; ?>" alt="<?php echo $label; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ============================================ SCREENS -->
        <section class="adm-view" data-view="screens" hidden>
            <div class="adm-head adm-head--row">
                <div>
                    <div class="avo-kicker"><span>Screens</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Willkommens-Bildschirm</h1>
                    <p class="avo-small">Folien für die Bildschirme im Foyer. Platzhalter: {orga_name}, {show_title}, {show_subtitle}.</p>
                </div>
                <button type="button" class="avo-btn primary" id="scrSave"><?php echo $svg('save'); ?><span>Speichern</span></button>
            </div>
            <div class="adm-screens">
                <div class="avo-plate adm-table-plate">
                    <div class="avo-toolbar"><span class="avo-kicker"><span>Folien</span></span><span class="spacer"></span>
                        <button type="button" class="avo-btn compact" id="scrAdd"><?php echo $svg('plus'); ?><span>Folie</span></button></div>
                    <ol class="adm-slides" id="slideList"></ol>
                    <div class="adm-pad adm-form">
                        <div class="avo-field"><label class="avo-label" for="scrLang">Sprachen</label>
                            <select class="avo-select" id="scrLang"><option value="both">Deutsch und Englisch</option><option value="de">Nur Deutsch</option><option value="en">Nur Englisch</option></select></div>
                    </div>
                </div>
                <div class="avo-plate adm-pad adm-form" id="slideEditor"></div>
            </div>
        </section>

        <!-- ============================================ ZAHLUNG -->
        <section class="adm-view" data-view="payments" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Zahlung</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Kartenzahlung mit Stripe</h1>
            </div>
            <div class="adm-cols">
                <form class="avo-plate adm-pad adm-form" id="payForm" autocomplete="off">
                    <h2 class="avo-title"><?php echo $svg('key'); ?>API-Schlüssel <span class="adm-tagslot" id="payMode"></span></h2>
                    <div class="avo-field"><label class="avo-label" for="payPub">Publishable Key</label>
                        <input class="avo-input" id="payPub" placeholder="pk_live_… oder pk_test_…" spellcheck="false"></div>
                    <div class="avo-field"><label class="avo-label" for="paySecret">Secret Key</label>
                        <input class="avo-input" id="paySecret" type="password" spellcheck="false">
                        <p class="avo-help" id="paySecretHelp"></p></div>
                    <div class="avo-field"><label class="avo-label" for="payHook">Webhook Secret</label>
                        <input class="avo-input" id="payHook" type="password" spellcheck="false" placeholder="whsec_…">
                        <p class="avo-help" id="payHookHelp"></p></div>
                    <div class="adm-actions"><button type="submit" class="avo-btn primary"><?php echo $svg('save'); ?><span>Speichern</span></button></div>
                </form>
                <div class="avo-plate adm-pad adm-note">
                    <div class="avo-kicker"><span>So wird abgebucht</span></div>
                    <ol class="adm-steps">
                        <li><span class="avo-serial live">01</span><p class="avo-small">Beim „Weiter“ im Shop werden die Tickets 10 Minuten für den Kunden reserviert. Ist ein Termin ausverkauft, erfährt er es hier, vor der Zahlung.</p></li>
                        <li><span class="avo-serial live">02</span><p class="avo-small">Die Karte wird nur <b>vorgemerkt</b> (manuelle Erfassung). Es fließt noch kein Geld.</p></li>
                        <li><span class="avo-serial live">03</span><p class="avo-small">Erst wenn die Tickets angelegt sind, wird die Zahlung eingezogen. Scheitert etwas, wird die Vormerkung aufgehoben.</p></li>
                    </ol>
                    <p class="avo-help">Der Secret Key bleibt auf dem Server und wird nie an einen Browser geschickt.</p>
                </div>
            </div>
        </section>

        <!-- ============================================ KONTEN -->
        <section class="adm-view" data-view="accounts" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Konten</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Benutzer & Zugänge</h1>
            </div>
            <div class="adm-cols">
                <div class="avo-plate adm-table-plate">
                    <div class="avo-table-scroll">
                        <table class="avo-table">
                            <thead><tr><th>Benutzer</th><th>Admin</th><th>Abendkasse</th><th>Scanner</th><th></th></tr></thead>
                            <tbody id="accRows"><tr><td colspan="5"><span class="avo-loader inline"></span></td></tr></tbody>
                        </table>
                    </div>
                </div>
                <form class="avo-plate adm-pad adm-form" id="accForm" autocomplete="off">
                    <h2 class="avo-title"><?php echo $svg('user'); ?>Neues Konto</h2>
                    <div class="avo-field"><label class="avo-label" for="accUser">Benutzername <span class="req">*</span></label>
                        <input class="avo-input" id="accUser" required maxlength="40"></div>
                    <div class="avo-field"><label class="avo-label" for="accPw">Startpasswort <span class="req">*</span></label>
                        <input class="avo-input" id="accPw" required minlength="6">
                        <p class="avo-help">Mindestens 6 Zeichen. Die Person kann es später selbst ändern.</p></div>
                    <fieldset class="adm-fieldset"><legend class="avo-label">Zugänge</legend>
                        <label class="avo-choice"><input type="checkbox" class="avo-check" id="accAdmin"> Admin (Verwaltung & Konten)</label>
                        <label class="avo-choice"><input type="checkbox" class="avo-check" id="accTf" checked> Abendkasse (TicketFlow)</label>
                        <label class="avo-choice"><input type="checkbox" class="avo-check" id="accHh"> Einlass-Scanner</label>
                    </fieldset>
                    <div class="adm-actions"><button type="submit" class="avo-btn primary"><?php echo $svg('plus'); ?><span>Konto anlegen</span></button></div>
                </form>
            </div>
        </section>

        <!-- ============================================ WARTUNG -->
        <section class="adm-view" data-view="system" hidden>
            <div class="adm-head">
                <div class="avo-kicker"><span>Wartung</span><i class="rule"></i></div>
                <h1 class="avo-display-2">Backup & Daten</h1>
            </div>
            <div class="avo-plate adm-pad adm-row">
                <div><h2 class="avo-title"><?php echo $svg('down'); ?>Datenbank-Backup</h2>
                    <p class="avo-small">Vollständige Kopie (Veranstaltung, Tickets, Statistik, Konten, Einstellungen) als <code class="avo-code">.db</code>-Datei. Auch im laufenden Betrieb konsistent.</p></div>
                <button type="button" class="avo-btn" id="backupBtn"><?php echo $svg('down'); ?><span>Backup herunterladen</span></button>
            </div>
            <div class="avo-plate avo-status error adm-danger">
                <div class="adm-pad"><h2 class="avo-title adm-danger__title"><?php echo $svg('alert'); ?>Gefahrenzone</h2>
                    <p class="avo-small">Diese Aktionen lassen sich nicht rückgängig machen. Lade vorher ein Backup herunter.</p></div>
                <?php foreach ([
                    ['wipe_data', 'LÖSCHEN', 'Alle Daten löschen', 'Löscht alle Tickets, Verkäufe und Statistiken. Die Plätze werden auf volle Kapazität gesetzt. Veranstaltung und Konten bleiben.'],
                    ['reinstall', 'INSTALL', 'Neu installieren', 'Startet den Einrichtungsassistenten erneut. Alle Daten bleiben erhalten.'],
                    ['factory_reset', 'WERKSRESET', 'Werkseinstellungen', 'Löscht alles: Veranstaltung, Tickets, Statistik, Einstellungen, Bilder. Das Admin-Konto wird auf admin/admin gesetzt.'],
                ] as [$act, $word, $label, $desc]): ?>
                <div class="adm-row adm-row--line">
                    <div><b><?php echo $label; ?></b><p class="avo-small"><?php echo $desc; ?></p></div>
                    <button type="button" class="avo-btn adm-btn-danger" data-danger="<?php echo $act; ?>" data-word="<?php echo $word; ?>" data-label="<?php echo $h($label); ?>"><span><?php echo $label; ?></span></button>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        </main>
    </div>

    <!-- day editor -->
    <dialog class="avo-dialog adm-dialog" id="dayDlg" aria-labelledby="dayDlgTitle">
        <form id="dayForm" class="adm-form" method="dialog">
            <div class="avo-kicker"><span>Termin</span><i class="rule"></i></div>
            <h3 class="avo-title" id="dayDlgTitle">Neuer Termin</h3>
            <div class="avo-grid c2">
                <div class="avo-field"><label class="avo-label" for="dDate">Datum <span class="req">*</span></label><input class="avo-input" type="date" id="dDate" required></div>
                <div class="avo-field"><label class="avo-label" for="dTime">Beginn <span class="req">*</span></label><input class="avo-input" type="time" id="dTime" value="19:30" required></div>
            </div>
            <div class="avo-field"><label class="avo-label" for="dLoc">Ort</label><select class="avo-select" id="dLoc"></select></div>
            <label class="avo-choice adm-switchrow"><input type="checkbox" class="avo-switch" id="dSeat">
                <span><b>Platzwahl</b><span class="avo-help">Kunden wählen ihre Plätze im Saalplan des Ortes. Kapazität und Preise kommen dann aus dem Saalplan.</span></span></label>
            <div class="avo-grid c2">
                <div class="avo-field" id="dCapField"><label class="avo-label" for="dCap">Kapazität <span class="req">*</span></label><input class="avo-input" type="number" min="0" step="1" id="dCap" value="100"></div>
                <div class="avo-field"><label class="avo-label" for="dPrice">Preis (€) <span class="req">*</span></label><input class="avo-input" type="number" min="0" step="0.01" id="dPrice" value="15.00"></div>
            </div>
            <p class="avo-help" id="dInfo"></p>
            <p class="avo-error-msg" id="dErr" hidden></p>
            <div class="adm-actions">
                <button type="button" class="avo-btn adm-btn-danger" id="dDel" hidden><span>Löschen</span></button>
                <span class="adm-bar__sp"></span>
                <button type="button" class="avo-btn" data-close>Abbrechen</button>
                <button type="submit" class="avo-btn primary" id="dSave"><?php echo $svg('save'); ?><span>Speichern</span></button>
            </div>
        </form>
    </dialog>

    <!-- location editor -->
    <dialog class="avo-dialog adm-dialog" id="locDlg" aria-labelledby="locDlgTitle">
        <form id="locForm" class="adm-form" method="dialog">
            <div class="avo-kicker"><span>Ort</span><i class="rule"></i></div>
            <h3 class="avo-title" id="locDlgTitle">Neuer Ort</h3>
            <div class="avo-field"><label class="avo-label" for="lName">Name <span class="req">*</span></label><input class="avo-input" id="lName" required maxlength="80"></div>
            <div class="avo-field"><label class="avo-label" for="lAddr">Adresse</label>
                <div class="avo-field-icon"><?php echo $svg('pin'); ?><input class="avo-input" id="lAddr" maxlength="160"></div>
                <p class="avo-help">Steht auf Ticket und E-Mail.</p></div>
            <p class="avo-error-msg" id="lErr" hidden></p>
            <div class="adm-actions">
                <button type="button" class="avo-btn adm-btn-danger" id="lDel" hidden><span>Löschen</span></button>
                <span class="adm-bar__sp"></span>
                <button type="button" class="avo-btn" data-close>Abbrechen</button>
                <button type="submit" class="avo-btn primary"><?php echo $svg('save'); ?><span>Speichern</span></button>
            </div>
        </form>
    </dialog>

    <!-- confirm (optionally type-to-confirm) -->
    <dialog class="avo-dialog adm-dialog" id="cfDlg" aria-labelledby="cfTitle">
        <form class="adm-form" method="dialog" id="cfForm">
            <div class="avo-kicker"><span>Bestätigen</span><i class="rule"></i></div>
            <h3 class="avo-title" id="cfTitle"></h3>
            <p class="avo-small" id="cfText"></p>
            <div class="avo-field" id="cfWordField" hidden><label class="avo-label" for="cfWord" id="cfWordLabel"></label><input class="avo-input" id="cfWord" autocomplete="off" spellcheck="false"></div>
            <div class="adm-actions">
                <span class="adm-bar__sp"></span>
                <button type="button" class="avo-btn" data-close>Abbrechen</button>
                <button type="submit" class="avo-btn adm-btn-danger" id="cfOk"><span>Bestätigen</span></button>
            </div>
        </form>
    </dialog>

    <div class="avo-toast-stack" id="toasts" aria-live="polite"></div>

    <script>window.ADMIN = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="admin.js?v=6" defer></script>
</body>
</html>
