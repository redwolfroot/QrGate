<?php

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new Exception('Invalid request. Please try again.');
        }

        // Honeypot: a hidden field no real user fills in. If it has a value,
        // it's almost certainly a bot — silently bounce back to the homepage.
        if (!empty($_POST['website'])) {
            error_log('buy.php: honeypot triggered from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            header('Location: index.php');
            exit;
        }

        // The booking is binding; the customer must tick the consent checkbox.
        // Enforced server-side too so it can't be bypassed by editing the form.
        if (empty($_POST['consent'])) {
            $lang = $_SESSION['language'] ?? 'en';
            throw new Exception($lang === 'de'
                ? 'Bitte bestätigen Sie den verbindlichen Buchungshinweis.'
                : 'Please confirm the binding booking notice.');
        }

        $requiredFields = ['first_name', 'last_name', 'email', 'tickets', 'valid_date', 'payment_method', 'price'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Feld '$field' ist erforderlich");
            }
        }

        // Email is required in the UI but was previously optional server-side.
        // A paid ticket with no/invalid email is never delivered.
        $email = trim($_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        $paymentMethodsResponse = makeApiCall('/api/show/get/payment_methods');
        $allowedPaymentMethods = ['cash', 'stripe', 'bar'];

        if (isset($paymentMethodsResponse['payment_methods'])) {
            $paymentMethods = $paymentMethodsResponse['payment_methods'];

            if ($paymentMethods === 'cash') {
                $allowedPaymentMethods = ['cash', 'bar'];
            } elseif ($paymentMethods === 'online') {
                $allowedPaymentMethods = ['stripe'];
            } elseif ($paymentMethods === 'both') {
                $allowedPaymentMethods = ['cash', 'stripe', 'bar'];
            }
        }

        $ticketCount = (int)$_POST['tickets'];

        // Validate add_people: must be a flat array of bounded-length strings.
        // An attacker can otherwise submit a nested array and abuse it downstream.
        $addPeople = [];
        if (isset($_POST['add_people'])) {
            if (!is_array($_POST['add_people'])) {
                throw new Exception('Invalid additional names! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
            }
            foreach ($_POST['add_people'] as $person) {
                if (!is_string($person)) {
                    throw new Exception('Invalid additional names! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
                }
                $addPeople[] = mb_substr(trim($person), 0, 100);
            }
        }

        // The form collects an extra name for every ticket beyond the first,
        // so add_people must hold exactly ($ticketCount - 1) entries.
        if (count($addPeople) !== max(0, $ticketCount - 1)) {
            throw new Exception('Invalid additional names! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
        }

        $ticketData = [
            'first_name' => trim($_POST['first_name']),
            'last_name'  => trim($_POST['last_name']),
            'email'      => $email,
            'tickets'    => $ticketCount,
            'valid_date' => $_POST['valid_date'],
            'price'      => (float)$_POST['price'],
            'paid'       => false,
            'method'     => $_POST['payment_method'],
            'add_people' => $addPeople,
            // Buyer's current UI language → localizes the ticket email + PDF.
            'lang'       => in_array(($_SESSION['language'] ?? 'en'), ['de', 'en'], true)
                ? ($_SESSION['language'] ?? 'en') : 'en',
        ];

        // Enforce the same maximum as the UI (1–10 tickets).
        if ($ticketData['tickets'] < 1 || $ticketData['tickets'] > 10) {
            throw new Exception('Invalid number of people! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
        }

        if (!in_array($ticketData['method'], $allowedPaymentMethods)) {
            throw new Exception('Invalid payment method selected! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
        }

        // Authoritative price/date check: never trust the client-supplied price.
        // Look up the real price for the chosen date from the show config and
        // override it, rejecting any date that doesn't exist.
        $show = makeApiCall('/api/show/get');
        if (isset($show['error']) || empty($show['dates']) || !is_array($show['dates'])) {
            throw new Exception('Could not verify event data. Please try again later.');
        }

        $matchedPrice = null;
        $matchedSeating = false;
        $matchedTime = '';
        $matchedLocId = null;
        foreach ($show['dates'] as $dateData) {
            if (($dateData['date'] ?? null) === $ticketData['valid_date']) {
                $matchedPrice = (float)$dateData['price'];
                $matchedSeating = !empty($dateData['seating']);
                $matchedTime = $dateData['time'] ?? '';
                $matchedLocId = $dateData['location'] ?? null;
                break;
            }
        }
        $matchedLocName = '';
        if ($matchedLocId !== null && isset($show['locations'][$matchedLocId]['name'])) {
            $matchedLocName = $show['locations'][$matchedLocId]['name'];
        }

        if ($matchedPrice === null) {
            throw new Exception('Invalid event date selected! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
        }

        // Override the (untrusted) submitted price with the authoritative one.
        $ticketData['price'] = $matchedPrice;

        // Order total drives the Stripe amount check below. For general
        // admission it's price × tickets; for reserved seating it's the
        // authoritative sum of the chosen seats' category prices.
        $orderTotal = $ticketData['price'] * $ticketData['tickets'];

        if ($matchedSeating) {
            $seats = qrgate_sanitize_seats($_POST['seats'] ?? null);
            $holdToken = trim($_POST['hold_token'] ?? '');
            if (!$seats || $holdToken === '') {
                throw new Exception('Please select your seats again.');
            }
            if (count($seats) !== $ticketData['tickets']) {
                throw new Exception('Seat selection mismatch. Please try again.');
            }
            $sum = qrgate_seat_order_total($ticketData['valid_date'], $seats);
            if (!$sum['ok']) {
                throw new Exception('Some of your seats are no longer available. Please pick again.');
            }
            $orderTotal = $sum['total'];
            // Record a per-ticket price so backend income stats stay ~accurate.
            $ticketData['price'] = $ticketData['tickets'] > 0
                ? round($orderTotal / $ticketData['tickets'], 2) : 0;
            $ticketData['seats'] = $seats;
            $ticketData['hold_token'] = $holdToken;
        }

        // Resolve seat labels (seated orders) for the success ticket card.
        $seatLabels = ($matchedSeating && !empty($ticketData['seats']))
            ? qrgate_seat_labels($ticketData['valid_date'], $ticketData['seats'])
            : [];

        // Stash the confirmed booking details so index.php can render the
        // ticket-stub success screen (mirrors the cancel page's stub).
        $setSuccessTicket = function (bool $paid) use ($show, $ticketData, $matchedTime, $matchedLocName, $seatLabels) {
            $_SESSION['success_ticket'] = [
                'event_name' => $show['orga_name'] ?? '',
                'date'       => $ticketData['valid_date'],
                'time'       => $matchedTime,
                'location'   => $matchedLocName,
                'name'       => trim($ticketData['first_name'] . ' ' . $ticketData['last_name']),
                'tickets'    => (int)$ticketData['tickets'],
                'seats'      => $seatLabels,
                'paid'       => $paid,
            ];
        };

        if ($ticketData['method'] === 'bar') {
            // Rate-limit unpaid cash bookings: they create real tickets and send
            // emails with no payment, so bots could drain availability and spam.
            // Allow at most RL_MAX bookings per RL_WINDOW seconds, tracked per
            // session AND per client IP.
            $rlMax    = 3;
            $rlWindow = 600; // 10 minutes
            $now      = time();
            $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            if (!isset($_SESSION['bar_booking_log']) || !is_array($_SESSION['bar_booking_log'])) {
                $_SESSION['bar_booking_log'] = [];
            }

            // Drop timestamps outside the current window and prune other IPs' history.
            $log = [];
            foreach ($_SESSION['bar_booking_log'] as $entry) {
                if (isset($entry['ts'], $entry['ip']) && ($now - $entry['ts']) < $rlWindow) {
                    $log[] = $entry;
                }
            }

            $recentForSession = count($log);
            $recentForIp = 0;
            foreach ($log as $entry) {
                if ($entry['ip'] === $ip) {
                    $recentForIp++;
                }
            }

            if ($recentForSession >= $rlMax || $recentForIp >= $rlMax) {
                error_log('buy.php: cash booking rate limit hit (ip ' . $ip . ')');
                throw new Exception('Too many bookings in a short time. Please wait a few minutes and try again.');
            }

            $log[] = ['ts' => $now, 'ip' => $ip];
            $_SESSION['bar_booking_log'] = $log;

            $result = makeApiCall('/api/ticket/create', 'POST', $ticketData);

            if (isset($result['error'])) {
                throw new Exception($result['error']);
            }

            if ($result['status'] === 'success') {
                $lang = $_SESSION['language'] ?? 'en';
                $successMessages = [
                    'de' => 'Ihre Tickets wurden erfolgreich übermittelt. Sie erhalten Ihre Tickets in Kürze per E-Mail. Bitte bezahlen Sie Ihre Tickets am Veranstaltungstag an unserer Ticketkasse.',
                    'en' => 'Your tickets have been successfully submitted to the system. You will receive your tickets by email shortly. Please pay your tickets on the day of the event at our ticket counter.',
                ];
                $_SESSION['success'] = $successMessages[$lang] ?? $successMessages['en'];
                $setSuccessTicket(false);
            } else {
                throw new Exception('Ticket could not be created!');
            }

            header('Location: index.php');
            exit;

        } elseif ($ticketData['method'] === 'stripe') {
            $paymentIntentId = trim($_POST['payment_intent_id'] ?? '');

            if (empty($paymentIntentId)) {
                throw new Exception('Payment verification failed: no payment reference provided.');
            }

            // Retrieve Stripe config (secret key) from backend
            $stripeConfig = makeApiCall('/api/show/get/stripe');

            if (isset($stripeConfig['error']) || empty($stripeConfig['secret_key'])) {
                throw new Exception('Payment configuration error. Please contact the organizer.');
            }

            // Verify payment server-side with Stripe (direct curl, no SDK)
            $secretKey = $stripeConfig['secret_key'];
            $intentId  = preg_replace('/[^a-zA-Z0-9_]/', '', $paymentIntentId); // sanitize

            $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . $intentId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $secretKey . ':',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $stripeResponse = curl_exec($ch);
            $stripeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($stripeHttpCode !== 200) {
                throw new Exception('Payment verification failed. Please contact the organizer.');
            }

            $intent = json_decode($stripeResponse, true);

            if (($intent['status'] ?? '') !== 'succeeded') {
                throw new Exception('Payment has not been completed. Please try again.');
            }

            // Verify amount matches expected order total (seat sum for reserved
            // seating, price × tickets otherwise), in cents.
            $expectedCents = (int)round($orderTotal * 100);
            if (($intent['amount_received'] ?? 0) !== $expectedCents) {
                throw new Exception('Payment amount mismatch. Please contact the organizer.');
            }

            $ticketData['paid'] = true;
            // Idempotency: hand the verified PaymentIntent id to the backend so it
            // can reject a reused intent (HTTP 409 payment_already_used) instead of
            // creating duplicate tickets if the customer retries/refreshes.
            $ticketData['payment_intent_id'] = $intent['id'] ?? $intentId;

            // Direct call so we can read the 409 response body (makeApiCall would
            // collapse it into a generic error string).
            $createCh = curl_init(API_BASE_URL . '/api/ticket/create');
            curl_setopt_array($createCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($ticketData),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: ' . API_KEY,
                    'Content-Type: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $createResponse = curl_exec($createCh);
            $createHttpCode = curl_getinfo($createCh, CURLINFO_HTTP_CODE);
            $createError    = curl_error($createCh);
            curl_close($createCh);

            if ($createResponse === false) {
                error_log('buy.php: ticket create call failed: ' . $createError);
                throw new Exception('Could not reach the ticketing system. Please contact the organizer with your payment reference ' . $intentId . '.');
            }

            $result = json_decode($createResponse, true);

            // Backend rejects a reused PaymentIntent: do NOT create duplicate
            // tickets — the tickets already exist from the first attempt.
            if ($createHttpCode === 409 || (isset($result['message']) && $result['message'] === 'payment_already_used')) {
                $alreadyUsedMessages = [
                    'de' => 'Diese Zahlung wurde bereits zur Erstellung Ihrer Tickets verwendet — bitte prüfen Sie Ihre E-Mails oder kontaktieren Sie uns mit der Referenz ' . $intentId . '.',
                    'en' => 'This payment was already used to create your tickets — check your email, or contact us with reference ' . $intentId . '.',
                ];
                $lang = $_SESSION['language'] ?? 'en';
                $_SESSION['error'] = $alreadyUsedMessages[$lang] ?? $alreadyUsedMessages['en'];
                header('Location: index.php');
                exit;
            }

            if ($createHttpCode !== 200 || !is_array($result)) {
                error_log('buy.php: ticket create returned HTTP ' . $createHttpCode . ': ' . $createResponse);
                throw new Exception('Ticket could not be created. Please contact the organizer with your payment reference ' . $intentId . '.');
            }

            if (($result['status'] ?? '') === 'success') {
                $lang = $_SESSION['language'] ?? 'en';
                $successMessages = [
                    'de' => 'Ihre Tickets wurden erfolgreich erfasst und bezahlt. Sie erhalten Ihre Tickets in Kürze per E-Mail.',
                    'en' => 'Your tickets have been successfully entered and paid for. You will receive your tickets by email shortly.',
                ];
                $_SESSION['success'] = $successMessages[$lang] ?? $successMessages['en'];
                $setSuccessTicket(true);
            } else {
                throw new Exception('Ticket could not be created. Please contact the organizer with your payment reference ' . $intentId . '.');
            }

            header('Location: index.php');
            exit;

        } else {
            throw new Exception('Unknown payment method! Please do not play around with the code of this page, otherwise your access to this page will be denied.');
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: index.php');
        exit;
    }
}


header('Location: index.php');
exit;
