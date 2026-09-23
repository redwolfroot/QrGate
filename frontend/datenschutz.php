<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $_SESSION['language'] = in_array($_POST['language'], ['de', 'en'], true) ? $_POST['language'] : 'en';
    header("Location: datenschutz.php");
    exit();
}

$shows = getShows();
$orga_name = htmlspecialchars($shows['orga_name'] ?? 'QrGate');

$current_language = $_SESSION['language'] ?? 'en';
if (!in_array($current_language, ['de', 'en'], true)) {
    $current_language = 'en';
}
$is_de = $current_language === 'de';

$pageTitle = $orga_name . ' – ' . ($is_de ? 'Datenschutzerklärung' : 'Privacy Policy');
$assetBase = '';
$extraHead = <<<HTML
    <style>
        @keyframes fadein {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadein 0.5s ease both; }

        .section-label::before {
            content: '';
            display: inline-block;
            width: 48px;
            height: 2px;
            border-radius: 9999px;
            flex-shrink: 0;
            background: linear-gradient(90deg, var(--avo-coral-700), var(--avo-coral-400));
        }

        .content-list {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            margin: 0.5rem 0;
        }
        .content-list li {
            color: var(--avo-text-muted);
            font-size: 0.875rem;
            line-height: 1.625;
            padding-left: 1.25rem;
            position: relative;
        }
        .content-list li::before {
            content: '\\2022';
            position: absolute;
            left: 0;
            color: var(--avo-primary);
        }
        .content-list li strong {
            color: var(--avo-text);
            font-weight: 600;
        }
    </style>
HTML;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language; ?>" class="avo-ui">
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="bg-background text-foreground min-h-screen flex flex-col pt-1">

    <!-- Gradient bar -->
    <div class="avo-topbar fixed top-0 left-0 right-0 z-50"></div>

    <!-- Navigation -->
    <nav class="fixed top-1 left-0 right-0 z-40 flex items-center justify-between px-7 py-2.5"
         style="background:color-mix(in oklab, var(--avo-bg) 80%, transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--avo-border);">
        <a href="index.php" class="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            <?php echo $is_de ? 'Zurück' : 'Back'; ?>
        </a>

        <span class="absolute left-1/2 -translate-x-1/2 text-sm font-bold text-muted-foreground qg-wordmark">
            <?php echo $orga_name; ?>
        </span>

        <form method="POST">
            <button type="submit" name="language" value="<?php echo $is_de ? 'en' : 'de'; ?>"
                class="h-8 px-3 rounded-full text-xs font-bold tracking-wider text-muted-foreground border bg-transparent hover:text-foreground transition-colors cursor-pointer avo-bordered hover:border-[var(--avo-primary)]">
                <?php echo $is_de ? '🇬🇧 EN' : '🇩🇪 DE'; ?>
            </button>
        </form>
    </nav>

    <!-- Main content -->
    <main class="fade-in max-w-3xl w-full mx-auto px-6 pt-24 pb-16 flex-1">

        <?php if ($is_de): ?>

        <div class="section-label flex items-center gap-3 mb-1"><span class="avo-kicker">DSGVO</span></div>
        <h1 class="text-4xl font-extrabold tracking-tight mb-2" style="font-size:clamp(1.8rem,4vw,2.6rem)">Daten<span class="avo-hl">schutz</span>erklärung</h1>
        <p class="text-muted-foreground text-sm mb-10 pb-8 border-b border-border">
            Gültig für den Ticketshop von <strong class="text-foreground"><?php echo $orga_name; ?></strong>
        </p>

        <!-- 01 -->
        <div class="card p-6 mb-3 border-t-2 avo-bordered" style="border-top-color: var(--avo-primary);">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">01</span> Verantwortlicher
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Verantwortlicher für die Verarbeitung personenbezogener Daten im Rahmen dieses Ticketshops
                ist der jeweilige Veranstalter. Kontaktdaten sind auf der Veranstaltungsseite oder bei der
                Veranstaltung selbst erhältlich.
            </p>
        </div>

        <!-- 02 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">02</span> Welche Daten wir verarbeiten
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">Im Rahmen des Ticketkaufs werden folgende personenbezogene Daten erfasst:</p>
            <ul class="content-list">
                <li>Vorname und Nachname</li>
                <li>E-Mail-Adresse</li>
                <li>Anzahl der Tickets und gewählter Veranstaltungstermin</li>
                <li>Namen von Begleitpersonen (sofern angegeben)</li>
                <li>Gewählte Zahlungsmethode (Bar oder Online)</li>
                <li>Bei Onlinezahlung: Zahlungsreferenz (Payment Intent ID) über Stripe</li>
            </ul>
            <p class="text-muted-foreground text-sm leading-relaxed">Aus diesen Daten wird ein eindeutiger QR-Code generiert und als PDF erstellt.</p>
        </div>

        <!-- 03 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">03</span> Zweck und Rechtsgrundlage
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Die Daten werden ausschließlich zur Abwicklung des Ticketkaufs und zur Einlasskontrolle verarbeitet.
                Rechtsgrundlage ist <code class="text-xs">Art. 6 Abs. 1 lit. b DSGVO</code> (Vertragserfüllung).
            </p>
            <ul class="content-list">
                <li><strong>Ticketerstellung:</strong> Speicherung im System, Generierung von QR-Code und PDF</li>
                <li><strong>E-Mail-Versand:</strong> Übermittlung des Tickets an die angegebene E-Mail-Adresse</li>
                <li><strong>Einlasskontrolle:</strong> Validierung des QR-Codes beim Veranstaltungseinlass</li>
            </ul>
        </div>

        <!-- 04 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">04</span> Weitergabe an Dritte
            </h2>
            <h3 class="text-sm font-semibold text-foreground mb-1 mt-0">Stripe – Onlinezahlung</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Bei Onlinezahlung wird die Transaktion über <strong class="text-foreground">Stripe Payments Europe, Ltd.</strong> abgewickelt.
                Stripe verarbeitet Zahlungsdaten (z.&thinsp;B. Kartendaten) gemäß eigener Datenschutzerklärung.
                Die Zahlungsreferenz wird serverseitig zur Verifikation genutzt. Kartendaten werden nie auf unseren Servern gespeichert.
            </p>
            <p class="text-muted-foreground/60 text-xs mt-1">Weitere Informationen: stripe.com/de/privacy</p>

            <h3 class="text-sm font-semibold text-foreground mb-1 mt-4">E-Mail-Versand</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Das Ticket wird per E-Mail über einen SMTP-Server zugestellt.
                Die E-Mail-Adresse wird ausschließlich für den Ticketversand verwendet.
            </p>

            <h3 class="text-sm font-semibold text-foreground mb-1 mt-4">Externe Ressourcen (CDN / Schriften)</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Diese Seite lädt Schriftarten von Google Fonts und Ressourcen über externe CDNs.
                Dabei kann Ihre IP-Adresse an diese Server übermittelt werden.
                Es werden keine Cookies gesetzt und keine Nutzerprofile erstellt.
            </p>
        </div>

        <!-- 05 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">05</span> Speicherdauer
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Ihre Ticketdaten (Name, E-Mail, QR-Code) werden für die Dauer der Veranstaltung und einer
                anschließenden gesetzlichen Aufbewahrungsfrist gespeichert (insbesondere Buchhaltungs- und
                Steuerrecht). Die konkrete Dauer wird durch den Veranstalter festgelegt.
            </p>
        </div>

        <!-- 06 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">06</span> Ihre Rechte
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">Sie haben nach der DSGVO folgende Rechte gegenüber dem Veranstalter:</p>
            <ul class="content-list">
                <li><strong>Auskunftsrecht</strong> <code class="text-xs">Art. 15</code></li>
                <li><strong>Recht auf Berichtigung</strong> <code class="text-xs">Art. 16</code></li>
                <li><strong>Recht auf Löschung</strong> <code class="text-xs">Art. 17</code></li>
                <li><strong>Recht auf Einschränkung der Verarbeitung</strong> <code class="text-xs">Art. 18</code></li>
                <li><strong>Recht auf Datenübertragbarkeit</strong> <code class="text-xs">Art. 20</code></li>
                <li><strong>Widerspruchsrecht</strong> <code class="text-xs">Art. 21</code></li>
            </ul>
            <div class="mt-3 p-3 rounded-md border border-border bg-muted/30 text-muted-foreground text-xs leading-relaxed">
                Zur Ausübung Ihrer Rechte wenden Sie sich bitte direkt an den Veranstalter (Kontaktdaten auf der Veranstaltungsankündigung).
            </div>
        </div>

        <!-- 07 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">07</span> Beschwerderecht
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Sie haben das Recht, bei der zuständigen Aufsichtsbehörde eine Beschwerde einzureichen:
            </p>
            <div class="mt-3 p-4 rounded-lg border border-border bg-muted/20 text-sm leading-relaxed">
                <strong class="text-foreground block mb-0.5">Österreichische Datenschutzbehörde</strong>
                <span class="text-muted-foreground">Barichgasse 40–42, 1030 Wien &mdash; dsb.gv.at</span>
            </div>
        </div>

        <!-- 08 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">08</span> Automatisierte Entscheidungsfindung
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Es findet keine automatisierte Entscheidungsfindung oder Profilerstellung
                im Sinne von <code class="text-xs">Art. 22 DSGVO</code> statt.
            </p>
        </div>

        <!-- 09 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">09</span> Cookies
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Diese Seite verwendet ausschließlich technisch notwendige Session-Cookies zur
                Sprachauswahl und Anzeige von Systemmeldungen. Es werden keine Tracking-,
                Analyse- oder Werbe-Cookies eingesetzt.
            </p>
        </div>

        <?php else: ?>

        <div class="section-label flex items-center gap-3 mb-1"><span class="avo-kicker">GDPR</span></div>
        <h1 class="text-4xl font-extrabold tracking-tight mb-2" style="font-size:clamp(1.8rem,4vw,2.6rem)">Privacy <span class="avo-hl">Policy</span></h1>
        <p class="text-muted-foreground text-sm mb-10 pb-8 border-b border-border">
            Applicable to the ticket shop of <strong class="text-foreground"><?php echo $orga_name; ?></strong>
        </p>

        <!-- 01 -->
        <div class="card p-6 mb-3 border-t-2 avo-bordered" style="border-top-color: var(--avo-primary);">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">01</span> Controller
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                The controller responsible for processing personal data in this ticket shop is the respective
                event organizer. Contact details are available on the event page or at the event itself.
            </p>
        </div>

        <!-- 02 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">02</span> Data We Process
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">The following personal data is collected during ticket purchase:</p>
            <ul class="content-list">
                <li>First name and last name</li>
                <li>Email address</li>
                <li>Number of tickets and selected event date</li>
                <li>Names of accompanying persons (if provided)</li>
                <li>Selected payment method (cash or online)</li>
                <li>For online payment: payment reference (Payment Intent ID) via Stripe</li>
            </ul>
            <p class="text-muted-foreground text-sm leading-relaxed">A unique QR code is generated from this data and provided as a PDF.</p>
        </div>

        <!-- 03 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">03</span> Purpose and Legal Basis
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Your data is processed exclusively for completing the ticket purchase and for access control at the event.
                The legal basis is <code class="text-xs">Art. 6(1)(b) GDPR</code> (performance of a contract).
            </p>
            <ul class="content-list">
                <li><strong>Ticket creation:</strong> Storage in the system, generation of QR code and PDF</li>
                <li><strong>Email delivery:</strong> Sending your ticket to the provided email address</li>
                <li><strong>Access control:</strong> Validation of the QR code at the event entrance</li>
            </ul>
        </div>

        <!-- 04 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">04</span> Disclosure to Third Parties
            </h2>
            <h3 class="text-sm font-semibold text-foreground mb-1 mt-0">Stripe – Online payment</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                For online payment, the transaction is processed by <strong class="text-foreground">Stripe Payments Europe, Ltd.</strong>
                Stripe processes payment data (e.g. card details) under its own privacy policy.
                The payment reference is used server-side to verify the transaction. Card data is never stored on our servers.
            </p>
            <p class="text-muted-foreground/60 text-xs mt-1">More information: stripe.com/privacy</p>

            <h3 class="text-sm font-semibold text-foreground mb-1 mt-4">Email delivery</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Your ticket is sent by email via a configured SMTP server.
                Your email address is used solely for ticket delivery.
            </p>

            <h3 class="text-sm font-semibold text-foreground mb-1 mt-4">External resources (CDN / fonts)</h3>
            <p class="text-muted-foreground text-sm leading-relaxed">
                This page loads fonts from Google Fonts and resources via external CDNs.
                Your IP address may be transmitted to these servers.
                No cookies are set and no user profiles are created.
            </p>
        </div>

        <!-- 05 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">05</span> Retention Period
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                Your ticket data (name, email, QR code) is stored for the duration of the event and a subsequent
                statutory retention period (in particular accounting and tax law). The specific duration is
                determined by the event organizer.
            </p>
        </div>

        <!-- 06 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">06</span> Your Rights
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">Under the GDPR you have the following rights with respect to the event organizer:</p>
            <ul class="content-list">
                <li><strong>Right of access</strong> <code class="text-xs">Art. 15</code></li>
                <li><strong>Right to rectification</strong> <code class="text-xs">Art. 16</code></li>
                <li><strong>Right to erasure</strong> <code class="text-xs">Art. 17</code></li>
                <li><strong>Right to restriction of processing</strong> <code class="text-xs">Art. 18</code></li>
                <li><strong>Right to data portability</strong> <code class="text-xs">Art. 20</code></li>
                <li><strong>Right to object</strong> <code class="text-xs">Art. 21</code></li>
            </ul>
            <div class="mt-3 p-3 rounded-md border border-border bg-muted/30 text-muted-foreground text-xs leading-relaxed">
                To exercise your rights, please contact the event organizer directly (contact details in the event announcement).
            </div>
        </div>

        <!-- 07 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">07</span> Right to Lodge a Complaint
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                You have the right to lodge a complaint with the competent supervisory authority:
            </p>
            <div class="mt-3 p-4 rounded-lg border border-border bg-muted/20 text-sm leading-relaxed">
                <strong class="text-foreground block mb-0.5">Datenschutzbehörde (Austrian Data Protection Authority)</strong>
                <span class="text-muted-foreground">Barichgasse 40–42, 1030 Vienna, Austria &mdash; dsb.gv.at</span>
            </div>
        </div>

        <!-- 08 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">08</span> Automated Decision-Making
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                No automated decision-making or profiling within the meaning of <code class="text-xs">Art. 22 GDPR</code> takes place.
            </p>
        </div>

        <!-- 09 -->
        <div class="card p-6 mb-3">
            <h2 class="text-base font-bold flex items-center gap-2 mb-3">
                <span class="badge badge-secondary text-xs">09</span> Cookies
            </h2>
            <p class="text-muted-foreground text-sm leading-relaxed">
                This site uses only technically necessary session cookies for language selection and
                displaying system messages. No tracking, analytics, or advertising cookies are used.
            </p>
        </div>

        <?php endif; ?>

    </main>

    <?php
    $orgName = $shows['orga_name'] ?? '';
    include __DIR__ . '/partials/footer.php';
    ?>

</body>
</html>
