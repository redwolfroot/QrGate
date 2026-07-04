<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $_SESSION['language'] = in_array($_POST['language'], ['de', 'en'], true) ? $_POST['language'] : 'en';
    $_SESSION['language_selected'] = true;
    header("Location: index.php");
    exit();
}

$languages = [
    'en' => [
        'flag' => '🇬🇧',
        'name' => 'Englisch',
        'error_loading_shows' => "We can't load events right now",
        'try_again' => 'Please try again shortly. If this keeps happening, contact the organizer.',
        'try_again_button' => 'Try again',
        'buy_tickets' => 'Buy Tickets',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'number_of_tickets' => 'Number of Tickets',
        'payment_method' => 'Payment Method',
        'cash_payment' => 'Cash payment',
        'online_payment' => 'Online payment',
        'book_tickets' => 'Book Tickets',
        'need_help' => 'Do you need help?',
        'sold_out' => 'Sold out',
        'tickets_left' => 'Only {count} tickets left!',
        'tickets_available' => '{available} of {total} tickets available.',
        'store_lock_title' => 'Store locked',
        'store_lock_message' => 'The ticket shop of {name} is currently closed. Maby there are currently no tickets to sell? Please check back later or contact the operator for more information.',
        'booking_notice_title' => 'Binding booking',
        'binding_notice' => 'Your booking is binding. By confirming, you commit to attending on the selected date.',
        'booking_notice_title_card' => 'Binding purchase',
        'binding_notice_card' => 'Your purchase is binding. By confirming you complete the payment and commit to attending on the selected date.',
        'consent_label_card' => 'I confirm that this purchase is binding and that I will attend on the selected date.',
        'storno_card' => 'Paid by card: in case of cancellation, refunds are handled by the organizer.',
        'storno_cash' => 'Cash payment: you pay on site — nothing is charged now, but the booking is still binding.',
        'storno_contact' => 'Questions or cancellation? Please contact {contact}.',
        'the_organizer' => 'the organizer',
        'consent_label' => 'I confirm that this booking is binding and that I will attend on the selected date.',
        'consent_required' => 'Please confirm the booking notice to continue.',
        'step_of' => 'Step {n} of 4',
        'step1_title' => 'Your details',
        'step2_title' => 'Tickets',
        'step3_title' => 'Payment method',
        'step4_title' => 'Confirm &amp; pay',
        'back' => 'Back',
        'next' => 'Next',
        'pay_now' => 'Pay now',
        'summary' => 'Summary',
        'total' => 'Total',
        'choose_payment' => 'How would you like to pay?',
        'date_label' => 'Date',
        'location_label' => 'Location',
    ],
    'de' => [
        'flag' => '🇩🇪',
        'name' => 'Deutsch',
        'error_loading_shows' => 'Veranstaltungen können gerade nicht geladen werden',
        'try_again' => 'Bitte versuchen Sie es in Kürze erneut. Falls das Problem weiterhin besteht, kontaktieren Sie den Veranstalter.',
        'try_again_button' => 'Erneut versuchen',
        'buy_tickets' => 'Tickets kaufen',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'email' => 'E-Mail',
        'number_of_tickets' => 'Anzahl der Tickets',
        'payment_method' => 'Zahlungsmethode',
        'cash_payment' => 'Barzahlung',
        'online_payment' => 'Online-Zahlung',
        'book_tickets' => 'Tickets buchen',
        'need_help' => 'Brauchen Sie Hilfe?',
        'sold_out' => 'Ausverkauft',
        'tickets_left' => 'Nur noch {count} Plätze frei!',
        'tickets_available' => '{available} von {total} Plätzen verfügbar.',
        'store_lock_title' => 'Shop gesperrt',
        'store_lock_message' => 'Der Ticketshop von {name} ist derzeit geschlossen. Möglicherweise sind derzeit keine Tickets zum Verkauf verfügbar? Bitte schauen Sie später wieder vorbei oder kontaktieren Sie den Betreiber für weitere Informationen.',
        'booking_notice_title' => 'Verbindliche Buchung',
        'binding_notice' => 'Ihre Buchung ist verbindlich. Mit der Bestätigung verpflichten Sie sich, am gewählten Termin zu erscheinen.',
        'booking_notice_title_card' => 'Verbindlicher Kauf',
        'binding_notice_card' => 'Ihr Kauf ist verbindlich. Mit der Bestätigung schließen Sie die Zahlung ab und verpflichten sich, am gewählten Termin zu erscheinen.',
        'consent_label_card' => 'Ich bestätige, dass dieser Kauf verbindlich ist und ich am gewählten Termin erscheine.',
        'storno_card' => 'Zahlung per Karte: Bei Storno werden Rückerstattungen vom Veranstalter abgewickelt.',
        'storno_cash' => 'Barzahlung: Sie zahlen vor Ort — es wird jetzt nichts abgebucht, die Buchung ist dennoch verbindlich.',
        'storno_contact' => 'Fragen oder Storno? Bitte kontaktieren Sie {contact}.',
        'the_organizer' => 'den Veranstalter',
        'consent_label' => 'Ich bestätige, dass diese Buchung verbindlich ist und ich am gewählten Termin erscheine.',
        'consent_required' => 'Bitte bestätigen Sie den Buchungshinweis, um fortzufahren.',
        'step_of' => 'Schritt {n} von 4',
        'step1_title' => 'Ihre Daten',
        'step2_title' => 'Tickets',
        'step3_title' => 'Zahlungsart',
        'step4_title' => 'Bestätigen &amp; bezahlen',
        'back' => 'Zurück',
        'next' => 'Weiter',
        'pay_now' => 'Jetzt bezahlen',
        'summary' => 'Übersicht',
        'total' => 'Gesamt',
        'choose_payment' => 'Wie möchten Sie bezahlen?',
        'date_label' => 'Datum',
        'location_label' => 'Ort',
    ],
];

$current_language = $_SESSION['language'] ?? 'en';
if (!isset($languages[$current_language])) {
    $current_language = 'en';
}
$is_de = $current_language === 'de';
// First visit (no language picked yet) → show the language chooser dialog on load.
$showLangDialog = empty($_SESSION['language_selected']);
$shows = getShows();

$pageTitle = htmlspecialchars($shows['orga_name'] ?? 'QrGate') . ' - Tickets';
$assetBase = '';
$extraHead = '';
if ($shows !== null) {
    $extraHead = <<<HTML
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            let availablePaymentMethods = 'both';
            let stripePublishableKey = '';

            async function loadPaymentMethods() {
                try {
                    const response = await fetch('api-proxy.php?endpoint=payment_methods');
                    const data = await response.json();
                    if (data.status === 'success') {
                        availablePaymentMethods = data.payment_methods;
                    }
                } catch (error) {
                    console.error('Error loading payment methods:', error);
                }
            }

            async function loadStripeKey() {
                try {
                    const response = await fetch('api-proxy.php?endpoint=stripe_pub_key');
                    const data = await response.json();
                    if (data.publishable_key) {
                        stripePublishableKey = data.publishable_key;
                    }
                } catch (error) {
                    console.error('Error loading Stripe key:', error);
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                loadPaymentMethods();
                loadStripeKey();
            });
        </script>
HTML;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language; ?>">

<?php include __DIR__ . '/partials/head.php'; ?>
<body>
    <style>
        .orga-name {
            color: var(--avo-text);
        }

        .language-selector {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }

        .language-selector select {
            background-color: var(--card-background);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: var(--avo-radius-sm);
            padding: 8px;
            cursor: pointer;
        }

        .language-selector .flag {
            margin-right: 5px;
        }

        @media (max-width: 640px) {
            .language-selector {
                bottom: 10px;
                right: 10px;
            }

            .language-selector select {
                padding: 6px;
                font-size: 0.875rem;
            }
        }

        .help-question {
            font-size: larger;
            margin-top: -10px;
        }

        #bookingModal::backdrop {
            background-color: rgba(0, 0, 0, 0.7) !important;
        }

        /* ---- Full-screen booking experience -------------------------------- */
        /* Base .dialog transitions ALL props (allow-discrete); animating width
           froze the modal. Fade opacity only, size is static. */
        #bookingModal { transition-property: opacity, overlay, display !important; }
        #bookingModal.dialog {
            width: 100vw; max-width: 100vw; height: 100dvh; max-height: 100dvh;
            margin: 0; border: 0; border-radius: 0; padding: 0;
            background: var(--avo-bg, #0b0b0b); color: var(--avo-text, #eee);
            overflow: hidden;
        }
        #bookingModal::backdrop { background: rgba(0, 0, 0, .85) !important; }
        /* Basecoat caps .dialog children at ~32rem; our shell must span the screen. */
        #bookingModal .booking-shell { max-width: none !important; width: 100% !important; }

        /* Two-column layout: fixed sidebar (summary + steps) + main content. */
        .booking-shell { display: flex; flex-direction: row; height: 100%; width: 100%; overflow: hidden; }
        .booking-side {
            flex: 0 0 320px; display: flex; flex-direction: column; gap: 1.25rem;
            padding: clamp(1.25rem, 2vw, 2rem);
            border-right: 1px solid var(--avo-border);
            background: color-mix(in oklab, var(--avo-surface) 60%, transparent);
            overflow-y: auto;
        }
        .booking-side-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
        .booking-close {
            flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
            width: 2.25rem; height: 2.25rem; border-radius: 8px; color: var(--avo-text);
            border: 1px solid var(--avo-border); background: var(--avo-card);
            cursor: pointer; transition: opacity .15s;
        }
        .booking-close:hover { opacity: .7; }
        .step-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .25rem; }
        .step-item {
            display: flex; align-items: center; gap: .75rem;
            padding: .7rem .75rem; border-radius: 10px; color: var(--avo-text-muted);
            font-size: .95rem; transition: background .15s, color .15s;
        }
        .step-item .step-num {
            flex: 0 0 auto; width: 1.75rem; height: 1.75rem; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .85rem; font-weight: 700;
            border: 1px solid var(--avo-border); background: var(--avo-card);
        }
        .step-item.active { color: var(--avo-text); background: color-mix(in oklab, var(--avo-primary) 14%, transparent); }
        .step-item.active .step-num { background: var(--avo-primary); color: #fff; border-color: var(--avo-primary); }
        .step-item.done { color: var(--avo-text); }
        .step-item.done .step-num { background: color-mix(in oklab, var(--avo-primary) 30%, transparent); border-color: var(--avo-primary); }

        .booking-main { flex: 1 1 auto; min-width: 0; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .booking-main > section { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        #bookingForm { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; gap: 0; padding: 0; }
        .wizard-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: clamp(1.25rem, 3vw, 2.5rem) clamp(1rem, 4vw, 3rem);
            display: flex; flex-direction: column; align-items: center; }
        /* margin:auto centres short steps but yields to the top when content is
           taller than the viewport, so nothing gets clipped/unreachable. */
        .wizard-step { width: 100%; max-width: 480px; margin-inline: auto; margin-block: auto; }
        .wizard-step[data-step="2"] { max-width: 1180px; margin-block: 0; }
        /* General-admission step 2 has no seat map, so keep it narrow + centred
           like the other steps instead of the wide, top-aligned map layout. */
        .wizard-step[data-step="2"].ga-mode { max-width: 480px; margin-block: auto; }
        .wizard-heading { font-size: 1.35rem; font-weight: 700; margin-bottom: .35rem; }
        .wizard-sub { color: var(--avo-text-muted); font-size: .95rem; margin-bottom: 1.25rem; }
        .wizard-nav { flex: 0 0 auto; border-top: 1px solid var(--avo-border); padding: 1rem clamp(1rem, 4vw, 3rem); }
        .wizard-nav .booking-inner { display: flex; gap: .75rem; width: 100%; max-width: 620px; margin: 0 auto; }

        /* Stack on narrow screens: sidebar becomes a compact top bar. */
        @media (max-width: 820px) {
            .booking-shell { flex-direction: column; }
            /* Ultra-slim single-row top bar: progress dots + close. Everything
               else (title, help, date card) is collapsed; date/step moves into
               the step content via #mobileStepBar. */
            .booking-side { flex: 0 0 auto; flex-direction: row; align-items: center; border-right: 0; border-bottom: 1px solid var(--avo-border); overflow: visible; gap: .5rem; padding: .5rem .8rem; }
            .booking-side-head { order: 2; margin-left: auto; align-items: center; }
            .booking-side-head > div { display: none; }          /* title + help */
            #dialogContext { display: none !important; }
            .booking-close { width: 2rem; height: 2rem; }
            /* Step list becomes connected progress dots. */
            .step-list { order: 1; flex: 0 1 auto; flex-direction: row; align-items: center; gap: 0; }
            .step-item { padding: 0; gap: 0; }
            .step-item .step-name { display: none !important; }
            .step-item.active .step-name { display: none !important; }
            .step-item .step-num { width: 1.35rem; height: 1.35rem; font-size: .72rem; }
            .step-item:not(:last-child)::after { content: ""; width: 1.1rem; height: 2px; background: var(--avo-border); margin: 0 .18rem; display: block; }
            .step-item.done::after { background: var(--avo-primary); }
            .booking-mobile-only { display: flex !important; }
            .booking-main { height: auto; flex: 1 1 auto; min-height: 0; }
        }

        @media (max-width: 640px) {
            #bookingModal.dialog {
                width: 100vw !important; max-width: 100vw !important;
                height: 100dvh !important; max-height: 100dvh !important;
                min-height: 100dvh !important;
                border: 0 !important; border-radius: 0 !important;
                margin: 0 !important; padding: 0 !important; z-index: 1000 !important;
            }
        }
    </style>
    <div class="language-selector">
        <form method="post" id="langForm">
            <select name="language" onchange="this.form.submit()">
                <?php foreach ($languages as $code => $lang): ?>
                    <option value="<?php echo $code; ?>" <?php echo ($current_language == $code) ? 'selected' : ''; ?>>
                        <span class="flag"><?php echo $lang['flag']; ?></span>
                        <?php echo $lang['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if ($showLangDialog): ?>
    <dialog id="langDialog" class="dialog w-full sm:max-w-[400px]" aria-labelledby="langDialogTitle">
        <div class="p-6 grid gap-5 text-center">
            <div>
                <div class="avo-kicker mb-2">// language · sprache</div>
                <h2 id="langDialogTitle" class="text-xl font-bold">Choose your language</h2>
                <p class="avo-muted text-sm mt-1">Wählen Sie Ihre Sprache</p>
            </div>
            <div class="grid gap-3">
                <?php foreach ($languages as $code => $lang): ?>
                    <form method="post">
                        <input type="hidden" name="language" value="<?php echo $code; ?>">
                        <button type="submit" class="btn-secondary w-full"
                            style="display:flex;align-items:center;justify-content:center;gap:.6rem;min-height:52px;">
                            <span style="font-size:1.4rem;line-height:1;" aria-hidden="true"><?php echo $lang['flag']; ?></span>
                            <span><?php echo htmlspecialchars($lang['name']); ?></span>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    </dialog>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var d = document.getElementById('langDialog');
            if (d && !d.open) d.showModal();
            // force a choice — don't let Escape dismiss it without picking
            if (d) d.addEventListener('cancel', function (e) { e.preventDefault(); });
        });
    </script>
    <?php endif; ?>
    <dialog id="error-dialog" class="dialog" aria-labelledby="error-dialog-title"
        aria-describedby="error-dialog-description">
        <div>
            <header>
                <h2 id="error-dialog-title" class="text-2xl inline-flex gap-x-2"><svg xmlns="http://www.w3.org/2000/svg"
                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-circle-alert-icon lucide-circle-alert">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" x2="12" y1="8" y2="12" />
                        <line x1="12" x2="12.01" y1="16" y2="16" />
                    </svg> Error</h2>
                <p id="error-dialog-description"></p>
            </header>
            <footer>
                <button class="btn-primary" onclick="document.getElementById('error-dialog').close()">Okay</button>
            </footer>
        </div>
    </dialog>
    <dialog id="message-dialog" class="dialog result-dialog" aria-labelledby="message-dialog-title"
        aria-describedby="message-dialog-description">
        <div class="result-body">
            <div class="result-icon" aria-hidden="true">
                <span class="result-ring"></span>
                <svg class="result-svg result-svg--success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                <svg class="result-svg result-svg--error" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            </div>
            <h2 id="message-dialog-title" class="result-title"></h2>
            <p id="message-dialog-description" class="result-desc avo-text"></p>
            <button class="btn-primary result-btn" onclick="document.getElementById('message-dialog').close()">Okay</button>
        </div>
    </dialog>
    <?php if (isset($_SESSION['success_ticket'])):
        $st = $_SESSION['success_ticket'];
        unset($_SESSION['success_ticket']);
        // Superseded by this stub — consume the generic success flag so the plain
        // dialog below doesn't also fire.
        unset($_SESSION['success']);
        $stubPaid = !empty($st['paid']);
        // Note text is localized at RENDER time (follows the current UI language),
        // not baked at purchase time, so it always matches the rest of the stub.
        if ($stubPaid) {
            $stubNote = $is_de
                ? 'Ihre Tickets wurden erfasst und bezahlt. Sie erhalten sie in Kürze per E-Mail.'
                : 'Your tickets are confirmed and paid. You will receive them by email shortly.';
        } else {
            $stubNote = $is_de
                ? 'Sie erhalten Ihre Tickets in Kürze per E-Mail. Bitte bezahlen Sie am Veranstaltungstag an unserer Ticketkasse.'
                : 'You will receive your tickets by email shortly. Please pay at our box office on the day of the event.';
        }
        $d = DateTime::createFromFormat('Y-m-d', (string)($st['date'] ?? ''));
        $stubDate = $d ? $d->format('d.m.Y') : (string)($st['date'] ?? '');
        $stubDateLine = $stubDate . (!empty($st['time']) ? ' · ' . $st['time'] : '');
    ?>
        <dialog id="booking-success" class="stub-dialog">
            <div class="bx-ticket">
                <div class="bx-top">
                    <div class="bx-eyebrow">// TICKET</div>
                    <div class="bx-event"><?php echo htmlspecialchars($st['event_name'] ?: 'Ticket'); ?></div>
                </div>
                <div class="bx-perf"><span class="bx-notch l"></span><span class="bx-notch r"></span></div>
                <div class="bx-body">
                    <div class="bx-rows">
                        <?php if ($stubDateLine): ?>
                        <div class="bx-row"><span class="bx-k"><?php echo $is_de ? 'Datum' : 'Date'; ?></span><span class="bx-v"><?php echo htmlspecialchars($stubDateLine); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($st['seats'])): ?>
                        <div class="bx-row"><span class="bx-k"><?php echo $is_de ? 'Plätze' : 'Seats'; ?></span><span class="bx-v"><span class="bx-seats"><?php foreach ($st['seats'] as $s): ?><span class="bx-seat">&#127903; <?php echo htmlspecialchars($s); ?></span><?php endforeach; ?></span></span></div>
                        <?php else: ?>
                        <div class="bx-row"><span class="bx-k">Tickets</span><span class="bx-v"><?php echo (int)$st['tickets']; ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($st['location'])): ?>
                        <div class="bx-row"><span class="bx-k"><?php echo $is_de ? 'Ort' : 'Location'; ?></span><span class="bx-v"><?php echo htmlspecialchars($st['location']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($st['name'])): ?>
                        <div class="bx-row"><span class="bx-k">Name</span><span class="bx-v"><?php echo htmlspecialchars($st['name']); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="bx-result">
                        <div class="bx-badge"><svg viewBox="0 0 52 52" fill="none" stroke="var(--avo-success)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path class="bx-draw" d="M14 27l8 8 16-18"/></svg></div>
                        <div class="bx-title"><?php echo $stubPaid ? ($is_de ? 'Erfolgreich gekauft' : 'Successfully purchased') : ($is_de ? 'Erfolgreich gebucht' : 'Successfully booked'); ?></div>
                        <?php if ($stubNote): ?><p class="bx-note"><?php echo htmlspecialchars($stubNote); ?></p><?php endif; ?>
                    </div>
                    <button class="bx-btn" type="button" onclick="document.getElementById('booking-success').close()"><?php echo $is_de ? 'Fertig' : 'Done'; ?></button>
                </div>
            </div>
        </dialog>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.getElementById('booking-success').showModal();
            });
        </script>
    <?php endif; ?>
    <?php if (isset($_SESSION['error']) || isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const dialog = document.getElementById('message-dialog');
                const title = dialog.querySelector('#message-dialog-title');
                const desc = dialog.querySelector('#message-dialog-description');

                <?php if (isset($_SESSION['error'])): ?>
                    dialog.classList.add('is-error');
                    title.textContent = <?php echo json_encode($current_language === 'de' ? 'Fehler' : 'Error'); ?>;
                    desc.textContent = <?php echo json_encode($_SESSION['error']); ?>;
                    <?php unset($_SESSION['error']); ?>
                <?php elseif (isset($_SESSION['success'])): ?>
                    dialog.classList.add('is-success');
                    title.textContent = <?php echo json_encode($current_language === 'de' ? 'Geschafft!' : 'All set!'); ?>;
                    desc.textContent = <?php echo json_encode($_SESSION['success']); ?>;
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                dialog.showModal();
            });
        </script>
    <?php endif; ?>
    <style>
        .wizard-dot { flex: 1; height: 4px; border-radius: 999px; background: var(--avo-border); transition: background .2s ease; }
        .wizard-dot.active { background: var(--avo-primary); }
        .wizard-dot.done { background: color-mix(in oklab, var(--avo-primary) 55%, transparent); }
        input[aria-invalid="true"],
        select[aria-invalid="true"] {
            border-color: var(--avo-error) !important;
            outline-color: var(--avo-error);
        }
        .field-error { color: var(--avo-error); font-size: .8rem; margin: 0; }
        .field-error.hidden { display: none; }

        /* ---- result dialog (purchase success / error) ---- */
        .result-dialog .result-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 1rem;
            /* this element is basecoat's `.dialog>*` wrapper; our override replaces its padding */
            padding: 2rem 1.75rem 1.75rem;
        }
        .result-icon {
            position: relative;
            width: 76px;
            height: 76px;
            display: grid;
            place-items: center;
            border-radius: 999px;
        }
        .result-dialog.is-success .result-icon { background: color-mix(in oklab, var(--avo-primary) 16%, transparent); color: var(--avo-primary); }
        .result-dialog.is-error   .result-icon { background: color-mix(in oklab, var(--avo-error) 16%, transparent);   color: var(--avo-error); }
        .result-ring {
            position: absolute;
            inset: 0;
            border-radius: 999px;
            border: 2px solid currentColor;
            opacity: 0;
        }
        .result-svg { width: 38px; height: 38px; position: relative; z-index: 1; display: none; }
        .result-dialog.is-success .result-svg--success { display: block; }
        .result-dialog.is-error   .result-svg--error   { display: block; }
        .result-svg--success path { stroke-dasharray: 30; stroke-dashoffset: 30; }
        .result-title { font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--avo-text); }
        .result-desc { margin: 0; white-space: pre-line; max-width: 38ch; }
        /* basecoat absolutely-positions `.dialog>*>button` (its close-X) top-right.
           Our Okay button is a normal flow action — pin it back into the column. */
        .result-dialog .result-btn {
            position: static;
            opacity: 1;
            align-self: center;
            margin-top: .5rem;
            min-width: 150px;
        }

        @media (prefers-reduced-motion: no-preference) {
            .result-dialog[open] .result-icon { animation: resultPop .45s cubic-bezier(.2, .8, .3, 1.25) both; }
            .result-dialog[open] .result-ring { animation: resultRing 1s ease-out .2s both; }
            .result-dialog.is-success[open] .result-svg--success path { animation: checkDraw .45s ease-out .38s forwards; }
            .result-dialog.is-error[open] .result-body { animation: resultShake .42s ease .1s both; }
            .result-dialog[open] .result-title,
            .result-dialog[open] .result-desc,
            .result-dialog[open] .result-btn { animation: resultRise .4s ease both; }
            .result-dialog[open] .result-title { animation-delay: .12s; }
            .result-dialog[open] .result-desc { animation-delay: .2s; }
            .result-dialog[open] .result-btn { animation-delay: .28s; }
        }
        @keyframes resultPop { 0% { transform: scale(.5); opacity: 0; } 60% { transform: scale(1.06); } 100% { transform: scale(1); opacity: 1; } }
        @keyframes resultRing { 0% { transform: scale(.85); opacity: .55; } 100% { transform: scale(1.9); opacity: 0; } }
        @keyframes checkDraw { to { stroke-dashoffset: 0; } }
        @keyframes resultRise { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes resultShake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-7px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(3px); } }

        /* ---- booking success: ticket-stub modal (mirrors the cancel page) ---- */
        .stub-dialog { border: 0; background: transparent; padding: 0; margin: 0; max-width: 440px; width: calc(100% - 2rem); max-height: 92vh; overflow-y: auto; position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%); }
        .stub-dialog::backdrop { background: rgba(0,0,0,.6); backdrop-filter: blur(3px); }
        .stub-dialog[open] { animation: bxRise .5s cubic-bezier(.22,1,.36,1); }
        .bx-ticket { position: relative; background: var(--avo-surface); border: 1px solid var(--avo-border); border-radius: 20px; overflow: hidden; box-shadow: 0 24px 60px -30px rgba(0,0,0,.55); }
        .bx-top { position: relative; padding: 1.5rem 1.4rem 1.2rem; background: var(--avo-primary); background-image: linear-gradient(135deg, var(--avo-primary), color-mix(in oklab, var(--avo-primary) 60%, #000)); color: #fff; }
        .bx-eyebrow { font-family: var(--avo-font-mono, monospace); font-size: .7rem; font-weight: 700; letter-spacing: .22em; opacity: .85; }
        .bx-event { font-family: var(--avo-font-display); font-size: 1.4rem; font-weight: 800; line-height: 1.1; margin-top: .3rem; word-break: break-word; }
        .bx-perf { position: relative; height: 0; border-top: 2.5px dashed color-mix(in oklab, var(--avo-text-muted) 65%, transparent); }
        .bx-notch { position: absolute; top: -13px; width: 26px; height: 26px; border-radius: 50%; background: var(--avo-bg); }
        .bx-notch.l { left: -13px; } .bx-notch.r { right: -13px; }
        .bx-body { padding: 1.3rem 1.4rem 1.5rem; }
        .bx-rows { display: flex; flex-direction: column; }
        .bx-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid color-mix(in oklab, var(--avo-border) 55%, transparent); }
        .bx-row:last-child { border-bottom: 0; }
        .bx-k { font-size: .66rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--avo-text-muted); }
        .bx-v { font-weight: 700; color: var(--avo-text); text-align: right; word-break: break-word; }
        .bx-seats { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end; }
        .bx-seat { display: inline-flex; align-items: center; gap: .3rem; padding: .3rem .6rem; border-radius: 999px; font-family: var(--avo-font-display); font-weight: 800; font-size: .9rem; color: var(--avo-primary); background: color-mix(in oklab, var(--avo-primary) 14%, transparent); border: 1px solid color-mix(in oklab, var(--avo-primary) 40%, transparent); }
        .bx-result { display: flex; flex-direction: column; align-items: center; text-align: center; gap: .5rem; margin-top: 1.3rem; }
        .bx-badge { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: color-mix(in oklab, var(--avo-success) 18%, transparent); }
        .bx-badge svg { width: 42px; height: 42px; }
        .bx-badge .bx-draw { stroke-dasharray: 48; stroke-dashoffset: 48; }
        .stub-dialog[open] .bx-badge .bx-draw { animation: bxCheck .5s .2s ease forwards; }
        .bx-title { font-family: var(--avo-font-display); font-size: 1.3rem; font-weight: 800; color: var(--avo-success); }
        .bx-note { font-size: .85rem; line-height: 1.6; color: var(--avo-text-muted); margin: 0; }
        .bx-btn { width: 100%; min-height: 50px; border: 0; border-radius: 12px; font-weight: 800; font-size: 1rem; cursor: pointer; margin-top: 1.1rem; background: var(--avo-primary); color: #fff; transition: filter .2s ease; }
        .bx-btn:hover { filter: brightness(1.07); }
        @keyframes bxRise { from { opacity: 0; transform: translate(-50%, calc(-50% + 20px)) scale(.98); } to { opacity: 1; transform: translate(-50%, -50%); } }
        @keyframes bxCheck { to { stroke-dashoffset: 0; } }
        @media (prefers-reduced-motion: reduce) {
            .stub-dialog[open] { animation: none; }
            .stub-dialog[open] .bx-badge .bx-draw { animation: none; stroke-dashoffset: 0; }
        }
    </style>
    <dialog id="bookingModal" class="dialog w-full sm:max-w-[425px]" aria-labelledby="demo-dialog-edit-profile-title"
        onclick="if (event.target === this) this.close()">
        <div class="booking-shell">
          <aside class="booking-side">
            <div class="booking-side-head">
                <div>
                    <h2 id="demo-dialog-edit-profile-title" class="text-lg font-bold">
                        <?php echo $languages[$current_language]['buy_tickets']; ?>
                    </h2>
                    <p class="demo-dialog-edit-profile-description text-sm mt-1">
                        <i class="fa-solid fa-circle-question"></i>
                        <a href="./help/buy_ticket.php" class="avo-link" target="_blank">
                            <span><?php echo $languages[$current_language]['need_help']; ?></span>
                        </a>
                    </p>
                </div>
                <button type="button" aria-label="Close dialog" onclick="this.closest('dialog').close()" class="booking-close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <!-- Persistent context: which date/location is being booked (visible on every step) -->
            <div id="dialogContext" class="hidden grid gap-1 mb-4 p-3"
                 style="border:1px solid var(--avo-border);border-radius:12px;background-color:var(--avo-surface);">
                <div style="display:flex;align-items:center;gap:.5rem;font-weight:600;font-size:.9rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="var(--avo-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M8 2v4" /><path d="M16 2v4" /><rect width="18" height="18" x="3" y="4" rx="2" /><path d="M3 10h18" />
                    </svg>
                    <span id="dialogDate"></span>
                </div>
                <div id="dialogLocationRow" style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--avo-text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                    <span id="dialogLocation"></span>
                </div>
            </div>
            <ol class="step-list">
                <li class="step-item" data-stepitem="1"><span class="step-num">1</span><span class="step-name"><?php echo $languages[$current_language]['step1_title']; ?></span></li>
                <li class="step-item" data-stepitem="2"><span class="step-num">2</span><span class="step-name"><?php echo $languages[$current_language]['step2_title']; ?></span></li>
                <li class="step-item" data-stepitem="3"><span class="step-num">3</span><span class="step-name"><?php echo $languages[$current_language]['step3_title']; ?></span></li>
                <li class="step-item" data-stepitem="4"><span class="step-num">4</span><span class="step-name"><?php echo $languages[$current_language]['step4_title']; ?></span></li>
            </ol>
          </aside>
          <main class="booking-main">
          <section>
                <form class="form grid gap-4" id="bookingForm" action="buy.php" method="POST">
                    <?php echo csrfField(); ?>
                    <!-- Honeypot: hidden from real users; bots that fill it are rejected. -->
                    <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">
                        <label for="website">Website</label>
                        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="hidden" name="valid_date" id="validDate">
                    <input type="hidden" name="price" id="ticketPrice">
                    <input type="hidden" name="payment_intent_id" id="paymentIntentId">
                    <input type="hidden" name="payment_method" id="paymentMethodInput" value="">
                    <input type="hidden" name="hold_token" id="holdToken" value="">
                    <div id="seatsHidden"></div>
                    <?php
                    $L = $languages[$current_language];
                    $contactEmail = $shows['contact_email'] ?? '';
                    $contactDisplay = $contactEmail !== ''
                        ? '<a href="mailto:' . htmlspecialchars($contactEmail) . '" class="avo-link">' . htmlspecialchars($contactEmail) . '</a>'
                        : htmlspecialchars($L['the_organizer']);
                    $stornoContact = str_replace('{contact}', $contactDisplay, htmlspecialchars($L['storno_contact']));
                    ?>

                    <div class="wizard-body">
                    <!-- Mobile-only: step + date/location (sidebar chrome is collapsed to dots there) -->
                    <div id="mobileStepBar" class="booking-mobile-only" style="width:100%;max-width:1180px;margin:0 auto .75rem;display:none;flex-wrap:wrap;align-items:baseline;gap:.15rem .6rem;">
                        <span id="mobileStepLabel" style="font-size:1.05rem;font-weight:700;color:var(--avo-text);"></span>
                        <span id="mobileStepDate" style="font-size:.82rem;color:var(--avo-text-muted);"></span>
                    </div>
                    <!-- STEP 1 — personal details -->
                    <div class="wizard-step grid gap-4" data-step="1">
                        <div>
                            <div class="wizard-heading"><?php echo $L['step1_title']; ?></div>
                            <div class="wizard-sub"><?php echo $current_language === 'de' ? 'Wir brauchen diese Angaben für Ihre Tickets.' : 'We need these details for your tickets.'; ?></div>
                        </div>
                        <div class="grid gap-2">
                            <label for="first_name"><?php echo $L['first_name']; ?></label>
                            <input type="text" name="first_name" id="first_name" placeholder="Max" autocomplete="given-name" required autofocus aria-describedby="first_name_err">
                            <p id="first_name_err" class="field-error hidden" role="alert"></p>
                        </div>
                        <div class="grid gap-2">
                            <label for="last_name"><?php echo $L['last_name']; ?></label>
                            <input type="text" name="last_name" id="last_name" placeholder="Mustermann" autocomplete="family-name" required aria-describedby="last_name_err">
                            <p id="last_name_err" class="field-error hidden" role="alert"></p>
                        </div>
                        <div class="grid gap-2">
                            <label for="email"><?php echo $L['email']; ?></label>
                            <input type="email" name="email" id="email" placeholder="max@mustermann.de" autocomplete="email" spellcheck="false" required aria-describedby="email_err">
                            <p id="email_err" class="field-error hidden" role="alert"></p>
                        </div>
                    </div>

                    <!-- STEP 2 — tickets / seats -->
                    <div class="wizard-step hidden grid gap-4" data-step="2">
                        <div id="gaTicketWrap" class="grid gap-4" style="max-width:22rem;margin:0 auto;text-align:center;">
                            <div class="text-lg font-semibold" style="color:var(--avo-text);"><?php echo $L['number_of_tickets']; ?></div>
                            <div class="flex items-center justify-center gap-4">
                                <button type="button" id="gaMinus" class="btn-secondary" style="padding:.4rem 1.1rem;line-height:1;font-size:1.6rem;" aria-label="−">−</button>
                                <span id="gaCountVal" class="font-bold" style="min-width:2.5rem;text-align:center;font-size:2rem;color:var(--avo-text);">1</span>
                                <button type="button" id="gaPlus" class="btn-secondary" style="padding:.4rem 1.1rem;line-height:1;font-size:1.6rem;" aria-label="+">+</button>
                            </div>
                            <!-- Source-of-truth select kept (hidden) so existing validation / summary /
                                 availability JS keeps working; the stepper above drives it. -->
                            <select name="tickets" id="tickets" required class="hidden" aria-hidden="true" tabindex="-1">
                                <?php for ($i = 1; $i <= 10; $i++) { ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i . ' Ticket' . (($i > 1 ? 's' : '')); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <!-- Extra-ticket name fields render here (moved in from below so they sit
                                 with the amount, matching the seated flow). -->
                            <div id="nameFieldsContainer" class="grid gap-3" style="text-align:left;"></div>
                        </div>

                        <!-- Reserved-seating picker (shown only for seated dates) -->
                        <div id="seatPickerWrap" class="hidden grid gap-2">

                            <!-- PHASE 1 — how many seats (before touching the map) -->
                            <div id="seatCountPhase" class="grid gap-4" style="max-width:22rem;margin:0 auto;text-align:center;">
                                <div class="text-lg font-semibold" style="color:var(--avo-text);"><?php echo $current_language === 'de' ? 'Wie viele Plätze möchten Sie?' : 'How many seats do you want?'; ?></div>
                                <div class="flex items-center justify-center gap-4">
                                    <button type="button" id="seatWantMinus" class="btn-secondary" style="padding:.4rem 1.1rem;line-height:1;font-size:1.6rem;" aria-label="−">−</button>
                                    <span id="seatWantVal" class="font-bold" style="min-width:2.5rem;text-align:center;font-size:2rem;color:var(--avo-text);">1</span>
                                    <button type="button" id="seatWantPlus" class="btn-secondary" style="padding:.4rem 1.1rem;line-height:1;font-size:1.6rem;" aria-label="+">+</button>
                                </div>
                                <!-- Names for the extra tickets, filled here (not hidden below the map) -->
                                <div id="seatNameFields" class="grid gap-3" style="text-align:left;"></div>
                                <button type="button" id="seatCountNext" class="btn" style="width:100%;min-height:52px;font-size:1.05rem;"><?php echo $current_language === 'de' ? 'Plätze auf Karte auswählen' : 'Choose seats on the map'; ?></button>
                                <button type="button" id="seatAutoPick" class="btn-secondary" style="width:100%;"><?php echo $current_language === 'de' ? 'Beste Plätze automatisch wählen' : 'Pick best seats automatically'; ?></button>
                            </div>

                            <!-- PHASE 2 — the map itself -->
                            <div id="seatMapPhase" class="hidden grid gap-2">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <div class="text-base font-medium" style="color:var(--avo-text);"><span id="seatWantEcho">1</span> <?php echo $current_language === 'de' ? 'Plätze – tippen Sie in den Saal' : 'seats – tap in the hall'; ?></div>
                                    <button type="button" id="seatCountBack" class="btn-secondary" style="padding:.3rem .9rem;line-height:1;"><?php echo $current_language === 'de' ? 'Anzahl ändern' : 'Change amount'; ?></button>
                                </div>
                                <div id="seatOrphanHint" class="hidden text-sm p-2" style="color:#b45309;background:color-mix(in oklab,#f59e0b 15%,transparent);border-radius:8px;"></div>
                                <div id="seatLegend" class="flex flex-wrap gap-x-4 gap-y-1 text-xs" style="color:var(--avo-text-muted);"></div>
                                <div class="flex items-center gap-2 text-sm" style="color:var(--avo-text-muted);">
                                    <button type="button" id="seatZoomOut" class="btn-secondary" style="padding:.3rem .8rem;line-height:1;font-size:1.2rem;" aria-label="Zoom out">−</button>
                                    <button type="button" id="seatZoomIn" class="btn-secondary" style="padding:.3rem .8rem;line-height:1;font-size:1.2rem;" aria-label="Zoom in">+</button>
                                    <button type="button" id="seatZoomFit" class="btn-secondary" style="padding:.3rem .9rem;line-height:1;" aria-label="Fit"><?php echo $current_language === 'de' ? 'Ganzer Saal' : 'Whole hall'; ?></button>
                                </div>
                                <div id="seatMapScroll" style="overflow:auto;max-height:min(68vh,860px);border:1px solid var(--avo-border);border-radius:10px;background:var(--avo-surface);touch-action:none;">
                                    <svg id="seatSvg" xmlns="http://www.w3.org/2000/svg" style="display:block;width:100%;height:auto;min-height:200px;"></svg>
                                </div>
                                <div id="seatSelInfo" class="text-base font-semibold" style="color:var(--avo-text);"></div>
                                <div id="seatHoldTimer" class="text-xs hidden" style="color:var(--avo-primary);font-weight:600;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3 — payment method -->
                    <div class="wizard-step hidden grid gap-3" data-step="3" role="group" aria-label="<?php echo htmlspecialchars($L['choose_payment']); ?>">
                        <div class="wizard-heading"><?php echo $L['choose_payment']; ?></div>
                        <div id="paymentMethodSelection" class="grid gap-3">
                            <button type="button" id="cashButton" class="btn-secondary payment-method-btn"
                                onclick="pickMethod('bar')" data-method="cash"
                                aria-label="<?php echo $L['cash_payment']; ?>"
                                style="display:flex;align-items:center;justify-content:center;gap:0.5rem;min-height:56px;padding:0 1rem;width:100%;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-coins">
                                    <circle cx="8" cy="8" r="6" />
                                    <path d="M18.09 10.37A6 6 0 1 1 10.34 18" />
                                    <path d="M7 6h1v4" />
                                    <path d="m16.71 13.88.7.71-2.82 2.82" />
                                </svg>
                                <span><?php echo $L['cash_payment']; ?></span>
                            </button>
                            <button type="button" id="stripeButton" class="btn-primary payment-method-btn"
                                onclick="pickMethod('stripe')" data-method="online"
                                aria-label="<?php echo $L['online_payment']; ?>"
                                style="display:flex;align-items:center;justify-content:center;gap:0.5rem;min-height:56px;padding:0 1rem;width:100%;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-credit-card">
                                    <rect width="20" height="14" x="2" y="5" rx="2" />
                                    <line x1="2" x2="22" y1="10" y2="10" />
                                </svg>
                                <span><?php echo $L['online_payment']; ?> / Card &amp; More</span>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 4 — confirm & pay -->
                    <div class="wizard-step hidden grid gap-4" data-step="4">
                        <div class="grid gap-2" style="border:1px solid var(--avo-border);border-radius:12px;padding:12px 14px;background-color:var(--avo-surface);">
                            <div class="font-semibold text-sm"><?php echo $L['summary']; ?></div>
                            <div id="orderSummary" class="text-sm grid gap-1" style="color:var(--avo-text-muted);"></div>
                        </div>

                        <div class="grid gap-3" style="border:1px solid var(--avo-border);border-radius:12px;padding:14px 16px;background-color:var(--avo-surface);">
                            <div style="font-weight:700;color:var(--avo-primary);display:flex;align-items:center;gap:.45rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                    <path d="M12 9v4" /><path d="M12 17h.01" />
                                </svg>
                                <span id="bookingNoticeTitle"><?php echo htmlspecialchars($L['booking_notice_title']); ?></span>
                            </div>
                            <p id="bindingNotice" style="margin:0;font-size:.9rem;color:var(--avo-text);"><?php echo htmlspecialchars($L['binding_notice']); ?></p>
                            <ul id="stornoList" style="margin:0;padding-left:1.1rem;list-style:disc;font-size:.85rem;color:var(--avo-text-muted);"></ul>
                            <p style="margin:0;font-size:.85rem;color:var(--avo-text-muted);"><?php echo $stornoContact; ?></p>
                            <label class="flex items-start gap-2" style="cursor:pointer;font-size:.9rem;color:var(--avo-text);">
                                <input type="checkbox" name="consent" id="consentCheckbox" value="1" required style="margin-top:.2rem;flex:none;">
                                <span id="consentLabel"><?php echo htmlspecialchars($L['consent_label']); ?></span>
                            </label>
                        </div>

                        <button type="submit" id="cashConfirmButton" class="btn-primary w-full hidden"
                            aria-label="<?php echo $L['book_tickets']; ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-badge-check">
                                <path
                                    d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
                                <path d="m9 12 2 2 4-4" />
                            </svg> <?php echo $L['book_tickets']; ?>
                        </button>
                        <div id="stripePaymentContainer" class="hidden">
                            <div id="stripe-payment-element"></div>
                            <div id="stripe-payment-error" class="hidden mt-2 text-sm" style="color:var(--avo-error);" role="alert" aria-live="polite"></div>
                            <button type="button" id="stripeConfirmButton" class="btn-primary w-full mt-3 hidden"
                                onclick="confirmStripePayment()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-lock">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg>
                                <?php echo $L['pay_now']; ?>
                            </button>
                        </div>
                    </div>

                    </div><!-- /.wizard-body -->

                    <!-- Wizard navigation -->
                    <div class="wizard-nav"><div class="booking-inner">
                        <button type="button" id="wizardBack" class="btn-secondary flex-1 hidden" onclick="wizardGoBack()">
                            <?php echo $L['back']; ?>
                        </button>
                        <button type="button" id="wizardNext" class="btn-primary flex-1" onclick="wizardGoNext()">
                            <?php echo $L['next']; ?>
                        </button>
                    </div></div>
                </form>
            </section>
          </main>
        </div>
    </dialog>
    <?php if ($shows === null): ?>
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="backdrop-blur-md rounded-lg p-8 max-w-md w-full text-center"
                style="background-color: color-mix(in oklab, var(--avo-error) 18%, transparent); border: 1px solid color-mix(in oklab, var(--avo-error) 45%, transparent);">
                <svg class="w-16 h-16 mx-auto mb-4" style="color: var(--avo-error);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h2 class="text-2xl font-bold mb-4"><?php echo $languages[$current_language]['error_loading_shows']; ?></h2>
                <p class="avo-muted"><?php echo $languages[$current_language]['try_again']; ?></p>
                <button onclick="location.reload()" class="btn-destructive mt-6">
                    <?php echo $languages[$current_language]['try_again_button']; ?>
                </button>
            </div>
        </div>
    <?php else: ?>
        <?php if ($shows["store_lock"] === true): ?>
            <div class="min-h-screen flex items-center justify-center p-4">
                <div class="backdrop-blur-md rounded-lg p-8 max-w-md w-full text-center"
                    style="background-color: color-mix(in oklab, var(--avo-error) 18%, transparent); border: 1px solid color-mix(in oklab, var(--avo-error) 45%, transparent);">
                    <div style="display: flex; flex-direction: column; align-items: center; text-align: center; "><svg
                            xmlns="http://www.w3.org/2000/svg" width="85" height="85" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-lock-icon lucide-lock">
                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg></div>
                    <h2 class="text-2xl font-bold mb-4"><br><?php echo $languages[$current_language]['store_lock_title']; ?>
                    </h2>
                    <p class="avo-muted">
                        <?php echo str_replace('{name}', htmlspecialchars($shows['orga_name']), $languages[$current_language]['store_lock_message']); ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="animate-fade-in">
                <div class="relative w-full min-h-[40vh] max-h-[50vh] flex items-center justify-center mb-8 px-4">
                    <div id="bannerBackground" class="absolute inset-0 bg-cover bg-center transition-transform duration-700">
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {

                            const timestamp = new Date().getTime();
                            document.getElementById('bannerBackground').style.backgroundImage = `url('<?php echo PUBLIC_API_BASE; ?>/api/image/get/banner.png?t=${timestamp}')`;
                        });
                    </script>
                    <div class="absolute inset-0 bg-black/60"></div>
                    <div class="relative z-10 text-center max-w-2xl w-full py-6 md:py-10 px-3 rounded-xl">
                        <div class="avo-kicker mb-2 animate-fade-in-up">// tickets</div>
                        <h1 class="orga-name text-3xl md:text-5xl font-bold mb-2 animate-fade-in-up">
                            <?php echo htmlspecialchars($shows['orga_name'] ?? ''); ?>
                        </h1>
                        <h2 class="show-name text-xl md:text-3xl font-semibold mb-1 animate-fade-in-up">
                            <span class="px-2 py-1 rounded-md">
                                <?php echo htmlspecialchars($shows['title'] ?? ''); ?>
                            </span>
                        </h2>
                        <?php if (!empty(trim($shows['subtitle'] ?? ''))): ?>
                            <h3 class="text-lg md:text-xl mt-2 animate-fade-in-up opacity-90">
                                <?php echo htmlspecialchars($shows['subtitle'] ?? ''); ?>
                            </h3>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="container mx-auto max-w-6xl px-4 py-8">
                    <?php
                    // Group the available dates by their assigned location so the
                    // shop can render one section per venue. Dates without a
                    // location fall into the '' bucket, rendered last.
                    $locations = (isset($shows['locations']) && is_array($shows['locations'])) ? $shows['locations'] : [];
                    $grouped = [];
                    foreach ($shows['dates'] as $id => $show) {
                        $locId = $show['location'] ?? '';
                        if (!isset($locations[$locId])) {
                            $locId = '';
                        }
                        $grouped[$locId][$id] = $show;
                    }
                    // Order: known locations first (in their defined order), then ungrouped.
                    $groupOrder = [];
                    foreach (array_keys($locations) as $lid) {
                        if (!empty($grouped[$lid])) {
                            $groupOrder[] = $lid;
                        }
                    }
                    if (!empty($grouped[''])) {
                        $groupOrder[] = '';
                    }
                    $hasNamedLocations = false;
                    foreach ($groupOrder as $lid) {
                        if ($lid !== '') { $hasNamedLocations = true; break; }
                    }
                    foreach ($groupOrder as $locId):
                        $group = $grouped[$locId];
                        $loc = $locations[$locId] ?? null;
                        ?>
                        <?php if ($locId !== '' && $loc): ?>
                            <div class="mb-6 mt-4 animate-fade-in-up">
                                <div class="avo-kicker mb-2">// location</div>
                                <h2 class="text-2xl md:text-4xl font-bold">
                                    <?php echo htmlspecialchars($loc['name'] ?? ''); ?>
                                </h2>
                                <?php if (!empty(trim($loc['address'] ?? ''))): ?>
                                    <p class="opacity-80 mt-1" style="display:inline-flex;align-items:center;gap:0.4rem;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-map-pin-icon lucide-map-pin">
                                            <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
                                            <circle cx="12" cy="10" r="3" />
                                        </svg>
                                        <?php echo htmlspecialchars($loc['address']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($hasNamedLocations): ?>
                            <div class="mb-6 mt-4 animate-fade-in-up">
                                <div class="avo-kicker mb-2">// more</div>
                                <h2 class="text-2xl md:text-4xl font-bold">
                                    <?php echo $languages[$current_language]['other_dates'] ?? 'Other dates'; ?>
                                </h2>
                            </div>
                        <?php endif; ?>
                    <!-- auto-fit: empty tracks collapse, so 1-2 events stretch to fill
                         the row instead of leaving an empty column on the right. -->
                    <div class="mb-12" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:2rem;">
                        <?php foreach ($group as $id => $show): ?>
                            <div class="card show-hover transform transition-transform duration-300 hover:scale-105">
                                <div class="p-6">
                                    <header class="mb-8">
                                        <div class="flex justify-between items-center mb-6">
                                            <span class="font-bold text-3xl"
                                                style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    class="lucide lucide-calendar-days-icon lucide-calendar-days">
                                                    <path d="M8 2v4" />
                                                    <path d="M16 2v4" />
                                                    <rect width="18" height="18" x="3" y="4" rx="2" />
                                                    <path d="M3 10h18" />
                                                    <path d="M8 14h.01" />
                                                    <path d="M12 14h.01" />
                                                    <path d="M16 14h.01" />
                                                    <path d="M8 18h.01" />
                                                    <path d="M12 18h.01" />
                                                    <path d="M16 18h.01" />
                                                </svg>
                                                <?php $date = new DateTime($show['date']);
                                                echo $date->format('d.m.Y'); ?>
                                            </span>
                                            <?php if ($show['tickets_available'] <= 20 && $show['tickets_available'] > 0): ?>
                                                <span class="font-bold badge-primary animate-pulse">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        class="lucide lucide-triangle-alert-icon lucide-triangle-alert">
                                                        <path
                                                            d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" />
                                                        <path d="M12 9v4" />
                                                        <path d="M12 17h.01" />
                                                    </svg>
                                                    <?php echo str_replace('{count}', $show['tickets_available'], $languages[$current_language]['tickets_left']); ?>
                                                </span>
                                            <?php elseif ($show['tickets_available'] == 0): ?>
                                                <span class="badge-destructive font-bold animate-pulse"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round" class="lucide lucide-ticket-x-icon lucide-ticket-x">
                                                        <path
                                                            d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                                                        <path d="m9.5 14.5 5-5" />
                                                        <path d="m9.5 9.5 5 5" />
                                                    </svg> <?php echo $languages[$current_language]['sold_out']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </header>
                                    <section>
                                        <div class="grid gap-3">
                                            <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="lucide lucide-euro-icon lucide-euro">
                                                    <path d="M4 10h12" />
                                                    <path d="M4 14h9" />
                                                    <path
                                                        d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2" />
                                                </svg>
                                                <span><?php echo htmlspecialchars($show['price']); ?></span>
                                            </span>
                                            <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="lucide lucide-clock2-icon lucide-clock-2">
                                                    <path d="M12 6v6l4-2" />
                                                    <circle cx="12" cy="12" r="10" />
                                                </svg>
                                                <?php echo htmlspecialchars($show['time']); ?>
                                            </span>
                                            <div class="relative pt-1 mb-4">
                                                <div class="flex mb-2 items-center justify-between">
                                                    <div class="text-right">
                                                        <span class="text-xs font-semibold inline-block bar-text">
                                                            <?php echo str_replace(['{available}', '{total}'], [htmlspecialchars($show['tickets_available']), htmlspecialchars($show['tickets'])], $languages[$current_language]['tickets_available']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="overflow-hidden h-2 text-xs flex rounded bar-dark">
                                                    <?php
                                                    $occupiedtickets = $show['tickets'] - $show['tickets_available'];
                                                    $percentage = ($occupiedtickets / $show['tickets']) * 100;
                                                    echo "<div style='width: {$percentage}%' class='shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bar'></div>";
                                                    ?>
                                                </div>
                                            </div>
                                            <?php if ($show['tickets_available'] > 0): ?>
                                                <button
                                                    <?php
                                                $dDisp = (new DateTime($show['date']))->format('d.m.Y');
                                                $locName = $loc['name'] ?? '';
                                                $jsDisp = htmlspecialchars(addslashes($dDisp), ENT_QUOTES);
                                                $jsLoc  = htmlspecialchars(addslashes($locName), ENT_QUOTES);
                                                ?>
                                                onclick="showBookingForm('<?php echo $id; ?>', '<?php echo $show['date']; ?>', '<?php echo $show['price']; ?>', '<?php echo $show['tickets_available']; ?>', '<?php echo $jsDisp; ?>', '<?php echo $jsLoc; ?>', <?php echo !empty($show['seating']) ? '1' : '0'; ?>)"
                                                    class="btn-primary"
                                                    aria-label="<?php echo $languages[$current_language]['buy_tickets']; ?> - <?php $date = new DateTime($show['date']); echo $date->format('d.m.Y'); ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round" class="lucide lucide-tickets-icon lucide-tickets" aria-hidden="true">
                                                        <path d="m3.173 8.18 11-5a2 2 0 0 1 2.647.993L18.56 8" />
                                                        <path d="M6 10V8" />
                                                        <path d="M6 14v1" />
                                                        <path d="M6 19v2" />
                                                        <rect x="2" y="8" width="20" height="13" rx="2" />
                                                    </svg>
                                                    <?php echo $languages[$current_language]['buy_tickets']; ?>
                                                </button>
                                            <?php elseif ($show['tickets_available'] == 0): ?>
                                                <button disabled class="btn-destructive cursor-not-allowed"
                                                    aria-label="<?php echo $languages[$current_language]['sold_out']; ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round" class="lucide lucide-ticket-x-icon lucide-ticket-x">
                                                        <path
                                                            d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                                                        <path d="m9.5 14.5 5-5" />
                                                        <path d="m9.5 9.5 5 5" />
                                                    </svg> <?php echo $languages[$current_language]['sold_out']; ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </section>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
                let lastFocusedElement = null;

                document.addEventListener('DOMContentLoaded', initPaymentMethods);

                function closeModal() {
                    const modal = document.getElementById('bookingModal');
                    modal.close();
                    modal.classList.remove('modal-exit');
                    document.body.style.overflow = 'auto';
                    releaseSeatHold();
                    resetWizard();
                    if (lastFocusedElement) lastFocusedElement.focus();
                }
                // Runs for every close path (X button, Esc, backdrop) — not just closeModal().
                document.getElementById('bookingModal').addEventListener('close', () => {
                    stopSeatPoll();
                    releaseSeatHold();
                    document.body.style.overflow = 'auto';
                });

                // ---- Multi-step booking wizard --------------------------------
                let wizardStep = 1;
                const WIZARD_TITLES = {
                    1: '<?php echo addslashes($L['step1_title']); ?>',
                    2: '<?php echo addslashes($L['step2_title']); ?>',
                    3: '<?php echo addslashes($L['step3_title']); ?>',
                    4: '<?php echo addslashes($L['step4_title']); ?>'
                };
                const STEP_OF_TPL = '<?php echo addslashes($L['step_of']); ?>';

                function resetWizard() {
                    document.getElementById('paymentMethodInput').value = '';
                    document.getElementById('paymentIntentId').value = '';
                    document.getElementById('cashConfirmButton').classList.add('hidden');
                    document.getElementById('stripePaymentContainer').classList.add('hidden');
                    document.getElementById('stripeConfirmButton').classList.add('hidden');
                    document.getElementById('stripe-payment-element').innerHTML = '';
                    const consent = document.getElementById('consentCheckbox');
                    if (consent) consent.checked = false;
                    window.stripeElements = null;
                    window.stripeInstance = null;
                    goToStep(1);
                }

                function goToStep(n) {
                    wizardStep = n;
                    document.querySelectorAll('.wizard-step').forEach(el => {
                        el.classList.toggle('hidden', parseInt(el.dataset.step, 10) !== n);
                    });
                    let stepTitle = '';
                    document.querySelectorAll('.step-item').forEach(d => {
                        const i = parseInt(d.dataset.stepitem, 10);
                        d.classList.toggle('active', i === n);
                        d.classList.toggle('done', i < n);
                        if (i === n) { const nm = d.querySelector('.step-name'); stepTitle = nm ? nm.textContent.trim() : ''; }
                    });
                    const mLabel = document.getElementById('mobileStepLabel');
                    if (mLabel) mLabel.textContent = '<?php echo $current_language === "de" ? "Schritt" : "Step"; ?> ' + n + (stepTitle ? ' · ' + stepTitle : '');
                    document.getElementById('wizardBack').classList.toggle('hidden', n === 1);
                    // Next is hidden on step 3 (advance by picking a method) and step 4 (pay/book live there).
                    document.getElementById('wizardNext').classList.toggle('hidden', n === 3 || n === 4);
                    const stepEl = document.querySelector('.wizard-step[data-step="' + n + '"]');
                    const firstInput = stepEl && stepEl.querySelector('input:not([type=hidden]), select');
                    if (firstInput && n !== 3) setTimeout(() => { try { firstInput.focus(); } catch (e) {} }, 60);
                    // Step 2 is two-phase: count first, map second. Keep the map open
                    // if seats are already picked (e.g. returning from step 3).
                    if (n === 2 && window.isSeated) {
                        if (selectedSeats.length) showSeatMapPhase(); else showSeatCountPhase();
                        startSeatPoll();
                    } else {
                        stopSeatPoll();
                    }
                }

                // NOTE: do not name these wizardNext/wizardBack — those ids exist on
                // the buttons inside the <form>, and an inline onclick resolves that
                // name to the form's control (the button element), shadowing the
                // function and making the click a no-op. Distinct names avoid the clash.
                async function wizardGoNext() {
                    if (wizardStep === 1) {
                        if (!validateStep1()) return;
                        goToStep(2);
                    } else if (wizardStep === 2) {
                        if (!validateStep2()) return;
                        // Seats are soft-held on selection; make sure the hold matches
                        // the final selection before moving on.
                        if (window.isSeated) {
                            clearTimeout(syncTimer);
                            const nextBtn = document.getElementById('wizardNext');
                            nextBtn.disabled = true;
                            await syncHold();
                            nextBtn.disabled = false;
                            if (!holdActive) return;   // a seat was taken — stay & re-pick
                        }
                        goToStep(3);
                        maybeAutoMethod();
                    }
                }

                async function wizardGoBack() {
                    if (wizardStep === 4) {
                        // Leaving confirm — clear payment-specific state so re-picking is clean.
                        document.getElementById('cashConfirmButton').classList.add('hidden');
                        document.getElementById('stripePaymentContainer').classList.add('hidden');
                        document.getElementById('stripeConfirmButton').classList.add('hidden');
                        document.getElementById('stripe-payment-element').innerHTML = '';
                        document.getElementById('paymentMethodInput').value = '';
                        document.getElementById('paymentIntentId').value = '';
                        window.stripeElements = null;
                        window.stripeInstance = null;
                        goToStep(3);
                        return;
                    }
                    // Going back to the seat picker: drop the hold FIRST (await!), then
                    // reload availability — otherwise the reload sees the buyer's own
                    // seats still held and renders them as taken/unclickable.
                    if (wizardStep === 3 && window.isSeated) {
                        const keep = selectedSeats.map(s => s.id);
                        await releaseSeatHold();
                        await loadSeatAvailability(document.getElementById('validDate').value);
                        reselectSeats(keep);
                    }
                    if (wizardStep > 1) goToStep(wizardStep - 1);
                }

                // Re-apply a previous selection after an availability reload so the
                // buyer keeps their seats when they step back to edit them.
                function reselectSeats(ids) {
                    (ids || []).forEach(id => {
                        const g = document.querySelector('.seat-node[data-seat="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
                        if (g && g.getAttribute('data-sold') !== '1') toggleSeat(g);
                    });
                }

                // The notice differs by payment method: cash is a reservation paid on
                // site (Buchung), card is paid now (Kauf). Title, intro, storno line
                // and consent wording all switch accordingly.
                const NOTICE = {
                    bar: {
                        title:   <?php echo json_encode($L['booking_notice_title']); ?>,
                        binding: <?php echo json_encode($L['binding_notice']); ?>,
                        consent: <?php echo json_encode($L['consent_label']); ?>,
                        storno:  <?php echo json_encode($L['storno_cash']); ?>
                    },
                    stripe: {
                        title:   <?php echo json_encode($L['booking_notice_title_card']); ?>,
                        binding: <?php echo json_encode($L['binding_notice_card']); ?>,
                        consent: <?php echo json_encode($L['consent_label_card']); ?>,
                        storno:  <?php echo json_encode($L['storno_card']); ?>
                    }
                };

                function applyStornoNotice(method) {
                    const n = NOTICE[method === 'bar' ? 'bar' : 'stripe'];
                    const title = document.getElementById('bookingNoticeTitle');
                    const binding = document.getElementById('bindingNotice');
                    const consent = document.getElementById('consentLabel');
                    const list = document.getElementById('stornoList');
                    if (title) title.textContent = n.title;
                    if (binding) binding.textContent = n.binding;
                    if (consent) consent.textContent = n.consent;
                    if (list) {
                        list.replaceChildren();
                        const li = document.createElement('li');
                        li.textContent = n.storno;
                        list.appendChild(li);
                    }
                }

                async function pickMethod(method) {
                    document.getElementById('paymentMethodInput').value = method; // 'bar' | 'stripe'
                    buildSummary(method);
                    applyStornoNotice(method);
                    goToStep(4);

                    const cashBtn = document.getElementById('cashConfirmButton');
                    const stripeBox = document.getElementById('stripePaymentContainer');
                    if (method === 'bar') {
                        stripeBox.classList.add('hidden');
                        cashBtn.classList.remove('hidden');
                    } else {
                        cashBtn.classList.add('hidden');
                        stripeBox.classList.remove('hidden');
                        const price = parseFloat(document.getElementById('ticketPrice').value);
                        const tickets = parseInt(document.querySelector('select[name="tickets"]').value);
                        await createStripeElements(price, tickets);
                    }
                }

                // Skip the choice screen if only one method is offered.
                function maybeAutoMethod() {
                    const cash = document.getElementById('cashButton');
                    const card = document.getElementById('stripeButton');
                    const cashVisible = cash && cash.style.display !== 'none';
                    const cardVisible = card && card.style.display !== 'none';
                    if (cashVisible && !cardVisible) pickMethod('bar');
                    else if (cardVisible && !cashVisible) pickMethod('stripe');
                }

                function buildSummary(method) {
                    const fn = document.querySelector('input[name="first_name"]').value.trim();
                    const ln = document.querySelector('input[name="last_name"]').value.trim();
                    const email = document.querySelector('input[name="email"]').value.trim();
                    const tickets = window.isSeated
                        ? selectedSeats.length
                        : (parseInt(document.querySelector('select[name="tickets"]').value, 10) || 1);
                    const price = parseFloat(document.getElementById('ticketPrice').value) || 0;
                    const total = window.isSeated ? seatTotal().toFixed(2) : (price * tickets).toFixed(2);
                    const methodLabel = method === 'bar'
                        ? '<?php echo addslashes($L['cash_payment']); ?>'
                        : '<?php echo addslashes($L['online_payment']); ?>';
                    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
                    const rows = [
                        ['<?php echo addslashes($L['first_name']); ?> / <?php echo addslashes($L['last_name']); ?>', (fn + ' ' + ln).trim()],
                        ['<?php echo addslashes($L['number_of_tickets']); ?>', tickets],
                        ['<?php echo addslashes($L['payment_method']); ?>', methodLabel],
                        ['<?php echo addslashes($L['email']); ?>', email],
                        ['<?php echo addslashes($L['date_label']); ?>', selectedDateDisplay]
                    ];
                    if (selectedLocation) {
                        rows.push(['<?php echo addslashes($L['location_label']); ?>', selectedLocation]);
                    }
                    if (window.isSeated && selectedSeats.length) {
                        rows.push(['<?php echo addslashes($current_language === "de" ? "Sitzplätze" : "Seats"); ?>', selectedSeats.map(s => s.label).join(', ')]);
                    }
                    rows.push(['<?php echo addslashes($L['total']); ?>', total + ' €']);
                    document.getElementById('orderSummary').innerHTML = rows.map(r =>
                        '<div class="flex justify-between gap-3"><span>' + esc(r[0]) + '</span><span style="color:var(--avo-text);font-weight:600;">' + esc(r[1]) + '</span></div>'
                    ).join('');
                }

                function setFieldError(input, message) {
                    input.setAttribute('aria-invalid', 'true');
                    const err = document.getElementById(input.id + '_err');
                    if (err) { err.textContent = message; err.classList.remove('hidden'); }
                }

                function clearFieldError(input) {
                    input.removeAttribute('aria-invalid');
                    const err = document.getElementById(input.id + '_err');
                    if (err) { err.textContent = ''; err.classList.add('hidden'); }
                }

                function validateStep1() {
                    const msg = wizardMessages();
                    const firstNameEl = document.querySelector('input[name="first_name"]');
                    const lastNameEl = document.querySelector('input[name="last_name"]');
                    const emailEl = document.querySelector('input[name="email"]');
                    [firstNameEl, lastNameEl, emailEl].forEach(clearFieldError);

                    let firstInvalid = null;
                    if (!firstNameEl.value.trim()) { setFieldError(firstNameEl, msg.fieldRequired); firstInvalid = firstInvalid || firstNameEl; }
                    if (!lastNameEl.value.trim()) { setFieldError(lastNameEl, msg.fieldRequired); firstInvalid = firstInvalid || lastNameEl; }
                    const email = emailEl.value.trim();
                    if (!email) {
                        setFieldError(emailEl, msg.fieldRequired); firstInvalid = firstInvalid || emailEl;
                    } else {
                        const re = /^[-a-z0-9!#$%&'*+/=?^_`{|}~]+(?:\.[-a-z0-9!#$%&'*+/=?^_`{|}~]+)*@(?:[a-z0-9](?:[-a-z0-9]*[a-z0-9])?\.)+[a-z0-9](?:[-a-z0-9]*[a-z0-9])?/;
                        if (!re.test(email)) { setFieldError(emailEl, msg.emailInvalid); firstInvalid = firstInvalid || emailEl; }
                    }

                    if (firstInvalid) { firstInvalid.focus(); return false; }
                    return true;
                }

                ['first_name', 'last_name', 'email'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.addEventListener('input', function () { clearFieldError(el); });
                });

                function validateStep2() {
                    const msg = wizardMessages();
                    const errors = [];
                    if (window.isSeated) {
                        if (selectedSeats.length < 1) errors.push('<?php echo addslashes($current_language === "de" ? "Bitte wählen Sie mindestens einen Sitzplatz." : "Please select at least one seat."); ?>');
                    } else {
                        const tickets = document.querySelector('select[name="tickets"]').value;
                        if (!tickets || tickets < 1) errors.push(msg.tickets);
                    }
                    for (const input of document.querySelectorAll('input[name="add_people[]"]')) {
                        if (!input.value.trim()) { errors.push(msg.missingAdditionalName); break; }
                    }
                    if (errors.length) { showErrorDialog(errors.join('\n')); return false; }
                    return true;
                }

                document.getElementById('cashConfirmButton').addEventListener('click', function (e) {
                    e.preventDefault();

                    if (!validateForm()) {
                        return;
                    }

                    this.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="status" aria-label="Loading" class="animate-spin">
            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
        </svg>
        Processing…
    `;
                    this.disabled = true;

                    document.getElementById('bookingForm').submit();
                });

                function wizardMessages() {
                    const currentLang = '<?php echo $current_language; ?>';
                    const messages = {
                        en: {
                            fieldRequired: 'Please fill in this field.',
                            firstName: 'Please enter your first name.',
                            lastName: 'Please enter your last name.',
                            emailRequired: 'Please enter your email.',
                            emailInvalid: 'Please enter a valid email address.',
                            tickets: 'Please select the number of tickets.',
                            missingAdditionalName: 'Please enter a name for all additional tickets.',
                            consent: '<?php echo addslashes($languages[$current_language]['consent_required']); ?>'
                        },
                        de: {
                            fieldRequired: 'Bitte ausfüllen.',
                            firstName: 'Bitte geben Sie Ihren Vornamen ein.',
                            lastName: 'Bitte geben Sie Ihren Nachnamen ein.',
                            emailRequired: 'Bitte geben Sie Ihre E-Mail-Adresse ein.',
                            emailInvalid: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
                            tickets: 'Bitte wählen Sie die Anzahl der Tickets aus.',
                            missingAdditionalName: 'Bitte geben Sie für alle zusätzlichen Tickets einen Namen an.',
                            consent: '<?php echo addslashes($languages[$current_language]['consent_required']); ?>'
                        }
                    };
                    return messages[currentLang] || messages.en;
                }

                function validateForm() {
                    const firstName = document.querySelector('input[name="first_name"]').value.trim();
                    const lastName = document.querySelector('input[name="last_name"]').value.trim();
                    const email = document.querySelector('input[name="email"]').value.trim();
                    const tickets = document.querySelector('select[name="tickets"]').value;
                    const additionalNameInputs = document.querySelectorAll('input[name="add_people[]"]');

                    const msg = wizardMessages();
                    let errors = [];

                    if (!firstName) errors.push(msg.firstName);
                    if (!lastName) errors.push(msg.lastName);
                    if (!email) {
                        errors.push(msg.emailRequired);
                    } else {
                        const emailRegex = /^[-a-z0-9!#$%&'*+/=?^_`{|}~]+(?:\.[-a-z0-9!#$%&'*+/=?^_`{|}~]+)*@(?:[a-z0-9](?:[-a-z0-9]*[a-z0-9])?\.)+[a-z0-9](?:[-a-z0-9]*[a-z0-9])?/;
                        if (!emailRegex.test(email)) {
                            errors.push(msg.emailInvalid);
                        }
                    }
                    if (!tickets || tickets < 1) errors.push(msg.tickets);

                    const consent = document.getElementById('consentCheckbox');
                    if (!consent || !consent.checked) errors.push(msg.consent);

                    for (const input of additionalNameInputs) {
                        if (!input.value.trim()) {
                            errors.push(msg.missingAdditionalName);
                            break;
                        }
                    }

                    if (errors.length > 0) {
                        showErrorDialog(errors.join('\n'));
                        return false;
                    }
                    return true;
                }

                function showError(message) {
                    const errorDiv = document.getElementById('modalError');
                    errorDiv.textContent = message;
                    errorDiv.classList.remove('hidden');


                    setTimeout(() => {
                        errorDiv.classList.add('hidden');
                    }, 5000);
                }

                async function createStripeElements(pricePerTicket, tickets) {
                    const container = document.getElementById('stripe-payment-element');
                    const errorDiv = document.getElementById('stripe-payment-error');
                    const confirmBtn = document.getElementById('stripeConfirmButton');

                    container.innerHTML = '<p style="color: var(--text-secondary); text-align: center;">Loading payment form…</p>';
                    errorDiv.classList.add('hidden');
                    confirmBtn.classList.add('hidden');

                    if (!stripePublishableKey) {
                        container.innerHTML = '';
                        errorDiv.textContent = 'Payment is not configured. Please contact the organizer.';
                        errorDiv.classList.remove('hidden');
                        return;
                    }

                    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                    const formData = new FormData();
                    formData.append('csrf_token', csrfToken);
                    formData.append('tickets', tickets);
                    if (window.isSeated) {
                        // Amount is computed server-side from the selected seats.
                        formData.append('seated', '1');
                        formData.append('date', document.getElementById('validDate').value);
                        selectedSeats.forEach(s => formData.append('seats[]', s.id));
                    } else {
                        formData.append('price', pricePerTicket);
                    }

                    let intentData;
                    try {
                        const resp = await fetch('stripe-intent.php', { method: 'POST', body: formData });
                        intentData = await resp.json();
                    } catch (err) {
                        container.innerHTML = '';
                        errorDiv.textContent = 'Network error. Please try again.';
                        errorDiv.classList.remove('hidden');
                        return;
                    }

                    if (intentData.error) {
                        container.innerHTML = '';
                        errorDiv.textContent = intentData.error;
                        errorDiv.classList.remove('hidden');
                        return;
                    }

                    document.getElementById('paymentIntentId').value = intentData.payment_intent_id;

                    const stripe = Stripe(stripePublishableKey);
                    window.stripeInstance = stripe;

                    const elements = stripe.elements({ clientSecret: intentData.client_secret });
                    window.stripeElements = elements;

                    const paymentElement = elements.create('payment');
                    container.innerHTML = '';
                    paymentElement.mount('#stripe-payment-element');
                    paymentElement.on('ready', () => {
                        confirmBtn.classList.remove('hidden');
                    });
                }

                async function confirmStripePayment() {
                    const confirmBtn = document.getElementById('stripeConfirmButton');
                    const errorDiv = document.getElementById('stripe-payment-error');

                    // Final guard: name/email/tickets/consent must all be valid before paying.
                    if (!validateForm()) return;
                    if (!window.stripeInstance || !window.stripeElements) return;

                    confirmBtn.disabled = true;
                    confirmBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="status" aria-label="Loading" class="animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg> Processing…`;

                    const { error } = await window.stripeInstance.confirmPayment({
                        elements: window.stripeElements,
                        redirect: 'if_required',
                    });

                    if (error) {
                        errorDiv.textContent = error.message;
                        errorDiv.classList.remove('hidden');
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-lock-icon lucide-lock"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Pay Now`;
                        return;
                    }

                    // Payment succeeded — submit form to buy.php for server-side verification
                    document.getElementById('bookingForm').submit();
                }

                // Enter inside the wizard should behave like the visible "Next" button,
                // not natively submit the whole form (which skipped step-by-step validation).
                document.getElementById('bookingForm').addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    if (e.target.tagName === 'TEXTAREA') return;
                    e.preventDefault();
                    const next = document.getElementById('wizardNext');
                    if (next && !next.classList.contains('hidden')) next.click();
                });


                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        closeModal();
                    }
                });


                document.getElementById('bookingModal').addEventListener('click', function (e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });

                function updateNameFields() {
                    const ticketsSelect = document.querySelector('select[name="tickets"]');
                    const nameFieldsContainer = document.getElementById('nameFieldsContainer');

                    if (!ticketsSelect || !nameFieldsContainer) return;

                    const numberOfTickets = parseInt(ticketsSelect.value, 10) || 1;


                    nameFieldsContainer.replaceChildren();


                    if (numberOfTickets > 1) {
                        for (let i = 2; i <= numberOfTickets; i++) {
                            const fieldGroup = document.createElement('div');
                            fieldGroup.className = 'space-y-2';

                            const label = document.createElement('label');
                            label.className = 'block text-sm font-medium avo-muted';

                            label.textContent = '<?php echo addslashes($current_language === "de" ? "Name für Ticket" : "Name for Ticket"); ?> ' + i;

                            const input = document.createElement('input');
                            input.type = 'text';
                            input.name = 'add_people[]';
                            input.placeholder = 'Max Mustermann';
                            input.required = true;
                            input.className = 'input w-full';

                            fieldGroup.appendChild(label);
                            fieldGroup.appendChild(input);
                            nameFieldsContainer.appendChild(fieldGroup);
                        }
                    }
                }


                function initTicketSelector() {
                    const ticketsSelect = document.querySelector('select[name="tickets"]');
                    if (!ticketsSelect) return;


                    ticketsSelect.removeEventListener('change', updateNameFields);
                    ticketsSelect.addEventListener('change', updateNameFields);


                    updateNameFields();
                }

                // General-admission +/- stepper. The hidden <select name="tickets">
                // stays the single source of truth (validation/summary/availability
                // all read it); the buttons just write it and fire 'change' so the
                // name fields + everything else stay in sync.
                function gaStepperInit() {
                    const sel = document.getElementById('tickets');
                    const val = document.getElementById('gaCountVal');
                    const minus = document.getElementById('gaMinus');
                    const plus = document.getElementById('gaPlus');
                    if (!sel || !val || !minus || !plus) return;

                    function sync() {
                        const n = parseInt(sel.value, 10) || 1;
                        val.textContent = String(n);
                        minus.disabled = n <= 1;
                        plus.disabled = n >= (sel.options.length || 1);
                    }
                    function step(delta) {
                        const n = parseInt(sel.value, 10) || 1;
                        const max = sel.options.length || 1;
                        const next = Math.min(max, Math.max(1, n + delta));
                        if (next === n) return;
                        sel.value = String(next);
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        sync();
                    }
                    minus.addEventListener('click', () => step(-1));
                    plus.addEventListener('click', () => step(1));
                    sel.addEventListener('change', sync);
                    // Let the modal-open code resync after it rebuilds the options.
                    window.gaStepperSync = sync;
                    sync();
                }


                document.addEventListener('DOMContentLoaded', initTicketSelector);
                document.addEventListener('DOMContentLoaded', gaStepperInit);


                function updatePaymentMethodButtons() {
                    // Show/hide the method buttons on step 3 according to what the
                    // organizer enabled. Auto-skipping the step when only one method
                    // is available is handled by maybeAutoMethod() on entering step 3.
                    document.querySelectorAll('.payment-method-btn').forEach(button => {
                        const method = button.getAttribute('data-method');
                        button.style.display = 'flex';
                        if (availablePaymentMethods === 'cash' && method === 'online') {
                            button.style.display = 'none';
                        } else if (availablePaymentMethods === 'online' && method === 'cash') {
                            button.style.display = 'none';
                        }
                    });
                }


                async function initPaymentMethods() {
                    await loadPaymentMethods();
                    updatePaymentMethodButtons();
                }


                let selectedDateDisplay = '';
                let selectedLocation = '';

                // ---- Reserved seating -----------------------------------------
                const SEAT_R = 12;
                const SEAT_MAX = 10;
                window.isSeated = false;
                let seatAvail = null;          // last availability response
                let selectedSeats = [];        // [{id,label,price}] in selection order
                let holdActive = false;
                let holdTimerId = null;

                function csrfValue() {
                    return document.querySelector('#bookingForm input[name="csrf_token"]').value;
                }

                async function loadSeatAvailability(date) {
                    const svg = document.getElementById('seatSvg');
                    svg.innerHTML = '<text x="10" y="24" fill="#888" font-size="13">Loading seats…</text>';
                    try {
                        const r = await fetch('seat-proxy.php?action=availability&date=' + encodeURIComponent(date));
                        seatAvail = await r.json();
                    } catch (e) { seatAvail = null; }
                    selectedSeats = [];
                    setSeatWant(1);
                    showSeatCountPhase();
                    document.getElementById('seatOrphanHint')?.classList.add('hidden');
                    if (!seatAvail || seatAvail.status !== 'success' || !seatAvail.seating) {
                        svg.innerHTML = '<text x="10" y="24" fill="#e00" font-size="13">Seat map unavailable.</text>';
                        return;
                    }
                    renderSeatMap(seatAvail);
                    updateSeatDerived();
                }

                function renderSeatMap(data) {
                    const els = data.elements || [];
                    const cats = {};
                    (data.categories || []).forEach(c => { cats[String(c.id)] = c; });
                    // Bounding box.
                    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
                    els.forEach(e => {
                        if (e.type === 'seat') {
                            minX = Math.min(minX, e.x - SEAT_R); minY = Math.min(minY, e.y - SEAT_R);
                            maxX = Math.max(maxX, e.x + SEAT_R); maxY = Math.max(maxY, e.y + SEAT_R);
                        } else {
                            const w = e.w || 40, h = e.h || 40;
                            minX = Math.min(minX, e.x); minY = Math.min(minY, e.y);
                            maxX = Math.max(maxX, e.x + w); maxY = Math.max(maxY, e.y + h);
                        }
                    });
                    if (!isFinite(minX)) { minX = 0; minY = 0; maxX = 200; maxY = 120; }
                    const pad = 24;
                    const vbW = (maxX - minX) + pad * 2, vbH = (maxY - minY) + pad * 2;
                    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
                    let out = '';
                    els.forEach(e => {
                        if (e.type === 'seat') return; // seats drawn on top below
                        const w = e.w || 40, h = e.h || 40;
                        const rot = e.rotation ? ` transform="rotate(${e.rotation} ${e.x + w / 2} ${e.y + h / 2})"` : '';
                        if (e.type === 'table') {
                            out += `<ellipse cx="${e.x + w / 2}" cy="${e.y + h / 2}" rx="${w / 2}" ry="${h / 2}" fill="#8b5cf6" opacity="0.5"${rot}/>`;
                        } else if (e.type === 'label') {
                            out += `<text x="${e.x}" y="${e.y + 14}" fill="var(--avo-text,#ccc)" font-size="14"${rot}>${esc(e.text || '')}</text>`;
                        } else {
                            const fill = e.type === 'stage' ? '#6b7280' : e.type === 'screen' ? '#0ea5e9' : e.type === 'wall' ? '#52525b' : '#6b7280';
                            const lbl = e.type === 'stage' ? 'STAGE' : e.type === 'screen' ? 'SCREEN' : '';
                            out += `<rect x="${e.x}" y="${e.y}" width="${w}" height="${h}" rx="4" fill="${fill}" opacity="0.55"${rot}/>`;
                            if (lbl) out += `<text x="${e.x + w / 2}" y="${e.y + h / 2 + 4}" fill="#fff" font-size="11" text-anchor="middle"${rot}>${lbl}</text>`;
                        }
                    });
                    els.forEach(e => {
                        if (e.type !== 'seat') return;
                        const cat = e.category_id != null ? cats[String(e.category_id)] : null;
                        const baseFill = cat ? cat.color : '#3b82f6';
                        const sold = e.status === 'sold' || e.status === 'held';
                        const fill = sold ? '#6b7280' : baseFill;
                        const label = ((e.row || '') + (e.number != null ? e.number : '')) || '';
                        out += `<g class="seat-node" data-seat="${esc(e.id)}" data-price="${e.price || 0}" data-label="${esc(label)}" data-sold="${sold ? 1 : 0}" style="cursor:${sold ? 'not-allowed' : 'pointer'}">`;
                        out += `<circle cx="${e.x}" cy="${e.y}" r="${SEAT_R}" fill="${fill}" data-basefill="${fill}" fill-opacity="${sold ? 0.4 : 1}" stroke="#0008" stroke-width="1"/>`;
                        if (label) out += `<text x="${e.x}" y="${e.y + 3}" fill="#fff" font-size="9" text-anchor="middle" pointer-events="none">${esc(label)}</text>`;
                        out += `</g>`;
                    });
                    const svg = document.getElementById('seatSvg');
                    svg.setAttribute('viewBox', `${minX - pad} ${minY - pad} ${vbW} ${vbH}`);
                    svg.innerHTML = out;
                    seatMapVB = { w: vbW, h: vbH };
                    initSeatZoomControls();
                    initSeatAuto();
                    resetSeatZoom();
                    svg.querySelectorAll('.seat-node').forEach(g => {
                        if (g.getAttribute('data-sold') === '1') return;
                        g.addEventListener('click', () => { if (!seatPanMoved) onSeatClick(g); });
                    });
                    renderLegend(data);
                }

                // ---- seat-map zoom & pan (native scroll for pan, px sizing for zoom) ----
                let seatMapVB = { w: 200, h: 120 };
                let seatZoom = 1;
                let seatPanMoved = false;
                function applySeatZoom() {
                    const svg = document.getElementById('seatSvg');
                    svg.style.maxWidth = 'none';
                    svg.style.width = (seatMapVB.w * seatZoom) + 'px';
                    svg.style.height = (seatMapVB.h * seatZoom) + 'px';
                    svg.style.minHeight = '0';
                }
                // "Whole hall" — fit every column into view (rows may scroll).
                function fitSeatZoom() {
                    const box = document.getElementById('seatMapScroll');
                    const cw = (box && box.clientWidth) ? box.clientWidth - 2 : 360;
                    seatZoom = Math.max(0.25, Math.min(1.6, cw / seatMapVB.w));
                    applySeatZoom();
                }
                // Default view: fit the whole hall. On desktop fit width AND height
                // so the entire map is visible with no scrolling; on mobile fit the
                // width (rows may scroll). Users can still zoom in manually.
                function resetSeatZoom() {
                    const box = document.getElementById('seatMapScroll');
                    const cw = (box && box.clientWidth) ? box.clientWidth - 2 : 360;
                    const fitW = cw / seatMapVB.w;
                    if (window.innerWidth > 820) {
                        // Apply width-fit first so the box reflows to its (clamped)
                        // height budget, then shrink to also fit vertically if needed.
                        seatZoom = Math.max(0.1, fitW);
                        applySeatZoom();
                        const ch = (box && box.clientHeight) ? box.clientHeight - 2 : 480;
                        seatZoom = Math.max(0.1, Math.min(fitW, ch / seatMapVB.h));
                    } else {
                        seatZoom = Math.max(0.1, fitW);
                    }
                    applySeatZoom();
                }
                function zoomSeatBy(factor, cx, cy) {
                    const box = document.getElementById('seatMapScroll');
                    const prev = seatZoom;
                    seatZoom = Math.max(0.25, Math.min(4, seatZoom * factor));
                    if (seatZoom === prev) return;
                    // Keep the point under the cursor stable while zooming.
                    const rect = box.getBoundingClientRect();
                    const ox = (cx == null ? rect.width / 2 : cx - rect.left) + box.scrollLeft;
                    const oy = (cy == null ? rect.height / 2 : cy - rect.top) + box.scrollTop;
                    const ratio = seatZoom / prev;
                    applySeatZoom();
                    box.scrollLeft = ox * ratio - (cx == null ? rect.width / 2 : cx - rect.left);
                    box.scrollTop = oy * ratio - (cy == null ? rect.height / 2 : cy - rect.top);
                }
                function initSeatZoomControls() {
                    const box = document.getElementById('seatMapScroll');
                    if (!box || box.dataset.zoomInit) return;
                    box.dataset.zoomInit = '1';
                    document.getElementById('seatZoomIn').addEventListener('click', () => zoomSeatBy(1.3));
                    document.getElementById('seatZoomOut').addEventListener('click', () => zoomSeatBy(1 / 1.3));
                    document.getElementById('seatZoomFit').addEventListener('click', () => fitSeatZoom());
                    box.addEventListener('wheel', (e) => {
                        // Plain wheel = scroll the map. Only ctrl/⌘ + wheel (and
                        // trackpad pinch, which sets ctrlKey) zooms.
                        if (!e.ctrlKey && !e.metaKey) return;
                        e.preventDefault();
                        zoomSeatBy(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX, e.clientY);
                    }, { passive: false });

                    // Drag to pan (mouse + single-finger touch). Movement past a small
                    // threshold suppresses the seat click so panning never selects.
                    let dragging = false, sx = 0, sy = 0, sl = 0, st = 0;
                    box.addEventListener('pointerdown', (e) => {
                        if (e.pointerType === 'mouse' && e.button !== 0) return;
                        dragging = true; seatPanMoved = false;
                        sx = e.clientX; sy = e.clientY; sl = box.scrollLeft; st = box.scrollTop;
                    });
                    box.addEventListener('pointermove', (e) => {
                        if (!dragging) return;
                        const dx = e.clientX - sx, dy = e.clientY - sy;
                        if (!seatPanMoved && Math.abs(dx) + Math.abs(dy) > 6) seatPanMoved = true;
                        if (seatPanMoved) { box.scrollLeft = sl - dx; box.scrollTop = st - dy; box.style.cursor = 'grabbing'; }
                    });
                    const endDrag = () => { dragging = false; box.style.cursor = ''; setTimeout(() => { seatPanMoved = false; }, 0); };
                    box.addEventListener('pointerup', endDrag);
                    box.addEventListener('pointercancel', endDrag);
                    box.addEventListener('pointerleave', () => { dragging = false; box.style.cursor = ''; });

                    // Two-finger pinch zoom.
                    const pts = new Map(); let pinchDist = 0;
                    box.addEventListener('pointerdown', (e) => { pts.set(e.pointerId, e); });
                    box.addEventListener('pointermove', (e) => {
                        if (!pts.has(e.pointerId)) return;
                        pts.set(e.pointerId, e);
                        if (pts.size === 2) {
                            const [a, b] = [...pts.values()];
                            const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
                            if (pinchDist) {
                                seatPanMoved = true;
                                zoomSeatBy(d / pinchDist, (a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2);
                            }
                            pinchDist = d;
                        }
                    });
                    const dropPt = (e) => { pts.delete(e.pointerId); if (pts.size < 2) pinchDist = 0; };
                    box.addEventListener('pointerup', dropPt);
                    box.addEventListener('pointercancel', dropPt);
                }

                function renderLegend(data) {
                    const leg = document.getElementById('seatLegend');
                    let html = '';
                    (data.categories || []).forEach(c => {
                        html += `<span style="display:inline-flex;align-items:center;gap:.35rem;"><span style="width:.8rem;height:.8rem;border-radius:50%;background:${c.color};display:inline-block;"></span>${escHtml(c.name)} · ${Number(c.price).toFixed(2)} €</span>`;
                    });
                    if (!(data.categories || []).length) {
                        html += `<span style="display:inline-flex;align-items:center;gap:.35rem;"><span style="width:.8rem;height:.8rem;border-radius:50%;background:#3b82f6;display:inline-block;"></span>${Number(data.base_price || 0).toFixed(2)} €</span>`;
                    }
                    html += `<span style="display:inline-flex;align-items:center;gap:.35rem;"><span style="width:.8rem;height:.8rem;border-radius:50%;background:#6b7280;display:inline-block;"></span>Sold</span>`;
                    leg.innerHTML = html;
                }
                function escHtml(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

                function seatNode(id) {
                    return document.querySelector('.seat-node[data-seat="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
                }
                // Visual only: paint a seat as picked (orange + white ring) or unpicked.
                function markPicked(g, on) {
                    const c = g.querySelector('circle'); if (!c) return;
                    if (on) {
                        c.setAttribute('fill', 'var(--avo-primary,#f97316)');
                        c.setAttribute('fill-opacity', '1');
                        c.setAttribute('stroke', '#ffffff'); c.setAttribute('stroke-width', '3');
                        g.setAttribute('data-picked', '1');
                    } else {
                        c.setAttribute('fill', c.getAttribute('data-basefill') || '#3b82f6');
                        c.setAttribute('fill-opacity', '1');
                        c.setAttribute('stroke', '#0008'); c.setAttribute('stroke-width', '1');
                        g.removeAttribute('data-picked');
                    }
                }

                function toggleSeat(g) {
                    const id = g.getAttribute('data-seat');
                    const idx = selectedSeats.findIndex(s => s.id === id);
                    if (idx >= 0) {
                        selectedSeats.splice(idx, 1);
                        markPicked(g, false);
                    } else {
                        if (selectedSeats.length >= SEAT_MAX) { showErrorDialog('You can select at most ' + SEAT_MAX + ' seats.'); return; }
                        selectedSeats.push({
                            id,
                            label: g.getAttribute('data-label') || id,
                            price: parseFloat(g.getAttribute('data-price')) || 0,
                        });
                        markPicked(g, true);
                    }
                    updateSeatDerived();
                    checkOrphans();
                    if (window.isSeated) scheduleSyncHold();   // reserve immediately
                }

                // Replace the whole selection at once (used by auto-pick / snap).
                function setSelection(ids) {
                    selectedSeats.slice().forEach(s => { const g = seatNode(s.id); if (g) markPicked(g, false); });
                    selectedSeats = [];
                    (ids || []).forEach(id => {
                        const g = seatNode(id);
                        if (!g || g.getAttribute('data-sold') === '1') return;
                        selectedSeats.push({ id, label: g.getAttribute('data-label') || id, price: parseFloat(g.getAttribute('data-price')) || 0 });
                        markPicked(g, true);
                    });
                    updateSeatDerived();
                    checkOrphans();
                    if (window.isSeated) scheduleSyncHold();
                }

                // ---- auto seat picking (anti-fragmentation) -------------------
                let _seatWant = 1;
                function seatWant() { return _seatWant; }
                function setSeatWant(v) {
                    _seatWant = Math.max(1, Math.min(SEAT_MAX, v | 0 || 1));
                    const el = document.getElementById('seatWantVal'); if (el) el.textContent = _seatWant;
                    const echo = document.getElementById('seatWantEcho'); if (echo) echo.textContent = _seatWant;
                    renderSeatNames();
                }
                // Extra-ticket name inputs live in the count phase (persons 2..N),
                // driven by the chosen amount. Preserve whatever was typed.
                function renderSeatNames() {
                    const c = document.getElementById('seatNameFields');
                    if (!c) return;
                    const prev = [...c.querySelectorAll('input[name="add_people[]"]')].map(i => i.value);
                    c.replaceChildren();
                    for (let i = 2; i <= _seatWant; i++) {
                        const grp = document.createElement('div'); grp.className = 'space-y-2';
                        const label = document.createElement('label'); label.className = 'block text-sm font-medium avo-muted';
                        label.textContent = '<?php echo addslashes($current_language === "de" ? "Name für Ticket" : "Name for ticket"); ?> ' + i;
                        const input = document.createElement('input');
                        input.type = 'text'; input.name = 'add_people[]'; input.placeholder = 'Max Mustermann';
                        input.required = true; input.className = 'input w-full';
                        input.value = prev[i - 2] || '';
                        grp.appendChild(label); grp.appendChild(input); c.appendChild(grp);
                    }
                }
                // Two-phase step 2: choose the count first, then reveal the map.
                function showSeatCountPhase() {
                    document.getElementById('seatCountPhase')?.classList.remove('hidden');
                    document.getElementById('seatMapPhase')?.classList.add('hidden');
                }
                function showSeatMapPhase() {
                    document.getElementById('seatCountPhase')?.classList.add('hidden');
                    document.getElementById('seatMapPhase')?.classList.remove('hidden');
                    // Map had no width while hidden — size it once it's visible.
                    requestAnimationFrame(() => requestAnimationFrame(resetSeatZoom));
                }
                function initSeatAuto() {
                    const bar = document.getElementById('seatCountPhase');
                    if (!bar || bar.dataset.init) return;
                    bar.dataset.init = '1';
                    document.getElementById('seatWantMinus').addEventListener('click', () => setSeatWant(_seatWant - 1));
                    document.getElementById('seatWantPlus').addEventListener('click', () => setSeatWant(_seatWant + 1));
                    document.getElementById('seatCountNext').addEventListener('click', () => showSeatMapPhase());
                    document.getElementById('seatAutoPick').addEventListener('click', () => { showSeatMapPhase(); pickBestSeats(_seatWant); });
                    document.getElementById('seatCountBack').addEventListener('click', () => { setSelection([]); showSeatCountPhase(); });
                }

                function isSeatFree(id) { const g = seatNode(id); return !!g && g.getAttribute('data-sold') !== '1'; }

                // Group seats into rows and sort each by x. Uses the seat's `row`
                // field when present, else clusters by y.
                function seatRows() {
                    const seats = ((seatAvail && seatAvail.elements) || []).filter(e => e.type === 'seat');
                    let rows = [];
                    const useRow = seats.some(s => s.row != null && String(s.row).trim() !== '');
                    if (useRow) {
                        const m = {};
                        seats.forEach(s => { const k = String(s.row); (m[k] = m[k] || []).push(s); });
                        rows = Object.keys(m).map(k => ({ seats: m[k], y: m[k][0].y }));
                        rows.sort((a, b) => a.y - b.y);
                    } else {
                        const sorted = seats.slice().sort((a, b) => a.y - b.y);
                        const tol = SEAT_R * 1.5; let cur = [], cy = null;
                        sorted.forEach(s => {
                            if (cy === null || Math.abs(s.y - cy) <= tol) { cur.push(s); cy = cy === null ? s.y : (cy + s.y) / 2; }
                            else { rows.push({ seats: cur, y: cy }); cur = [s]; cy = s.y; }
                        });
                        if (cur.length) rows.push({ seats: cur, y: cy });
                    }
                    rows.forEach(r => r.seats.sort((a, b) => a.x - b.x));
                    return rows;
                }

                // Split a row into physical runs (an aisle = a gap much larger than
                // the typical seat spacing breaks a run).
                function rowRuns(row) {
                    const ss = row.seats;
                    const gaps = []; for (let i = 1; i < ss.length; i++) gaps.push(ss[i].x - ss[i - 1].x);
                    const med = gaps.length ? gaps.slice().sort((a, b) => a - b)[Math.floor(gaps.length / 2)] : 0;
                    const thr = med ? med * 1.6 : Infinity;
                    const runs = []; let seg = [];
                    for (let i = 0; i < ss.length; i++) {
                        if (i > 0 && (ss[i].x - ss[i - 1].x) > thr) { runs.push(seg); seg = []; }
                        seg.push(ss[i]);
                    }
                    if (seg.length) runs.push(seg);
                    return runs;
                }

                // Maximal contiguous stretches of currently-free seats, each bounded
                // by taken seats / aisles / row ends (so any size-1 remainder is a
                // true orphan). Carries row centering info for scoring.
                function freeSegments() {
                    const rows = seatRows();
                    const n = rows.length, mid = (n - 1) / 2;
                    const segs = [];
                    rows.forEach((row, ri) => {
                        rowRuns(row).forEach(run => {
                            const rx0 = run[0].x, rx1 = run[run.length - 1].x, rcx = (rx0 + rx1) / 2;
                            let cur = [];
                            const flush = () => { if (cur.length) { segs.push({ seats: cur, rowDist: Math.abs(ri - mid), rowCenterX: rcx }); cur = []; } };
                            run.forEach(s => { if (isSeatFree(s.id)) cur.push(s); else flush(); });
                            flush();
                        });
                    });
                    return segs;
                }

                // Best contiguous block of n free seats: never leave a lone orphan,
                // prefer a perfect fit, then central rows and central position.
                function pickBestSeats(n) {
                    n = Math.max(1, Math.min(SEAT_MAX, n | 0 || 1));
                    const segs = freeSegments();
                    let best = null;
                    segs.forEach(seg => {
                        const L = seg.seats.length; if (L < n) return;
                        for (let off = 0; off <= L - n; off++) {
                            const leftRem = off, rightRem = L - n - off;
                            let sc = 0;
                            if (leftRem === 1) sc += 1000;
                            if (rightRem === 1) sc += 1000;
                            if (L === n) sc -= 200;
                            const block = seg.seats.slice(off, off + n);
                            const bx = (block[0].x + block[block.length - 1].x) / 2;
                            sc += seg.rowDist * 5;
                            sc += Math.abs(bx - seg.rowCenterX) / 40;
                            if (!best || sc < best.sc) best = { sc, ids: block.map(b => b.id) };
                        }
                    });
                    if (!best) {
                        showErrorDialog('<?php echo addslashes($current_language === "de" ? "Nicht genug freie Plätze nebeneinander. Bitte wählen Sie einzeln." : "Not enough adjacent free seats. Please pick seats individually."); ?>');
                        return;
                    }
                    setSelection(best.ids);
                    scrollToSeat(best.ids[0]);
                }

                // Snap a block of n seats near a clicked anchor (same row), keeping
                // the anchor covered and avoiding orphans.
                function snapBlockAt(anchorId, n) {
                    n = Math.max(1, Math.min(SEAT_MAX, n | 0 || 1));
                    let host = null, ai = -1;
                    outer:
                    for (const seg of freeSegments()) {
                        const k = seg.seats.findIndex(s => s.id === anchorId);
                        if (k >= 0) { host = seg; ai = k; break outer; }
                    }
                    if (!host || host.seats.length < n) { pickBestSeats(n); return; }
                    const L = host.seats.length;
                    const lo = Math.max(0, ai - (n - 1)), hi = Math.min(ai, L - n);
                    let best = null;
                    for (let off = lo; off <= hi; off++) {
                        const leftRem = off, rightRem = L - n - off;
                        let sc = 0;
                        if (leftRem === 1) sc += 1000;
                        if (rightRem === 1) sc += 1000;
                        sc += (ai - off) * 2; // keep anchor near the block's left/click point
                        if (!best || sc < best.sc) best = { sc, ids: host.seats.slice(off, off + n).map(b => b.id) };
                    }
                    setSelection(best.ids);
                    scrollToSeat(anchorId);
                }

                function onSeatClick(g) {
                    const id = g.getAttribute('data-seat');
                    const want = seatWant();
                    if (want > 1) { snapBlockAt(id, want); return; }
                    toggleSeat(g); // single-seat mode: additive tap-to-toggle
                }

                function scrollToSeat(id) {
                    const g = seatNode(id), box = document.getElementById('seatMapScroll');
                    if (!g || !box) return;
                    const bb = g.getBoundingClientRect(), rb = box.getBoundingClientRect();
                    box.scrollLeft += (bb.left + bb.width / 2) - (rb.left + rb.width / 2);
                    box.scrollTop += (bb.top + bb.height / 2) - (rb.top + rb.height / 2);
                }

                // Gentle warning when the current selection would strand a lone seat.
                function checkOrphans() {
                    const hint = document.getElementById('seatOrphanHint');
                    if (!hint) return;
                    if (!selectedSeats.length) { hint.classList.add('hidden'); return; }
                    const sel = new Set(selectedSeats.map(s => s.id));
                    const freeAfter = id => isSeatFree(id) && !sel.has(id);
                    let orphan = false;
                    seatRows().forEach(row => {
                        rowRuns(row).forEach(run => {
                            for (let i = 0; i < run.length; i++) {
                                if (!freeAfter(run[i].id)) continue;
                                const lFree = i > 0 && freeAfter(run[i - 1].id);
                                const rFree = i < run.length - 1 && freeAfter(run[i + 1].id);
                                if (lFree || rFree) continue; // not isolated
                                const adjSel = (i > 0 && sel.has(run[i - 1].id)) || (i < run.length - 1 && sel.has(run[i + 1].id));
                                if (adjSel) orphan = true;
                            }
                        });
                    });
                    if (orphan) {
                        hint.textContent = '<?php echo addslashes($current_language === "de" ? "Hinweis: Neben Ihrer Auswahl bleibt ein einzelner Platz frei – vielleicht mit dazunehmen?" : "Note: your selection leaves a single seat empty next to it — maybe add it too?"); ?>';
                        hint.classList.remove('hidden');
                    } else {
                        hint.classList.add('hidden');
                    }
                }

                function seatTotal() { return selectedSeats.reduce((a, s) => a + s.price, 0); }

                function updateSeatDerived() {
                    const n = selectedSeats.length;
                    const total = seatTotal();
                    // Info line.
                    const info = document.getElementById('seatSelInfo');
                    info.textContent = n
                        ? (n + ' seat' + (n > 1 ? 's' : '') + ' · ' + total.toFixed(2) + ' €')
                        : 'Tap seats to select.';
                    // Submitted price + ticket count.
                    document.getElementById('ticketPrice').value = total.toFixed(2);
                    const sel = document.querySelector('select[name="tickets"]');
                    if (sel) {
                        if (!sel.querySelector('option[value="' + Math.max(1, n) + '"]')) {
                            const o = document.createElement('option'); o.value = Math.max(1, n); sel.appendChild(o);
                        }
                        sel.value = Math.max(1, n);
                    }
                    // Hidden seats[] inputs (order = selection order).
                    const box = document.getElementById('seatsHidden');
                    box.innerHTML = '';
                    selectedSeats.forEach(s => {
                        const i = document.createElement('input');
                        i.type = 'hidden'; i.name = 'seats[]'; i.value = s.id; box.appendChild(i);
                    });
                    // Keep the chosen amount (and its name fields, shown in the count
                    // phase) in step with the actual selection — e.g. when single-tap
                    // mode adds more seats than initially requested.
                    if (n >= 1 && n !== _seatWant) setSeatWant(n);
                }

                // ---- live availability (so two buyers don't fight over a seat) ----
                let seatPollId = null;
                function startSeatPoll() {
                    stopSeatPoll();
                    refreshSeatStatuses();
                    seatPollId = setInterval(refreshSeatStatuses, 5000);
                }
                function stopSeatPoll() {
                    if (seatPollId) { clearInterval(seatPollId); seatPollId = null; }
                }
                async function refreshSeatStatuses() {
                    if (!window.isSeated) return;
                    let data;
                    try {
                        const r = await fetch('seat-proxy.php?action=availability&date=' + encodeURIComponent(document.getElementById('validDate').value));
                        data = await r.json();
                    } catch (e) { return; }
                    if (!data || data.status !== 'success' || !data.seating) return;
                    const byId = {};
                    (data.elements || []).forEach(e => { if (e.type === 'seat') byId[e.id] = e; });
                    const setSold = (g) => {
                        const c = g.querySelector('circle');
                        g.setAttribute('data-sold', '1'); g.style.cursor = 'not-allowed';
                        c.setAttribute('fill', '#6b7280'); c.setAttribute('fill-opacity', '0.4');
                        c.setAttribute('stroke', '#0008'); c.setAttribute('stroke-width', '1');
                    };
                    const setFree = (g) => {
                        const c = g.querySelector('circle');
                        g.setAttribute('data-sold', '0'); g.style.cursor = 'pointer';
                        c.setAttribute('fill', c.getAttribute('data-basefill') || '#3b82f6');
                        c.setAttribute('fill-opacity', '1'); c.setAttribute('stroke', '#0008'); c.setAttribute('stroke-width', '1');
                    };
                    const lost = [];
                    document.querySelectorAll('.seat-node').forEach(g => {
                        const id = g.getAttribute('data-seat');
                        const e = byId[id]; if (!e) return;
                        const taken = e.status === 'sold' || e.status === 'held';
                        const mine = selectedSeats.some(s => s.id === id);
                        if (mine) {
                            // My own soft-hold reads back as "held" — that's fine. Only a
                            // real loss is: sold, or held while I don't hold it yet.
                            if (e.status === 'sold' || (e.status === 'held' && !holdActive)) lost.push(id);
                            return;
                        }
                        if (taken && g.getAttribute('data-sold') !== '1') setSold(g);
                        else if (!taken && g.getAttribute('data-sold') === '1') setFree(g);
                    });
                    if (lost.length) {
                        const labels = [];
                        lost.forEach(id => {
                            const i = selectedSeats.findIndex(s => s.id === id);
                            if (i >= 0) { labels.push(selectedSeats[i].label); selectedSeats.splice(i, 1); }
                            const g = document.querySelector('.seat-node[data-seat="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
                            if (g) setSold(g);
                        });
                        updateSeatDerived();
                        showErrorDialog('<?php echo addslashes($current_language === "de" ? "Ein gewählter Platz wurde gerade von jemand anderem genommen:" : "A seat you picked was just taken by someone else:"); ?> ' + labels.join(', '));
                    }
                    checkOrphans();
                }

                // Reserve the current selection the moment it changes (debounced), so
                // a second buyer sees these seats as taken within one poll cycle and
                // can't pick them too.
                let syncTimer = null, syncing = false, syncAgain = false;
                function scheduleSyncHold() {
                    clearTimeout(syncTimer);
                    syncTimer = setTimeout(syncHold, 450);
                }
                async function syncHold() {
                    if (!window.isSeated) return;
                    if (syncing) { syncAgain = true; return; }
                    syncing = true;
                    try {
                        if (document.getElementById('holdToken').value) await releaseSeatHold();
                        if (selectedSeats.length) await placeSeatHold();
                    } finally {
                        syncing = false;
                        if (syncAgain) { syncAgain = false; syncHold(); }
                    }
                }

                async function placeSeatHold() {
                    if (!selectedSeats.length) return false;
                    const fd = new FormData();
                    fd.append('csrf_token', csrfValue());
                    fd.append('action', 'hold');
                    fd.append('date', document.getElementById('validDate').value);
                    selectedSeats.forEach(s => fd.append('seats[]', s.id));
                    let res;
                    try {
                        const r = await fetch('seat-proxy.php', { method: 'POST', body: fd });
                        res = await r.json();
                    } catch (e) { showErrorDialog('Network error. Please try again.'); return false; }
                    if (!res || res.status !== 'success' || !res.hold_token) {
                        // Someone grabbed a seat first — refresh and let them re-pick.
                        await loadSeatAvailability(document.getElementById('validDate').value);
                        showErrorDialog('Sorry, one or more of your seats was just taken. Please choose again.');
                        return false;
                    }
                    holdActive = true;
                    document.getElementById('holdToken').value = res.hold_token;
                    startHoldTimer(res.ttl || 600);
                    return true;
                }

                function startHoldTimer(ttl) {
                    clearHoldTimer();
                    const el = document.getElementById('seatHoldTimer');
                    el.classList.remove('hidden');
                    let remaining = ttl;
                    const tick = () => {
                        const m = Math.floor(remaining / 60), s = remaining % 60;
                        el.textContent = 'Seats reserved for ' + m + ':' + String(s).padStart(2, '0');
                        if (remaining <= 0) {
                            clearHoldTimer();
                            holdActive = false;
                            document.getElementById('holdToken').value = '';
                            el.classList.add('hidden');
                            showErrorDialog('Your seat reservation expired. Please pick your seats again.');
                            goToStep(2);
                            loadSeatAvailability(document.getElementById('validDate').value);
                            return;
                        }
                        remaining -= 1;
                    };
                    tick();
                    holdTimerId = setInterval(tick, 1000);
                }
                function clearHoldTimer() { if (holdTimerId) { clearInterval(holdTimerId); holdTimerId = null; } }

                async function releaseSeatHold() {
                    clearHoldTimer();
                    document.getElementById('seatHoldTimer')?.classList.add('hidden');
                    const token = document.getElementById('holdToken').value;
                    if (!token) { holdActive = false; return; }
                    document.getElementById('holdToken').value = '';
                    holdActive = false;
                    const fd = new FormData();
                    fd.append('csrf_token', csrfValue());
                    fd.append('action', 'release');
                    fd.append('date', document.getElementById('validDate').value);
                    fd.append('hold_token', token);
                    try { await fetch('seat-proxy.php', { method: 'POST', body: fd, keepalive: true }); } catch (e) {}
                }

                function showBookingForm(showId, date, price, ticketsAvailable, dateDisplay, location, seating) {
                    lastFocusedElement = document.activeElement;
                    document.getElementById('bookingModal').showModal();

                    window.isSeated = seating == 1 || seating === true;
                    document.getElementById('validDate').value = date;
                    document.getElementById('ticketPrice').value = price;
                    document.getElementById('holdToken').value = '';
                    document.getElementById('seatsHidden').innerHTML = '';
                    selectedSeats = [];
                    document.body.style.overflow = 'hidden';

                    const gaWrap = document.getElementById('gaTicketWrap');
                    const seatWrap = document.getElementById('seatPickerWrap');
                    const step2 = document.querySelector('.wizard-step[data-step="2"]');
                    if (window.isSeated) {
                        gaWrap.classList.add('hidden');
                        seatWrap.classList.remove('hidden');
                        if (step2) step2.classList.remove('ga-mode'); // wide, top-aligned for the map
                        loadSeatAvailability(date);
                    } else {
                        gaWrap.classList.remove('hidden');
                        seatWrap.classList.add('hidden');
                        if (step2) step2.classList.add('ga-mode'); // narrow, centred
                    }

                    // Persistent context banner: show chosen date + location on every step.
                    selectedDateDisplay = dateDisplay || '';
                    selectedLocation = location || '';
                    const ctx = document.getElementById('dialogContext');
                    document.getElementById('dialogDate').textContent = selectedDateDisplay;
                    const locRow = document.getElementById('dialogLocationRow');
                    if (selectedLocation) {
                        document.getElementById('dialogLocation').textContent = selectedLocation;
                        locRow.style.display = 'flex';
                    } else {
                        locRow.style.display = 'none';
                    }
                    ctx.classList.remove('hidden');
                    // Mobile mirror (sidebar date card is hidden on small screens).
                    const mDate = document.getElementById('mobileStepDate');
                    if (mDate) mDate.textContent = [selectedDateDisplay, selectedLocation].filter(Boolean).join(' · ');

                    const ticketsSelect = document.querySelector('select[name="tickets"]');
                    ticketsSelect.innerHTML = '';
                    const maxtickets = Math.min(ticketsAvailable, 10);
                    for (let i = 1; i <= maxtickets; i++) {
                        const option = document.createElement('option');
                        option.value = i;
                        option.textContent = `${i} Ticket${i > 1 ? 's' : ''}`;
                        ticketsSelect.appendChild(option);
                    }
                    // Options were rebuilt for this date's availability — resync the
                    // stepper display + button bounds to match.
                    if (window.gaStepperSync) window.gaStepperSync();

                    // Clear any name/email from a previous booking, then start fresh at step 1.
                    document.querySelector('input[name="first_name"]').value = '';
                    document.querySelector('input[name="last_name"]').value = '';
                    document.querySelector('input[name="email"]').value = '';

                    initTicketSelector();
                    updatePaymentMethodButtons();
                    resetWizard();
                }


                async function processPayment(paymentDetails) {
                    const available = await checkAvailability(paymentDetails.showId, paymentDetails.ticketCount);
                    if (!available) {
                        throw new Error("Nicht genügend Plätze verfügbar.");
                    }
                }

                async function checkAvailability(showId, ticketCount) {

                    const response = await fetch(`/api/ticket/available_tickets/${showId}`);
                    const data = await response.json();

                    if (data.status === "error") {
                        throw new Error(data.message);
                    }


                    return data.available_tickets >= ticketCount;
                }

                function showErrorDialog(message) {
                    const dialog = document.getElementById('error-dialog');
                    const description = dialog.querySelector('#error-dialog-description');


                    description.innerHTML = '';


                    const lines = message.split('\n');

                    lines.forEach((line, index) => {

                        description.appendChild(document.createTextNode(line));


                        if (index < lines.length - 1) {
                            description.appendChild(document.createElement('br'));
                        }
                    });

                    return new Promise((resolve) => {
                        dialog.addEventListener('close', () => resolve(false), { once: true });
                        dialog.showModal();
                    });
                }

            </script>

        <?php endif; ?>
    <?php endif; ?>

    <?php
    $orgName = $shows['orga_name'] ?? '';
    include __DIR__ . '/partials/footer.php';
    ?>
</body>

</html>