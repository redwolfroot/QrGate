<?php
/**
 * Shared footer for QrGate (avocloud brand kit v4.1).
 *
 * Optional vars (set before include):
 *   $assetBase   string  web-relative path to /frontend root (see head.php). Default ''.
 *   $orgName     string  organizer name shown before "Powered by". Default ''.
 *   $current_language string 'de' | 'en' — picks privacy-link label. Default 'en'.
 *   $privacyHref string  href to the privacy page. Default $assetBase.'datenschutz.php'.
 *   $showToggle  bool    show light/dark toggle button. Default true.
 */
$assetBase   = $assetBase   ?? '';
$orgName     = $orgName     ?? '';
$current_language = $current_language ?? 'en';
$privacyHref = $privacyHref ?? ($assetBase . 'datenschutz.php');
$showToggle  = $showToggle  ?? true;
$privacyLabel = ($current_language === 'de') ? 'Datenschutz' : 'Privacy';
?>
<footer class="qg-footer">
    <div class="qg-footer__in">
        <span class="avo-serial">
            <?php if ($orgName !== ''): ?><?php echo htmlspecialchars($orgName); ?> · <?php endif; ?>Powered by
        </span>
        <a href="https://avocloud.net" target="_blank" rel="noopener" class="qg-lockup" aria-label="avocloud.net">
            <svg viewBox="0 0 72 72" fill="none" aria-hidden="true">
                <path d="M22 18 L12 18 L12 54 L22 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M50 18 L60 18 L60 54 L50 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M26 30 L33 36 L26 42" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                <rect x="40" y="28" width="4" height="16" rx="2" fill="#FF6B4A"/>
            </svg>
            <b>avocloud<span>.net</span></b>
        </a>
        <span class="qg-footer__sp"></span>
        <a href="<?php echo htmlspecialchars($privacyHref); ?>" class="avo-link avo-mono" style="font-size:var(--avo-ui-label);letter-spacing:.1em;text-transform:uppercase;"><?php echo $privacyLabel; ?></a>
        <?php if ($showToggle): ?>
        <button type="button" class="avo-theme-toggle" data-avo-theme-toggle aria-label="Toggle theme">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
        </button>
        <?php endif; ?>
    </div>
</footer>
<style>
    .qg-footer { position: relative; z-index: var(--avo-z-content); border-top: 1px solid var(--avo-line); margin-top: var(--avo-space-8); }
    .qg-footer__in { max-width: var(--avo-maxw); margin: 0 auto; padding: var(--avo-space-5) var(--avo-container-pad);
        display: flex; align-items: center; flex-wrap: wrap; gap: var(--avo-space-3); }
    .qg-footer__sp { flex: 1 1 auto; }
    .qg-footer .qg-lockup b { font-size: .85rem; }
    .qg-footer .qg-lockup svg { width: 18px; height: 18px; }
</style>
