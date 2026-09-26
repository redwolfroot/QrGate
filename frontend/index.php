<?php
require_once 'config.php';

// ---- language: explicit choice (POST / ?lang) > session > browser ----------
// The buyer's choice is stored on the ticket, so emails and PDFs follow it.
// Until they have chosen, the page asks on load (browser language preselected).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $_SESSION['language'] = $_POST['language'] === 'de' ? 'de' : 'en';
    $_SESSION['language_chosen'] = true;
    header('Location: index.php');
    exit();
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['de', 'en'], true)) {
    $_SESSION['language'] = $_GET['lang'];
    $_SESSION['language_chosen'] = true;
}
if (empty($_SESSION['language'])) {
    $_SESSION['language'] = stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'de') === 0 ? 'de' : 'en';
}
$lang = $_SESSION['language'] === 'de' ? 'de' : 'en';
$askLang = empty($_SESSION['language_chosen']);
$de = $lang === 'de';

$T = [
    'de' => [
        'tickets' => 'Tickets', 'dates' => 'Termine', 'from' => 'ab', 'book' => 'Tickets',
        'sold_out' => 'Ausverkauft', 'few_left' => 'Nur noch {n}', 'available' => '{n} frei',
        'seated' => 'Platzwahl', 'free_seating' => 'Freie Platzwahl',
        'no_dates' => 'Aktuell sind keine Termine im Verkauf.',
        'locked_title' => 'Shop geschlossen',
        'locked_text' => 'Der Ticketverkauf ist gerade pausiert. Schau später wieder vorbei.',
        'error_title' => 'Shop nicht erreichbar',
        'error_text' => 'Die Termine können gerade nicht geladen werden. Bitte versuche es gleich noch einmal.',
        'retry' => 'Neu laden',
        'help' => 'Hilfe zum Kauf', 'cancel' => 'Ticket stornieren', 'contact' => 'Kontakt',
        'how_title' => 'So funktioniert es',
        'how_1' => 'Termin wählen, Anzahl oder Plätze festlegen. Die Tickets sind ab dann für dich reserviert.',
        'how_2' => 'Name und E-Mail eingeben, bezahlen. Abgebucht wird erst, wenn deine Tickets sicher angelegt sind.',
        'how_3' => 'Die Tickets kommen per E-Mail. Am Einlass den QR-Code zeigen.',
    ],
    'en' => [
        'tickets' => 'Tickets', 'dates' => 'Dates', 'from' => 'from', 'book' => 'Tickets',
        'sold_out' => 'Sold out', 'few_left' => 'Only {n} left', 'available' => '{n} left',
        'seated' => 'Seat selection', 'free_seating' => 'Free seating',
        'no_dates' => 'No dates are on sale right now.',
        'locked_title' => 'Shop closed',
        'locked_text' => 'Ticket sales are paused at the moment. Please check back later.',
        'error_title' => 'Shop unavailable',
        'error_text' => 'The dates cannot be loaded right now. Please try again in a moment.',
        'retry' => 'Reload',
        'help' => 'How to buy', 'cancel' => 'Cancel a ticket', 'contact' => 'Contact',
        'how_title' => 'How it works',
        'how_1' => 'Pick a date and the number of tickets or your seats. From then on they are reserved for you.',
        'how_2' => 'Enter your name and email and pay. Your card is only charged once your tickets exist.',
        'how_3' => 'Your tickets arrive by email. Show the QR code at the door.',
    ],
][$lang];

// Strings the checkout script needs (it renders everything after the list).
$J = [
    'de' => [
        'close' => 'Schließen', 'step1' => 'Tickets', 'step2' => 'Daten & Zahlung',
        'qty' => 'Anzahl', 'per_ticket' => 'pro Ticket', 'next' => 'Weiter', 'back' => 'Zurück',
        'total' => 'Gesamt', 'summary' => 'Deine Bestellung', 'reserved_for' => 'Reserviert für',
        'reserve_note' => 'Die Tickets sind für dich reserviert, solange die Zeit läuft.',
        'pick_note' => 'Die Tickets werden reserviert, sobald du auf „Weiter“ tippst.',
        'seats_pick' => 'Plätze wählen', 'seats_best' => 'Beste Plätze wählen', 'seats_fit' => 'Ganzer Saal',
        'seats_hint' => 'Tippe auf freie Plätze im Saalplan.', 'seats_none' => 'Noch keine Plätze gewählt.',
        'seats_chosen' => '{n} Plätze gewählt', 'seat_one' => '1 Platz gewählt', 'seat_sold' => 'Belegt',
        'seat_mine' => 'Deine Wahl', 'seat_max' => 'Höchstens {n} Plätze pro Bestellung.',
        'seat_orphan' => 'Neben deiner Auswahl bliebe ein einzelner Platz frei. Nimm ihn dazu oder rück einen Platz weiter.',
        'seat_no_block' => 'So viele freie Plätze nebeneinander gibt es nicht mehr. Wähle einzeln.',
        'seats_loading' => 'Saalplan wird geladen', 'seats_error' => 'Der Saalplan konnte nicht geladen werden.',
        'first_name' => 'Vorname', 'last_name' => 'Nachname', 'email' => 'E-Mail',
        'email_help' => 'An diese Adresse schicken wir die Tickets.',
        'guests' => 'Namen der Begleitpersonen', 'guests_help' => 'Optional. Leer gelassen steht „Gast 2“ usw. auf dem Ticket.',
        'guest' => 'Ticket {n}',
        'pay_with' => 'Bezahlen mit', 'm_card' => 'Karte, Apple Pay, Google Pay', 'm_cash' => 'Bar an der Abendkasse',
        'm_card_sub' => 'Sofort bezahlt, Tickets gelten direkt.',
        'm_cash_sub' => 'Reservierung. Du zahlst am Veranstaltungstag vor dem Einlass.',
        'consent_card' => 'Ich kaufe verbindlich und bestätige den Termin.',
        'consent_cash' => 'Ich reserviere verbindlich und zahle am Veranstaltungstag an der Kasse.',
        'pay' => 'Jetzt bezahlen', 'reserve' => 'Verbindlich reservieren',
        'processing' => 'Bestellung wird angelegt', 'paying' => 'Zahlung wird bestätigt',
        'card_loading' => 'Zahlungsformular wird geladen',
        'expired_title' => 'Reservierung abgelaufen',
        'expired_text' => 'Die Zeit ist um, die Tickets sind wieder frei. Starte einfach neu.',
        'restart' => 'Neu starten',
        'done_paid' => 'Bezahlt. Viel Spaß!', 'done_reserved' => 'Reserviert',
        'done_paid_text' => 'Deine Tickets sind unterwegs an {email}. Am Einlass den QR-Code zeigen.',
        'done_reserved_text' => 'Deine Tickets sind unterwegs an {email}. Bitte bezahle am Veranstaltungstag an der Abendkasse, dann werden sie freigeschaltet.',
        'done_spam' => 'Nichts angekommen? Schau im Spam-Ordner nach.',
        'finish' => 'Fertig', 'date' => 'Datum', 'seats' => 'Plätze', 'name' => 'Name', 'location' => 'Ort',
        'required' => 'Pflichtfeld',
        'err' => [
            'not_enough_tickets' => 'So viele Tickets sind nicht mehr frei. Verfügbar: {available}.',
            'seats_taken' => 'Einige Plätze wurden gerade vergeben. Bitte wähle neu.',
            'date_past' => 'Dieser Termin liegt in der Vergangenheit.',
            'store_locked' => 'Der Shop ist gerade geschlossen.',
            'hold_expired' => 'Deine Reservierung ist abgelaufen. Bitte starte neu.',
            'invalid_name' => 'Bitte gib Vor- und Nachnamen ein.',
            'invalid_email' => 'Bitte prüfe die E-Mail-Adresse.',
            'email_domain' => 'An diese E-Mail-Domain kann nichts zugestellt werden. Bitte prüfe die Adresse.',
            'consent_required' => 'Bitte bestätige die Buchung.',
            'too_many_reservations' => 'Für diese E-Mail-Adresse sind schon zu viele unbezahlte Tickets reserviert. Bitte bezahle online oder kontaktiere den Veranstalter.',
            'too_many_requests' => 'Zu viele Versuche. Bitte warte ein paar Minuten.',
            'too_fast' => 'Einen Moment bitte, dann noch einmal absenden.',
            'payment_not_authorized' => 'Die Zahlung wurde nicht bestätigt. Es wurde nichts abgebucht.',
            'payment_failed' => 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht.',
            'payment_unavailable' => 'Die Kartenzahlung ist gerade nicht verfügbar. Bitte versuche es gleich noch einmal.',
            'session_expired' => 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu.',
            'backend_unreachable' => 'Der Ticketserver ist gerade nicht erreichbar. Es wurde nichts abgebucht.',
            'method_not_allowed' => 'Diese Zahlungsart ist nicht verfügbar.',
            'amount_too_small' => 'Der Betrag ist zu klein für eine Kartenzahlung.',
            'generic' => 'Das hat nicht geklappt. Es wurde nichts abgebucht. Bitte versuche es noch einmal.',
        ],
    ],
    'en' => [
        'close' => 'Close', 'step1' => 'Tickets', 'step2' => 'Details & payment',
        'qty' => 'Quantity', 'per_ticket' => 'per ticket', 'next' => 'Continue', 'back' => 'Back',
        'total' => 'Total', 'summary' => 'Your order', 'reserved_for' => 'Reserved for',
        'reserve_note' => 'Your tickets are held for you while the timer runs.',
        'pick_note' => 'Your tickets are reserved as soon as you tap “Continue”.',
        'seats_pick' => 'Choose seats', 'seats_best' => 'Pick best seats', 'seats_fit' => 'Whole hall',
        'seats_hint' => 'Tap free seats on the map.', 'seats_none' => 'No seats chosen yet.',
        'seats_chosen' => '{n} seats chosen', 'seat_one' => '1 seat chosen', 'seat_sold' => 'Taken',
        'seat_mine' => 'Your choice', 'seat_max' => 'At most {n} seats per order.',
        'seat_orphan' => 'Your selection would leave a single empty seat next to it. Add it or move over by one.',
        'seat_no_block' => 'There are no longer that many free seats side by side. Please pick them one by one.',
        'seats_loading' => 'Loading seat map', 'seats_error' => 'The seat map could not be loaded.',
        'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email',
        'email_help' => 'We send the tickets to this address.',
        'guests' => 'Names of your guests', 'guests_help' => 'Optional. Left empty, the ticket reads “Guest 2” and so on.',
        'guest' => 'Ticket {n}',
        'pay_with' => 'Pay with', 'm_card' => 'Card, Apple Pay, Google Pay', 'm_cash' => 'Cash at the box office',
        'm_card_sub' => 'Paid now, tickets are valid right away.',
        'm_cash_sub' => 'A reservation. You pay on the day before entry.',
        'consent_card' => 'I am buying with obligation and confirm the date.',
        'consent_cash' => 'I am reserving with obligation and will pay at the box office on the day.',
        'pay' => 'Pay now', 'reserve' => 'Reserve now',
        'processing' => 'Placing your order', 'paying' => 'Confirming payment',
        'card_loading' => 'Loading payment form',
        'expired_title' => 'Reservation expired',
        'expired_text' => 'Time is up and the tickets are free again. Just start over.',
        'restart' => 'Start over',
        'done_paid' => 'Paid. Enjoy the show!', 'done_reserved' => 'Reserved',
        'done_paid_text' => 'Your tickets are on their way to {email}. Show the QR code at the door.',
        'done_reserved_text' => 'Your tickets are on their way to {email}. Please pay at the box office on the day to activate them.',
        'done_spam' => 'Nothing arrived? Check your spam folder.',
        'finish' => 'Done', 'date' => 'Date', 'seats' => 'Seats', 'name' => 'Name', 'location' => 'Venue',
        'required' => 'required',
        'err' => [
            'not_enough_tickets' => 'That many tickets are no longer available. Left: {available}.',
            'seats_taken' => 'Some seats were just taken. Please choose again.',
            'date_past' => 'This date is in the past.',
            'store_locked' => 'The shop is closed right now.',
            'hold_expired' => 'Your reservation expired. Please start over.',
            'invalid_name' => 'Please enter your first and last name.',
            'invalid_email' => 'Please check your email address.',
            'email_domain' => 'Mail cannot be delivered to this domain. Please check the address.',
            'consent_required' => 'Please confirm the booking.',
            'too_many_reservations' => 'Too many unpaid tickets are already reserved for this email. Please pay online or contact the organizer.',
            'too_many_requests' => 'Too many attempts. Please wait a few minutes.',
            'too_fast' => 'One moment please, then submit again.',
            'payment_not_authorized' => 'The payment was not confirmed. Nothing was charged.',
            'payment_failed' => 'The payment failed. Nothing was charged.',
            'payment_unavailable' => 'Card payment is unavailable right now. Please try again shortly.',
            'session_expired' => 'Your session expired. Please reload the page.',
            'backend_unreachable' => 'The ticket server cannot be reached. Nothing was charged.',
            'method_not_allowed' => 'This payment method is not available.',
            'amount_too_small' => 'The amount is too small for a card payment.',
            'generic' => 'That did not work. Nothing was charged. Please try again.',
        ],
    ],
][$lang];

$show = qrgate_public_show();
$csrf = generateCsrfToken();

$orga = $show['orga_name'] ?? '';
$pageTitle = ($orga !== '' ? $orga . ' · ' : '') . 'Tickets';
$assetBase = '';
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">'
    . '<link rel="stylesheet" href="assets/shop.css?v=7">';
if ($show && in_array('card', $show['methods'] ?? [], true)) {
    $extraHead .= '<script src="https://js.stripe.com/v3/" defer></script>';
}

$fmtPrice = function ($v) use ($de) {
    return number_format((float)$v, 2, $de ? ',' : '.', $de ? '.' : ',') . ' €';
};
$weekdays = $de ? ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$months = $de
    ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$upcoming = [];
$minPrice = null;
$venues = [];
if ($show) {
    foreach ($show['dates'] as $d) {
        if (!empty($d['past'])) {
            continue;
        }
        $upcoming[] = $d;
        if ($d['available'] > 0) {
            $minPrice = $minPrice === null ? $d['price'] : min($minPrice, $d['price']);
        }
        if ($d['location'] !== '' && isset($show['locations'][$d['location']])) {
            $venues[$d['location']] = $show['locations'][$d['location']]['name'];
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="avo-ui">
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="shop">

    <header class="shop-top">
        <div class="shop-top__in">
            <a href="index.php" class="qg-lockup">
                <img src="<?php echo PUBLIC_API_BASE; ?>/api/image/get/logo.png" alt="" onerror="this.style.display='none'">
                <b><?php echo $h($orga !== '' ? $orga : 'QrGate'); ?></b>
            </a>
            <span class="shop-top__sp"></span>
            <form method="post" class="shop-lang" aria-label="Language">
                <button name="language" value="de" class="<?php echo $de ? 'on' : ''; ?>" aria-pressed="<?php echo $de ? 'true' : 'false'; ?>">DE</button>
                <button name="language" value="en" class="<?php echo $de ? '' : 'on'; ?>" aria-pressed="<?php echo $de ? 'false' : 'true'; ?>">EN</button>
            </form>
            <button type="button" class="avo-theme-toggle" data-avo-theme-toggle aria-label="Toggle theme">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
            </button>
        </div>
    </header>

    <main class="avo-container shop-main">
    <?php if ($show === null): ?>
        <div class="avo-plate avo-status error shop-state" role="alert">
            <span class="avo-serial">ERR · BACKEND</span>
            <h1 class="avo-display-2"><?php echo $T['error_title']; ?></h1>
            <p class="avo-small"><?php echo $T['error_text']; ?></p>
            <a href="index.php" class="avo-btn"><?php echo $T['retry']; ?></a>
        </div>
    <?php else: ?>
        <section class="shop-hero">
            <div class="shop-hero__text">
                <div class="avo-kicker"><span><?php echo $T['tickets']; ?></span><i class="rule"></i></div>
                <h1 class="avo-display-1 shop-title"><?php echo $h($show['title'] !== '' ? $show['title'] : $orga); ?></h1>
                <?php if (trim($show['subtitle']) !== ''): ?>
                    <p class="avo-lead"><?php echo $h($show['subtitle']); ?></p>
                <?php endif; ?>
                <div class="avo-cluster shop-meta">
                    <?php if ($orga !== '' && $show['title'] !== ''): ?><span class="avo-chip"><?php echo $h($orga); ?></span><?php endif; ?>
                    <?php if ($upcoming): ?><span class="avo-chip"><?php echo count($upcoming) . ' ' . $T['dates']; ?></span><?php endif; ?>
                    <?php if ($minPrice !== null): ?><span class="avo-chip"><?php echo $T['from'] . ' ' . $fmtPrice($minPrice); ?></span><?php endif; ?>
                    <?php foreach ($venues as $vn): ?><span class="avo-chip"><?php echo $h($vn); ?></span><?php endforeach; ?>
                </div>
            </div>
            <figure class="shop-hero__img avo-plate" id="heroImg" hidden>
                <span class="avo-mk tl"></span><span class="avo-mk br"></span>
                <img src="<?php echo PUBLIC_API_BASE; ?>/api/image/get/banner.png" alt=""
                     onload="document.getElementById('heroImg').hidden=false" onerror="this.remove()">
            </figure>
        </section>

        <?php if (!empty($show['store_lock'])): ?>
            <div class="avo-plate avo-status warning shop-state" role="status">
                <span class="avo-serial">STORE · LOCKED</span>
                <h2 class="avo-display-2"><?php echo $T['locked_title']; ?></h2>
                <p class="avo-small"><?php echo $T['locked_text']; ?></p>
            </div>
        <?php else: ?>
        <section class="shop-dates" aria-labelledby="datesTitle">
            <div class="avo-kicker" id="datesTitle"><span><?php echo $T['dates']; ?></span><i class="rule"></i></div>
            <?php if (!$upcoming): ?>
                <div class="avo-plate" style="padding:0"><div class="avo-empty">
                    <div class="avo-kicker"><span><?php echo $T['dates']; ?></span></div>
                    <p class="avo-small"><?php echo $T['no_dates']; ?></p>
                </div></div>
            <?php else: ?>
            <div class="avo-plate date-list">
                <span class="avo-mk tl"></span><span class="avo-mk br"></span>
                <?php foreach ($upcoming as $d):
                    $ts = strtotime($d['date']);
                    $soldOut = $d['available'] <= 0;
                    $few = !$soldOut && $d['available'] <= max(10, (int)round($d['tickets'] * 0.1));
                    $loc = $show['locations'][$d['location']] ?? null;
                    $payload = [
                        'date' => $d['date'], 'time' => $d['time'], 'price' => $d['price'],
                        'available' => $d['available'], 'seating' => $d['seating'],
                        'location' => $loc['name'] ?? '', 'address' => $loc['address'] ?? '',
                        'label' => $weekdays[(int)date('w', $ts)] . ', ' . date('d.', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts),
                    ];
                ?>
                <article class="date-row<?php echo $soldOut ? ' is-out' : ''; ?>">
                    <div class="date-row__day" aria-hidden="true">
                        <span class="avo-serial"><?php echo $weekdays[(int)date('w', $ts)]; ?></span>
                        <b><?php echo date('d', $ts); ?></b>
                        <span class="avo-serial"><?php echo $months[(int)date('n', $ts) - 1] . ' ' . date('y', $ts); ?></span>
                    </div>
                    <div class="date-row__info">
                        <h3 class="date-row__title"><?php echo $h($payload['label']); ?></h3>
                        <div class="date-row__meta">
                            <span><?php echo $h($d['time']); ?></span>
                            <?php if ($loc): ?><span><?php echo $h($loc['name']); ?><?php if (trim($loc['address']) !== ''): ?><span class="avo-muted date-row__addr"> · <?php echo $h($loc['address']); ?></span><?php endif; ?></span><?php endif; ?>
                            <span class="avo-muted date-row__mode"><?php echo $d['seating'] ? $T['seated'] : $T['free_seating']; ?></span>
                        </div>
                    </div>
                    <div class="date-row__price">
                        <span class="avo-serial"><?php echo $d['seating'] ? $T['from'] : '&nbsp;'; ?></span>
                        <b class="tabular"><?php echo $fmtPrice($d['price']); ?></b>
                    </div>
                    <div class="date-row__state">
                        <?php if ($soldOut): ?>
                            <span class="avo-tag quiet"><?php echo $T['sold_out']; ?></span>
                        <?php elseif ($few): ?>
                            <span class="avo-tag warning"><?php echo str_replace('{n}', (string)$d['available'], $T['few_left']); ?></span>
                        <?php else: ?>
                            <span class="avo-tag quiet"><?php echo str_replace('{n}', (string)$d['available'], $T['available']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="date-row__act">
                        <button type="button" class="avo-btn" data-book='<?php echo $h(json_encode($payload)); ?>'
                            <?php echo $soldOut ? 'disabled' : ''; ?>
                            aria-label="<?php echo $h($T['book'] . ' · ' . $payload['label']); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                            <span><?php echo $soldOut ? $T['sold_out'] : $T['book']; ?></span>
                        </button>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="shop-how" aria-labelledby="howTitle">
            <div class="avo-kicker" id="howTitle"><span><?php echo $T['how_title']; ?></span><i class="rule"></i></div>
            <ol class="avo-grid c3 how-list">
                <li class="avo-plate"><span class="avo-serial live">01</span><p class="avo-small"><?php echo $T['how_1']; ?></p></li>
                <li class="avo-plate"><span class="avo-serial live">02</span><p class="avo-small"><?php echo $T['how_2']; ?></p></li>
                <li class="avo-plate"><span class="avo-serial live">03</span><p class="avo-small"><?php echo $T['how_3']; ?></p></li>
            </ol>
            <div class="avo-cluster shop-links">
                <a class="avo-chip" href="help/buy_ticket.php"><?php echo $T['help']; ?></a>
                <a class="avo-chip" href="cancel.php"><?php echo $T['cancel']; ?></a>
                <?php if ($show['contact_email'] !== ''): ?>
                    <a class="avo-chip" href="mailto:<?php echo $h($show['contact_email']); ?>"><?php echo $T['contact']; ?> · <?php echo $h($show['contact_email']); ?></a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    </main>

    <?php
    $orgName = $orga;
    $current_language = $lang;
    $showToggle = false;
    include __DIR__ . '/partials/footer.php';
    ?>

    <!-- Checkout. One dialog, two steps; the page behind stays put. -->
    <dialog id="co" class="avo-dialog co" aria-labelledby="coTitle">
        <header class="co-head">
            <div class="co-head__txt">
                <div class="avo-kicker"><span id="coStepLabel"></span></div>
                <h2 id="coTitle" class="co-title"></h2>
                <p id="coSub" class="avo-small"></p>
            </div>
            <button type="button" class="co-x" id="coClose" aria-label="<?php echo $J['close']; ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </header>
        <ol class="co-steps" aria-hidden="true">
            <li data-s="1"><span class="avo-serial">01</span> <?php echo $J['step1']; ?></li>
            <li data-s="2"><span class="avo-serial">02</span> <?php echo $J['step2']; ?></li>
        </ol>

        <div class="co-body">
            <div class="co-main">
                <!-- step 1 · general admission -->
                <section class="co-step" data-step="ga" hidden>
                    <div class="qty">
                        <span class="avo-label"><?php echo $J['qty']; ?></span>
                        <div class="qty__ctl">
                            <button type="button" class="qty__btn" data-qty="-1" aria-label="−">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>
                            <output id="qtyVal" class="qty__val" aria-live="polite">1</output>
                            <button type="button" class="qty__btn" data-qty="1" aria-label="+">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg></button>
                        </div>
                        <span class="avo-small" id="qtyPrice"></span>
                    </div>
                </section>

                <!-- step 1 · seated -->
                <section class="co-step" data-step="seats" hidden>
                    <div class="seat-bar">
                        <div class="qty qty--inline">
                            <span class="avo-label"><?php echo $J['qty']; ?></span>
                            <div class="qty__ctl">
                                <button type="button" class="qty__btn" data-want="-1" aria-label="−"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>
                                <output id="wantVal" class="qty__val qty__val--sm">1</output>
                                <button type="button" class="qty__btn" data-want="1" aria-label="+"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg></button>
                            </div>
                        </div>
                        <button type="button" class="avo-btn compact" id="seatBest">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.9 5.8H20l-4.9 3.6 1.9 5.8L12 14.6l-5 3.6 1.9-5.8L4 8.8h6.1z"/></svg>
                            <span><?php echo $J['seats_best']; ?></span></button>
                        <span class="seat-bar__sp"></span>
                        <div class="seat-zoom" role="group" aria-label="Zoom">
                            <button type="button" id="zOut" aria-label="Zoom out"><svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg></button>
                            <button type="button" id="zFit"><?php echo $J['seats_fit']; ?></button>
                            <button type="button" id="zIn" aria-label="Zoom in"><svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5v14"/></svg></button>
                        </div>
                    </div>
                    <div class="seat-map" id="seatMap">
                        <svg id="seatSvg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?php echo $J['seats_pick']; ?>"></svg>
                    </div>
                    <div class="seat-legend avo-cluster" id="seatLegend"></div>
                    <p class="co-note avo-status warning" id="seatOrphan" hidden></p>
                </section>

                <!-- step 2 · details + payment -->
                <section class="co-step" data-step="details" hidden>
                    <form id="coForm" class="co-form" novalidate autocomplete="on">
                        <div class="hp" aria-hidden="true">
                            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>
                        <div class="avo-grid c2 co-grid">
                            <div class="avo-field">
                                <label class="avo-label" for="fFirst"><?php echo $J['first_name']; ?> <span class="req">*</span></label>
                                <input class="avo-input" id="fFirst" name="first_name" autocomplete="given-name" maxlength="80" required>
                            </div>
                            <div class="avo-field">
                                <label class="avo-label" for="fLast"><?php echo $J['last_name']; ?> <span class="req">*</span></label>
                                <input class="avo-input" id="fLast" name="last_name" autocomplete="family-name" maxlength="80" required>
                            </div>
                        </div>
                        <div class="avo-field">
                            <label class="avo-label" for="fEmail"><?php echo $J['email']; ?> <span class="req">*</span></label>
                            <div class="avo-field-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                <input class="avo-input" id="fEmail" name="email" type="email" inputmode="email" autocomplete="email" spellcheck="false" maxlength="200" required>
                            </div>
                            <p class="avo-help"><?php echo $J['email_help']; ?></p>
                        </div>
                        <details class="co-guests" id="guestsBox" hidden>
                            <summary class="avo-label"><?php echo $J['guests']; ?></summary>
                            <p class="avo-help"><?php echo $J['guests_help']; ?></p>
                            <div class="avo-grid c2 co-grid" id="guestFields"></div>
                        </details>

                        <fieldset class="co-methods" id="methodBox">
                            <legend class="avo-label"><?php echo $J['pay_with']; ?></legend>
                            <label class="co-method" data-m="card">
                                <input type="radio" class="avo-radio" name="method" value="card">
                                <span><b><?php echo $J['m_card']; ?></b><span class="avo-small"><?php echo $J['m_card_sub']; ?></span></span>
                            </label>
                            <label class="co-method" data-m="cash">
                                <input type="radio" class="avo-radio" name="method" value="cash">
                                <span><b><?php echo $J['m_cash']; ?></b><span class="avo-small"><?php echo $J['m_cash_sub']; ?></span></span>
                            </label>
                        </fieldset>

                        <div id="cardBox" class="co-card" hidden>
                            <div id="cardLoading" class="co-loading"><span class="avo-spinner sm" role="status"></span><span class="avo-serial"><?php echo $J['card_loading']; ?></span></div>
                            <div id="cardElement"></div>
                        </div>

                        <label class="avo-choice co-consent">
                            <input type="checkbox" class="avo-check" id="fConsent" name="consent" value="1">
                            <span id="consentText"></span>
                        </label>
                    </form>
                </section>

                <!-- expired -->
                <section class="co-step" data-step="expired" hidden>
                    <div class="avo-plate avo-status warning co-msg">
                        <span class="avo-serial">HOLD · EXPIRED</span>
                        <h3 class="avo-title"><?php echo $J['expired_title']; ?></h3>
                        <p class="avo-small"><?php echo $J['expired_text']; ?></p>
                    </div>
                </section>

                <!-- done -->
                <section class="co-step" data-step="done" hidden>
                    <div class="stub">
                        <div class="stub__top">
                            <span class="avo-serial" id="doneSerial"></span>
                            <h3 class="avo-display-2" id="doneTitle"></h3>
                        </div>
                        <div class="stub__perf" aria-hidden="true"></div>
                        <dl class="stub__rows" id="doneRows"></dl>
                        <p class="avo-small stub__note" id="doneText"></p>
                        <p class="avo-small avo-muted"><?php echo $J['done_spam']; ?></p>
                    </div>
                </section>

            </div>

            <aside class="co-side">
                <div class="co-sum">
                    <div class="avo-kicker"><span><?php echo $J['summary']; ?></span></div>
                    <ul class="co-lines" id="sumLines"></ul>
                    <div class="co-total"><span class="avo-label"><?php echo $J['total']; ?></span><b id="sumTotal" class="tabular">0,00 €</b></div>
                    <div class="co-hold" id="holdBox" hidden>
                        <span class="avo-label"><?php echo $J['reserved_for']; ?></span>
                        <b id="holdTime" class="tabular">10:00</b>
                        <div class="co-holdbar"><span id="holdBar"></span></div>
                    </div>
                    <p class="avo-help" id="sumNote"></p>
                </div>
            </aside>
        </div>

        <p class="co-error" id="coError" role="alert" hidden></p>
        <footer class="co-foot">
            <button type="button" class="avo-btn" id="coBack"><span><?php echo $J['back']; ?></span></button>
            <span class="co-foot__sp"></span>
            <button type="button" class="avo-btn primary" id="coNext">
                <span id="coNextLabel"><?php echo $J['next']; ?></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
        </footer>
    </dialog>

    <script>
        window.QG = <?php echo json_encode([
            'lang' => $lang,
            'csrf' => $csrf,
            'methods' => $show['methods'] ?? [],
            'stripeKey' => $show['stripe_publishable_key'] ?? '',
            'orga' => $orga,
            'title' => $show['title'] ?? '',
            'maxPerOrder' => $show['max_per_order'] ?? 10,
            't' => $J,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="assets/shop.js?v=2" defer></script>

    <?php if ($askLang): ?>
    <dialog id="langAsk" class="avo-dialog lang-ask" aria-labelledby="langAskTitle">
        <form method="post" class="lang-ask__in">
            <h2 id="langAskTitle" class="co-title">Sprache wählen · Choose language</h2>
            <p class="avo-small">Deine Tickets und E-Mails bekommst du in dieser Sprache.<br>Your tickets and emails will be in this language.</p>
            <div class="lang-ask__opts">
                <button name="language" value="de" class="avo-btn<?php echo $de ? ' primary' : ''; ?>"<?php echo $de ? ' autofocus' : ''; ?>>Deutsch</button>
                <button name="language" value="en" class="avo-btn<?php echo $de ? '' : ' primary'; ?>"<?php echo $de ? '' : ' autofocus'; ?>>English</button>
            </div>
        </form>
    </dialog>
    <script>
        (function () {
            var d = document.getElementById('langAsk');
            if (!d || typeof d.showModal !== 'function') return;
            d.addEventListener('cancel', function (e) { e.preventDefault(); });
            d.showModal();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
