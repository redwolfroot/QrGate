<?php
require_once '../config.php';

// Must be logged in.
if (empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}
// Force a pending password change first.
if (!empty($_SESSION['must_change_pw'])) {
    header('Location: change_password.php');
    exit;
}

$perms = $_SESSION['perms'] ?? ['admin' => false, 'ticketflow' => false, 'handheld' => false];
$username = $_SESSION['username'];

// Apps this account may open.
$apps = [];
if (!empty($perms['admin'])) {
    $apps[] = ['href' => 'index.php', 'title' => 'Admin Panel', 'desc' => 'Dashboard, events, stats & accounts'];
}
if (!empty($perms['ticketflow'])) {
    $apps[] = ['href' => 'ticketflow/index.php', 'title' => 'TicketFlow', 'desc' => 'Box office — sell & manage tickets'];
}
if (!empty($perms['handheld'])) {
    $apps[] = ['href' => 'handheld/index.php', 'title' => 'Handheld Scanner', 'desc' => 'Validate tickets at the door'];
}

$pageTitle = 'Choose App';
$assetBase = '../';
?>
<!DOCTYPE html>
<html lang="en" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="min-h-screen flex flex-col">
    <div class="avo-topbar" aria-hidden="true"></div>
    <main class="flex-1 flex items-center justify-center p-4">
        <div class="w-full max-w-2xl">
            <div class="text-center mb-8">
                <div class="avo-kicker mb-1">signed in as <?= htmlspecialchars($username) ?></div>
                <h1 class="text-3xl">Choose an <span class="avo-hl">app</span></h1>
                <p class="avo-muted mt-1 text-sm">Pick where you want to go.</p>
            </div>

            <?php if (empty($apps)): ?>
                <div class="card"><div class="card-content text-center avo-muted">
                    Your account has no app access. Contact an administrator.
                </div></div>
            <?php else: ?>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?php foreach ($apps as $app): ?>
                        <a href="<?= htmlspecialchars($app['href']) ?>" class="card hover:border-[color:var(--avo-primary)]"
                           style="text-decoration:none; transition:border-color .12s;">
                            <div class="card-content">
                                <h2 class="text-xl mb-1"><?= htmlspecialchars($app['title']) ?></h2>
                                <p class="avo-muted text-sm"><?= htmlspecialchars($app['desc']) ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="text-center mt-8 flex justify-center gap-5">
                <a href="change_password.php" class="avo-link text-sm">Change password</a>
                <a href="logout.php" class="avo-link text-sm">Log out</a>
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
