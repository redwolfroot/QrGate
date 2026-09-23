<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $_SESSION['language'] = $_POST['language'] === 'de' ? 'de' : 'en';
    header('Location: buy_ticket.php');
    exit();
}
$lang = ($_SESSION['language'] ?? 'en') === 'de' ? 'de' : 'en';

$T = [
    'de' => [
        'page_title' => 'Hilfe zum Ticketkauf',
        'kicker' => 'Hilfe',
        'title' => 'So kaufst du Tickets',
        'lead' => 'Zwei Schritte, ein Formular. Deine Tickets sind reserviert, sobald du die Anzahl oder deine Plätze bestätigt hast.',
        'back' => 'Zurück zum Shop',
        'steps_title' => 'Ablauf',
        'steps' => [
            ['Termin wählen', 'Tippe im Shop beim gewünschten Termin auf „Tickets“.'],
            ['Anzahl oder Plätze festlegen', 'Bei freier Platzwahl wählst du die Anzahl. Bei Terminen mit Saalplan tippst du deine Plätze an oder lässt dir mit „Beste Plätze wählen“ die besten nebeneinanderliegenden Plätze vorschlagen.'],
            ['Weiter: Tickets werden reserviert', 'Ab jetzt sind die Tickets 10 Minuten für dich reserviert. Der Timer rechts zeigt, wie viel Zeit bleibt. Ist ein Termin inzwischen ausverkauft, erfährst du es genau hier, bevor du etwas bezahlst.'],
            ['Daten eingeben und bezahlen', 'Name und E-Mail eingeben, Zahlungsart wählen, Buchung bestätigen. Die Namen der Begleitpersonen sind optional.'],
            ['Tickets per E-Mail', 'Die Tickets kommen als PDF mit QR-Code per E-Mail. Am Einlass den QR-Code auf dem Handy oder ausgedruckt zeigen.'],
        ],
        'pay_title' => 'Zahlungsarten',
        'card' => 'Karte, Apple Pay, Google Pay',
        'card_text' => 'Deine Karte wird zuerst nur vorgemerkt. Abgebucht wird erst, wenn deine Tickets sicher angelegt sind. Geht dabei etwas schief, wird die Vormerkung aufgehoben und nichts abgebucht. Die Tickets gelten sofort.',
        'cash' => 'Bar an der Abendkasse',
        'cash_text' => 'Du reservierst verbindlich und zahlst am Veranstaltungstag vor dem Einlass an der Kasse. Die Tickets kommen trotzdem gleich per E-Mail und werden beim Bezahlen freigeschaltet.',
        'tips_title' => 'Gut zu wissen',
        'tips' => [
            'Nichts angekommen? Schau im Spam-Ordner nach und prüfe die E-Mail-Adresse.',
            'Pro Bestellung sind bis zu 10 Tickets möglich.',
            'Läuft die Reservierung ab, werden die Tickets wieder frei. Du kannst einfach neu starten.',
            'Stornieren kannst du über den Link in deiner Ticket-E-Mail.',
        ],
        'contact' => 'Noch Fragen? Schreib an',
        'contact_fallback' => 'Noch Fragen? Wende dich an den Veranstalter.',
    ],
    'en' => [
        'page_title' => 'How to buy tickets',
        'kicker' => 'Help',
        'title' => 'How to buy tickets',
        'lead' => 'Two steps, one form. Your tickets are reserved as soon as you confirm the quantity or your seats.',
        'back' => 'Back to the shop',
        'steps_title' => 'Steps',
        'steps' => [
            ['Pick a date', 'Tap “Tickets” next to the date you want in the shop.'],
            ['Choose quantity or seats', 'For free seating you pick the number of tickets. For dates with a seat map you tap your seats or let “Pick best seats” suggest the best seats side by side.'],
            ['Continue: your tickets are reserved', 'From now on the tickets are held for you for 10 minutes. The timer on the right shows how much time is left. If a date sold out meanwhile, you find out right here, before you pay anything.'],
            ['Enter your details and pay', 'Enter name and email, choose how to pay, confirm the booking. Names of your guests are optional.'],
            ['Tickets by email', 'Your tickets arrive as a PDF with a QR code. Show the QR code at the door, on your phone or printed.'],
        ],
        'pay_title' => 'Payment',
        'card' => 'Card, Apple Pay, Google Pay',
        'card_text' => 'Your card is only authorised at first. It is charged once your tickets have been created. If anything goes wrong, the authorisation is released and nothing is charged. The tickets are valid right away.',
        'cash' => 'Cash at the box office',
        'cash_text' => 'You reserve with obligation and pay at the box office on the day, before entry. You still get the tickets by email right away; they are activated when you pay.',
        'tips_title' => 'Good to know',
        'tips' => [
            'Nothing arrived? Check your spam folder and the email address you entered.',
            'Up to 10 tickets per order.',
            'If the reservation expires, the tickets are released again. Just start over.',
            'To cancel, use the link in your ticket email.',
        ],
        'contact' => 'Questions? Write to',
        'contact_fallback' => 'Questions? Please contact the organizer.',
    ],
][$lang];

$show = qrgate_public_show();
$orga = $show['orga_name'] ?? '';
$contact = $show['contact_email'] ?? '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$pageTitle = ($orga !== '' ? $orga . ' · ' : '') . $T['page_title'];
$assetBase = '../';
$extraHead = <<<HTML
<style>
    .help { padding-top: var(--avo-space-7); display: flex; flex-direction: column; gap: var(--avo-space-6); max-width: 860px; }
    .help-top { display: flex; align-items: center; justify-content: space-between; gap: var(--avo-space-3); padding: var(--avo-space-4) var(--avo-container-pad); border-bottom: 1px solid var(--avo-line); position: relative; z-index: 1; }
    .help-lang { display: inline-flex; border: 1px solid var(--avo-line-strong); border-radius: var(--avo-radius); overflow: hidden; }
    .help-lang button { height: 30px; padding: 0 10px; font-family: var(--avo-font-mono); font-size: var(--avo-ui-label); letter-spacing: .1em; color: var(--avo-text-muted); background: none; border: 0; cursor: pointer; }
    .help-lang button + button { border-left: 1px solid var(--avo-line); }
    .help-lang button.on { color: var(--avo-text); box-shadow: inset 0 -2px 0 var(--avo-primary); }
    .help-head { display: flex; flex-direction: column; gap: var(--avo-space-3); }
    .help-steps { list-style: none; margin: 0; padding: 0; }
    .help-steps li { display: grid; grid-template-columns: 36px minmax(0, 1fr); gap: var(--avo-space-3); padding: var(--avo-space-4) var(--avo-space-5); border-bottom: 1px solid var(--avo-line); }
    .help-steps li:last-child { border-bottom: 0; }
    .help-steps b, .help-pay b { display: block; font-weight: 600; margin-bottom: 4px; }
    .help-pay { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--avo-space-3); }
    .help-pay > div, .avo-ui .help-pay > div { padding: var(--avo-space-5); }
    .help-tips, .avo-ui .help-tips { margin: 0; padding: var(--avo-space-4) var(--avo-space-5); list-style: none; display: flex; flex-direction: column; gap: var(--avo-space-2); }
    .help-tips li::before { content: "// "; color: var(--avo-primary); font-family: var(--avo-font-mono); }
    .help section { display: flex; flex-direction: column; gap: var(--avo-space-3); }
    @media (max-width: 700px) { .help-pay { grid-template-columns: minmax(0, 1fr); } }
</style>
HTML;
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body>
    <header class="help-top">
        <a class="avo-btn compact" href="../index.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            <span><?php echo $T['back']; ?></span>
        </a>
        <form method="post" class="help-lang" aria-label="Language">
            <button name="language" value="de" class="<?php echo $lang === 'de' ? 'on' : ''; ?>">DE</button>
            <button name="language" value="en" class="<?php echo $lang === 'en' ? 'on' : ''; ?>">EN</button>
        </form>
    </header>

    <main class="avo-container help">
        <div class="help-head">
            <div class="avo-kicker"><span><?php echo $T['kicker']; ?></span><i class="rule"></i></div>
            <h1 class="avo-display-1"><?php echo $T['title']; ?></h1>
            <p class="avo-lead"><?php echo $T['lead']; ?></p>
        </div>

        <section aria-labelledby="stepsTitle">
            <div class="avo-kicker" id="stepsTitle"><span><?php echo $T['steps_title']; ?></span><i class="rule"></i></div>
            <ol class="avo-plate help-steps" style="padding:0">
                <?php foreach ($T['steps'] as $i => [$title, $text]): ?>
                    <li><span class="avo-serial live"><?php echo sprintf('%02d', $i + 1); ?></span>
                        <div><b><?php echo $title; ?></b><p class="avo-small"><?php echo $text; ?></p></div></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section aria-labelledby="payTitle">
            <div class="avo-kicker" id="payTitle"><span><?php echo $T['pay_title']; ?></span><i class="rule"></i></div>
            <div class="help-pay">
                <div class="avo-plate"><span class="avo-mk tl"></span><span class="avo-mk br"></span><b><?php echo $T['card']; ?></b><p class="avo-small"><?php echo $T['card_text']; ?></p></div>
                <div class="avo-plate"><span class="avo-mk tl"></span><span class="avo-mk br"></span><b><?php echo $T['cash']; ?></b><p class="avo-small"><?php echo $T['cash_text']; ?></p></div>
            </div>
        </section>

        <section aria-labelledby="tipsTitle">
            <div class="avo-kicker" id="tipsTitle"><span><?php echo $T['tips_title']; ?></span><i class="rule"></i></div>
            <ul class="avo-plate help-tips">
                <?php foreach ($T['tips'] as $tip): ?><li class="avo-small"><?php echo $tip; ?></li><?php endforeach; ?>
            </ul>
            <p class="avo-small">
                <?php if ($contact !== ''): ?>
                    <?php echo $T['contact']; ?> <a class="avo-link" href="mailto:<?php echo $h($contact); ?>"><?php echo $h($contact); ?></a>
                <?php else: ?>
                    <?php echo $T['contact_fallback']; ?>
                <?php endif; ?>
            </p>
        </section>
    </main>

    <?php
    $orgName = $orga;
    $current_language = $lang;
    $privacyHref = '../datenschutz.php';
    include __DIR__ . '/../partials/footer.php';
    ?>
</body>
</html>
