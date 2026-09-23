<?php
require_once '../config.php';

// Must be logged in to change a password.
if (empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            $resp = makeApiCall('/api/auth/change_password', 'POST', [
                'username'     => $_SESSION['username'],
                'old_password' => $old,
                'new_password' => $new,
            ]);
            if (is_array($resp) && ($resp['status'] ?? '') === 'success') {
                unset($_SESSION['must_change_pw']);
                header('Location: apps.php');
                exit;
            } else {
                $error = $resp['message'] ?? 'Could not change password.';
            }
        }
    }
}

$forced = !empty($_SESSION['must_change_pw']);
$pageTitle = 'Change Password';
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
                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <div class="avo-kicker mb-1">security</div>
                <h1 class="text-2xl">Change <span class="avo-hl">Password</span></h1>
                <p class="avo-muted mt-1 text-sm">
                    <?php echo $forced
                        ? 'Set a new password before continuing.'
                        : 'Update your account password.'; ?>
                </p>
            </div>

            <div class="card-content">
                <?php if ($error): ?>
                    <div class="alert alert-destructive mb-6" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="form grid gap-5">
                    <?php echo csrfField(); ?>
                    <div class="grid gap-2">
                        <label for="old_password">Current password</label>
                        <input type="password" name="old_password" id="old_password" class="input"
                               autocomplete="current-password" required autofocus>
                    </div>
                    <div class="grid gap-2">
                        <label for="new_password">New password</label>
                        <input type="password" name="new_password" id="new_password" class="input"
                               autocomplete="new-password" minlength="6" required>
                    </div>
                    <div class="grid gap-2">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="input"
                               autocomplete="new-password" minlength="6" required>
                    </div>
                    <button type="submit" class="btn-primary w-full">Save new password</button>
                    <?php if (!$forced): ?>
                        <a href="apps.php" class="avo-link text-center text-sm">Back</a>
                    <?php else: ?>
                        <a href="logout.php" class="avo-link text-center text-sm">Log out</a>
                    <?php endif; ?>
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
