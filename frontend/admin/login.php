<?php
require_once '../config.php';

// --- Login brute-force throttle (per client IP, file-backed) -----------------
// Simple cross-session counter so an attacker can't bypass it by dropping the
// session cookie. After LOGIN_MAX_ATTEMPTS failures within LOGIN_WINDOW seconds
// the IP is locked out for LOGIN_LOCKOUT seconds.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW = 900;   // 15 min sliding window for counting failures
const LOGIN_LOCKOUT = 300;  // 5 min lockout once tripped

function loginThrottleFile($ip) {
    return sys_get_temp_dir() . '/qrgate_login_' . hash('sha256', $ip) . '.json';
}
function loginThrottleGet($ip) {
    $f = loginThrottleFile($ip);
    if (is_file($f)) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) return $d + ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }
    return ['count' => 0, 'first' => time(), 'locked_until' => 0];
}
function loginThrottleSave($ip, $d) {
    @file_put_contents(loginThrottleFile($ip), json_encode($d), LOCK_EX);
}
function loginThrottleReset($ip) {
    @unlink(loginThrottleFile($ip));
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = time();
    $throttle = loginThrottleGet($clientIp);
    // Expire the counting window.
    if ($now - $throttle['first'] > LOGIN_WINDOW) {
        $throttle = ['count' => 0, 'first' => $now, 'locked_until' => 0];
    }

    if ($throttle['locked_until'] > $now) {
        $error = 'Too many failed attempts. Please try again later.';
    } elseif (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        // CSRF validation
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $redirect = $_GET['redirect'] ?? '';

        // Authenticate against the managed-accounts backend.
        $resp = makeApiCall('/api/auth/login', 'POST', [
            'username' => $username,
            'password' => $password,
        ]);

        if (is_array($resp) && ($resp['status'] ?? '') === 'success') {
            loginThrottleReset($clientIp);
            // prevent session fixation: issue a fresh session id on login
            session_regenerate_id(true);

            $perms = $resp['permissions'] ?? [];
            $_SESSION['username'] = $resp['username'] ?? $username;
            $_SESSION['perms'] = [
                'admin'      => !empty($perms['admin']),
                'ticketflow' => !empty($perms['ticketflow']),
                'handheld'   => !empty($perms['handheld']),
            ];
            // Legacy access flags kept so existing pages don't need rewiring.
            if ($_SESSION['perms']['admin'])      $_SESSION['admin'] = true;
            if ($_SESSION['perms']['ticketflow']) $_SESSION['ticketflow_access'] = true;
            if ($_SESSION['perms']['handheld'])   $_SESSION['handheld_access'] = true;

            // Force a password change before anything else.
            if (!empty($resp['must_change_pw'])) {
                $_SESSION['must_change_pw'] = true;
                header('Location: change_password.php');
                exit;
            }

            // Honor an explicit ?redirect= target if the user may access it.
            if ($redirect === 'ticketflow' && $_SESSION['perms']['ticketflow']) {
                header('Location: ticketflow/index.php');
                exit;
            }
            if ($redirect === 'handheld' && $_SESSION['perms']['handheld']) {
                header('Location: handheld/index.php');
                exit;
            }
            if ($redirect === 'admin' && $_SESSION['perms']['admin']) {
                header('Location: index.php');
                exit;
            }
            // Otherwise let the user pick from the apps they can access.
            header('Location: apps.php');
            exit;
        } else {
            // Failed attempt: count it and lock out if over the limit.
            $throttle['count']++;
            if ($throttle['count'] >= LOGIN_MAX_ATTEMPTS) {
                $throttle['locked_until'] = $now + LOGIN_LOCKOUT;
            }
            loginThrottleSave($clientIp, $throttle);
            $error = 'Invalid username or password';
        }
    }
}


// Already authenticated: force a pending password change, else go to the chooser.
if (!empty($_SESSION['username'])) {
    if (!empty($_SESSION['must_change_pw'])) {
        header('Location: change_password.php');
    } else {
        header('Location: apps.php');
    }
    exit;
}

$pageTitle = 'QrGate Admin Login';
$assetBase = '../';
?>
<!DOCTYPE html>
<html lang="en" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="min-h-screen flex flex-col">
    <div class="avo-topbar" aria-hidden="true"></div>
    <main class="flex-1 flex items-center justify-center p-4">
        <div class="card w-full max-w-sm">
            <div class="card-header text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full"
                     style="background-color: color-mix(in oklab, var(--avo-primary) 16%, transparent);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="avo-coral" aria-hidden="true">
                        <rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/>
                        <rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/>
                        <path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/>
                        <path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/>
                        <path d="M12 21v-1"/>
                    </svg>
                </div>
                <div class="avo-kicker mb-1">access control</div>
                <h1 class="text-2xl">QrGate <span class="avo-hl">Admin</span></h1>
                <p class="avo-muted mt-1 text-sm">Enter your admin credentials</p>
            </div>

            <div class="card-content">
                <?php if (isset($error)): ?>
                    <div class="alert alert-destructive mb-6" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="form grid gap-5">
                    <?php echo csrfField(); ?>
                    <div class="grid gap-2">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="input"
                            placeholder="Enter username"
                            autocomplete="username"
                            required
                            autofocus
                        />
                    </div>
                    <div class="grid gap-2">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="input"
                            placeholder="Enter password"
                            autocomplete="current-password"
                            required
                        />
                        <p class="avo-muted text-xs">
                            Sign in with your account. You'll be taken to the apps you can access.
                        </p>
                    </div>

                    <button type="submit" class="btn-primary w-full">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/>
                            <circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>
                        </svg>
                        Submit
                    </button>
                </form>
            </div>
        </div>
    </main>

    <?php
    $orgName = '';
    $current_language = 'en';
    include __DIR__ . '/../partials/footer.php';
    ?>
</body>
</html>
