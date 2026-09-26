<?php
/**
 * Announcement overlay for the foyer screens. Included right before </body>
 * by every screen. Polls the public, read-only endpoint through the shop's
 * api-proxy every 3 s and covers the screen in the category colour while an
 * announcement runs. No sound. The text is inserted as text, never as HTML.
 */
?>
<style>
    .qg-cast {
        position: fixed; inset: 0; z-index: 9999;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 3vh; padding: 6vh 7vw; text-align: center;
        background: var(--qg-cast-bg); color: var(--qg-cast-fg);
        opacity: 0; visibility: hidden; transition: opacity .4s ease, visibility 0s linear .4s;
    }
    .qg-cast.show { opacity: 1; visibility: visible; transition: opacity .4s ease; }
    .qg-cast[data-cat="info"]      { --qg-cast-bg: #1d4ed8; --qg-cast-fg: #fff; }
    .qg-cast[data-cat="attention"] { --qg-cast-bg: #f5b400; --qg-cast-fg: #111; }
    .qg-cast[data-cat="alert"]     { --qg-cast-bg: #c81e1e; --qg-cast-fg: #fff; }
    .qg-cast[data-cat="success"]   { --qg-cast-bg: #15803d; --qg-cast-fg: #fff; }
    .qg-cast__kicker {
        font-family: var(--avo-font-mono, monospace); font-weight: 700;
        font-size: clamp(1rem, 2.4vw, 2.2rem); letter-spacing: .2em; text-transform: uppercase;
        padding: .3em .9em; border: 3px solid currentColor; border-radius: 999px;
    }
    .qg-cast__text {
        font-family: var(--avo-font-display, sans-serif); font-weight: 800;
        font-size: clamp(2.4rem, 7vw, 8rem); line-height: 1.08; max-width: 18ch;
        overflow-wrap: anywhere;
    }
    .qg-cast__en {
        font-size: clamp(1.2rem, 3vw, 3.2rem); font-weight: 600; opacity: .85; max-width: 30ch;
    }
    .qg-cast[data-cat="alert"].show .qg-cast__kicker { animation: qgCastPulse 1.2s ease-in-out infinite; }
    @keyframes qgCastPulse { 50% { opacity: .35; } }
    @media (prefers-reduced-motion: reduce) {
        .qg-cast, .qg-cast.show { transition: none; }
        .qg-cast[data-cat="alert"].show .qg-cast__kicker { animation: none; }
    }
</style>
<div class="qg-cast" id="qgCast" role="alert" aria-live="assertive" data-cat="info">
    <div class="qg-cast__kicker" id="qgCastKicker"></div>
    <div class="qg-cast__text" id="qgCastText"></div>
    <div class="qg-cast__en" id="qgCastEn" hidden></div>
</div>
<script>
(function () {
    var LABEL = { info: 'Information', attention: 'Achtung', alert: 'Dringend', success: 'Hinweis' };
    var el = document.getElementById('qgCast');
    var url = '../api-proxy.php?endpoint=broadcast';
    var hideTimer = null;

    function hide() {
        clearTimeout(hideTimer);
        el.classList.remove('show');
    }
    function show(b) {
        if (!b || !LABEL[b.category]) { hide(); return; }
        el.dataset.cat = b.category;
        document.getElementById('qgCastKicker').textContent = LABEL[b.category];
        document.getElementById('qgCastText').textContent = b.text || '';
        var en = document.getElementById('qgCastEn');
        en.textContent = b.text_en || '';
        en.hidden = !b.text_en;
        el.classList.add('show');
        // Leave on time even if the next poll is late or the network is gone.
        clearTimeout(hideTimer);
        if (typeof b.expires_in === 'number') hideTimer = setTimeout(hide, b.expires_in * 1000 + 300);
    }
    function poll() {
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (d && d.status === 'success') show(d.broadcast);
                // No answer: keep what is shown; the timer ends it on time.
            })
            .catch(function () {})
            .then(function () { setTimeout(poll, 3000); });
    }
    poll();
})();
</script>
