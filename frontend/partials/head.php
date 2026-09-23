<?php
/**
 * Shared <head> for QrGate (avocloud brand kit v4.1).
 *
 * Set these BEFORE including, all optional:
 *   $assetBase  string  web-relative path to /frontend root. '' for top-level
 *                       pages, '../' for admin/, '../../' for admin/handheld/,
 *                       '../' for screens/. Default ''.
 *   $pageTitle  string  document title. Default 'QrGate'.
 *   $faviconUrl string  favicon href. Default = organizer logo from the API
 *                       (white-label), else avocloud app icon.
 *   $forceDark  bool    force dark theme + no persisted toggle (kiosk screens).
 *   $extraHead  string  raw HTML appended inside <head> (Stripe, manifest, …).
 *
 * LOAD ORDER guaranteed here:
 *   tailwind → basecoat → brand/avocloud.base.css (imports the kit) → avocloud.css
 *
 * Theme: dark is the brand default. A stored choice of "light" puts
 * `.avo-light` on <html> (the kit's light theme); `dark` stays for Tailwind's
 * dark: variants on older pages.
 */
$assetBase  = $assetBase  ?? '';
$pageTitle  = $pageTitle  ?? 'QrGate';
$forceDark  = $forceDark  ?? false;
$extraHead  = $extraHead  ?? '';
if (!isset($faviconUrl)) {
    // Same-origin image path (nginx proxies /api/image/ to the backend); falls
    // back to the bundled icon if config isn't loaded.
    $faviconUrl = defined('PUBLIC_API_BASE')
        ? PUBLIC_API_BASE . '/api/image/get/logo.png?t=' . time()
        : $assetBase . 'assets/img/avocloud-appicon-dark.svg';
}
$kitVersion = '4.2.0';
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="theme-color" content="#141518">

    <!-- theme guard: apply the stored choice before first paint (no flash) -->
    <script>
        (function () {
            var root = document.documentElement;
            var light = false;
            <?php if (!$forceDark): ?>
            try { light = localStorage.getItem('avo-theme') === 'light'; } catch (e) {}
            <?php endif; ?>
            root.classList.toggle('avo-light', light);
            root.classList.toggle('dark', !light);
        })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/basecoat-css@0.3.10-beta.2/dist/basecoat.cdn.min.css">
    <script src="https://cdn.jsdelivr.net/npm/basecoat-css@0.3.10-beta.2/dist/js/all.min.js" defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- QrGate type: IBM Plex Mono for everything, Syne only for wordmarks -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Syne:wght@700;800&display=swap">

    <!-- the kit MUST load after basecoat so its theme wins -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/brand/avocloud.base.css?v=<?php echo $kitVersion; ?>">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/avocloud.css?v=<?php echo $kitVersion; ?>">
    <?php if (!$forceDark): ?>
    <script src="<?php echo $assetBase; ?>assets/theme.js?v=<?php echo $kitVersion; ?>" defer></script>
    <?php endif; ?>

    <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php echo $extraHead; ?>
</head>
