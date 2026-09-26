<?php
/**
 * Announcement overlay for the foyer screens, printed by scr_close(). Polls
 * the public, read-only endpoint through the shop's api-proxy every 3 s and
 * takes over the screen while an announcement runs: category colour, icon,
 * the text as large as it fits, the English line, and a countdown when it
 * has an end. No sound. The text is inserted as text, never as HTML.
 */
?>
<style>
    .qg-cast {
        --u: 1vmin;
        position: fixed; inset: 0; z-index: 9999; overflow: hidden; isolation: isolate;
        display: grid; grid-template-rows: auto minmax(0, 1fr) auto;
        padding: calc(var(--u) * 5) calc(var(--u) * 6); box-sizing: border-box;
        color: var(--c-fg); background: var(--c-deep);
        font-family: var(--avo-font-mono, 'IBM Plex Mono', monospace);
        opacity: 0; visibility: hidden; transform: scale(1.03);
        transition: opacity .5s ease, transform .6s cubic-bezier(.2,.8,.2,1), visibility 0s linear .6s;
    }
    .qg-cast.show { opacity: 1; visibility: visible; transform: none; transition: opacity .5s ease, transform .6s cubic-bezier(.2,.8,.2,1); }
    .qg-cast[data-cat="info"]      { --c: #2f6bff; --c-deep: #0a1a4a; --c-fg: #fff;    --c-soft: rgba(255,255,255,.74); }
    .qg-cast[data-cat="attention"] { --c: #ffc21a; --c-deep: #f0a800; --c-fg: #141008; --c-soft: rgba(20,16,8,.72); }
    .qg-cast[data-cat="alert"]     { --c: #ff3b30; --c-deep: #6e0b08; --c-fg: #fff;    --c-soft: rgba(255,255,255,.78); }
    .qg-cast[data-cat="success"]   { --c: #2fd07a; --c-deep: #083a22; --c-fg: #fff;    --c-soft: rgba(255,255,255,.74); }

    /* background: colour field with a glow and a fine grid; attention and
       alert get a warning-tape edge top and bottom */
    .qg-cast__bg { position: absolute; inset: 0; z-index: -1; }
    .qg-cast__bg::before {
        content: ""; position: absolute; inset: 0;
        background:
            radial-gradient(ellipse 75% 65% at 50% 48%, color-mix(in oklab, var(--c) 60%, transparent), transparent 72%),
            linear-gradient(160deg, color-mix(in oklab, var(--c) 30%, var(--c-deep)), var(--c-deep) 70%);
    }
    .qg-cast[data-cat="attention"] .qg-cast__bg::before { background: radial-gradient(ellipse 75% 65% at 50% 48%, #ffd84d, transparent 75%), linear-gradient(160deg, #ffcc33, #eea300); }
    .qg-cast__bg::after {
        content: ""; position: absolute; inset: 0;
        background-image: linear-gradient(to right, rgba(255,255,255,.07) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,.07) 1px, transparent 1px);
        background-size: calc(var(--u) * 8) calc(var(--u) * 8);
        mask-image: radial-gradient(ellipse at center, #000 20%, transparent 80%);
    }
    .qg-cast[data-cat="attention"] .qg-cast__bg::after { background-image: linear-gradient(to right, rgba(0,0,0,.07) 1px, transparent 1px), linear-gradient(to bottom, rgba(0,0,0,.07) 1px, transparent 1px); }
    .qg-cast__tape { position: absolute; left: 0; right: 0; height: calc(var(--u) * 2); display: none;
        background: repeating-linear-gradient(-45deg, #141008 0 calc(var(--u) * 2.2), var(--c) calc(var(--u) * 2.2) calc(var(--u) * 4.4)); }
    .qg-cast__tape.t { top: 0; }
    .qg-cast__tape.b { bottom: 0; }
    .qg-cast[data-cat="attention"] .qg-cast__tape, .qg-cast[data-cat="alert"] .qg-cast__tape { display: block; }
    .qg-cast[data-cat="alert"] .qg-cast__tape { background: repeating-linear-gradient(-45deg, #fff 0 calc(var(--u) * 2.2), var(--c) calc(var(--u) * 2.2) calc(var(--u) * 4.4)); }

    /* top: category chip left, clock right */
    .qg-cast__top { display: flex; align-items: center; justify-content: space-between; gap: calc(var(--u) * 3); }
    .qg-cast__chip {
        display: inline-flex; align-items: center; gap: calc(var(--u) * 1.3);
        padding: calc(var(--u) * 1.1) calc(var(--u) * 2.6) calc(var(--u) * 1.1) calc(var(--u) * 1.5); border-radius: 999px;
        font-size: calc(var(--u) * 2.4); font-weight: 700; letter-spacing: .2em; text-transform: uppercase;
        background: var(--c-fg); color: var(--c-deep);
    }
    .qg-cast__chip .qg-cast__ico { width: calc(var(--u) * 3.4); height: calc(var(--u) * 3.4); }
    .qg-cast__ico { fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .qg-cast__clock { font-size: calc(var(--u) * 4.6); font-weight: 500; font-variant-numeric: tabular-nums; color: var(--c-soft); }

    /* the message */
    .qg-cast__main { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: calc(var(--u) * 3.6); min-height: 0; }
    .qg-cast__big {
        display: grid; place-items: center; width: calc(var(--u) * 16); height: calc(var(--u) * 16); border-radius: 50%;
        background: color-mix(in oklab, var(--c-fg) 15%, transparent);
        box-shadow: 0 0 0 calc(var(--u) * .5) color-mix(in oklab, var(--c-fg) 38%, transparent), 0 0 calc(var(--u) * 14) color-mix(in oklab, var(--c-fg) 22%, transparent);
    }
    .qg-cast[data-cat="attention"] .qg-cast__big { background: #141008; color: #ffc21a; box-shadow: 0 calc(var(--u) * 2) calc(var(--u) * 8) rgba(0,0,0,.25); }
    .qg-cast__big .qg-cast__ico { width: 52%; height: 52%; stroke-width: 2; }
    .qg-cast__text {
        margin: 0; max-width: 20ch; font-weight: 700; line-height: 1.08; letter-spacing: -.015em;
        text-wrap: balance; overflow-wrap: anywhere; font-size: calc(var(--u) * 10);
    }
    .qg-cast__text.is-m { font-size: calc(var(--u) * 7.6); max-width: 24ch; }
    .qg-cast__text.is-s { font-size: calc(var(--u) * 5.6); max-width: 30ch; line-height: 1.15; }
    .qg-cast__en { margin: 0; max-width: 42ch; font-size: calc(var(--u) * 3.4); font-weight: 500; line-height: 1.3; color: var(--c-soft); text-wrap: balance; }
    .qg-cast__en::before {
        content: "EN"; display: inline-block; margin-right: calc(var(--u) * 1.6); padding: 0 calc(var(--u) * .9);
        border-radius: calc(var(--u) * .6); font-size: .6em; letter-spacing: .15em; vertical-align: .25em; box-shadow: inset 0 0 0 1px currentColor;
    }
    .qg-cast__en[hidden] { display: none; }

    /* bottom: who sent it, how long it runs, a bar that runs down to the end */
    .qg-cast__foot { display: grid; grid-template-columns: 1fr auto; align-items: end; gap: calc(var(--u) * 1.6) calc(var(--u) * 3); }
    .qg-cast__from { font-size: calc(var(--u) * 1.9); letter-spacing: .18em; text-transform: uppercase; color: var(--c-soft); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .qg-cast__left { font-size: calc(var(--u) * 2.6); font-weight: 600; font-variant-numeric: tabular-nums; }
    .qg-cast__bar { grid-column: 1 / -1; height: calc(var(--u) * .7); border-radius: 99px; background: color-mix(in oklab, var(--c-fg) 20%, transparent); overflow: hidden; }
    .qg-cast__bar i { display: block; height: 100%; background: var(--c-fg); transform-origin: left; transition: transform 1s linear; }
    .qg-cast__bar[hidden] { display: none; }

    /* alert: the screen breathes so it is noticed from across the foyer */
    .qg-cast[data-cat="alert"].show .qg-cast__bg::before { animation: qgCastBreath 1.6s ease-in-out infinite; }
    .qg-cast[data-cat="alert"].show .qg-cast__big { animation: qgCastRing 1.6s ease-in-out infinite; }
    @keyframes qgCastBreath { 50% { filter: brightness(1.35); } }
    @keyframes qgCastRing { 50% { transform: scale(1.08); } }
    .qg-cast.show .qg-cast__text { animation: qgCastIn .7s cubic-bezier(.2,.8,.2,1) both .15s; }
    @keyframes qgCastIn { from { opacity: 0; transform: translateY(calc(var(--u) * 3)); } }
    @media (prefers-reduced-motion: reduce) {
        .qg-cast, .qg-cast.show { transition: opacity .3s ease; transform: none; }
        .qg-cast *, .qg-cast__bg::before { animation: none !important; }
    }
</style>
<div class="qg-cast" id="qgCast" role="alert" aria-live="assertive" data-cat="info">
    <div class="qg-cast__bg" aria-hidden="true"><div class="qg-cast__tape t"></div><div class="qg-cast__tape b"></div></div>
    <div class="qg-cast__top">
        <span class="qg-cast__chip"><svg class="qg-cast__ico" id="qgCastChipIco" viewBox="0 0 24 24" aria-hidden="true"></svg><span id="qgCastKicker"></span></span>
        <span class="qg-cast__clock" id="qgCastClock"></span>
    </div>
    <div class="qg-cast__main">
        <div class="qg-cast__big" aria-hidden="true"><svg class="qg-cast__ico" id="qgCastBigIco" viewBox="0 0 24 24"></svg></div>
        <p class="qg-cast__text" id="qgCastText"></p>
        <p class="qg-cast__en" id="qgCastEn" lang="en" hidden></p>
    </div>
    <div class="qg-cast__foot">
        <span class="qg-cast__from" id="qgCastFrom">Durchsage</span>
        <span class="qg-cast__left" id="qgCastLeft"></span>
        <div class="qg-cast__bar" id="qgCastBar" hidden><i id="qgCastBarFill"></i></div>
    </div>
</div>
<script>
(function () {
    var CAT = {
        info:      { label: 'Information', icon: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>' },
        attention: { label: 'Achtung',     icon: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>' },
        alert:     { label: 'Dringend',    icon: '<path d="M8.7 2h6.6a2 2 0 0 1 1.4.6l4.7 4.7a2 2 0 0 1 .6 1.4v6.6a2 2 0 0 1-.6 1.4l-4.7 4.7a2 2 0 0 1-1.4.6H8.7a2 2 0 0 1-1.4-.6l-4.7-4.7a2 2 0 0 1-.6-1.4V8.7a2 2 0 0 1 .6-1.4l4.7-4.7A2 2 0 0 1 8.7 2Z"/><path d="M12 8v4"/><path d="M12 16h.01"/>' },
        success:   { label: 'Hinweis',     icon: '<path d="M21.8 10A10 10 0 1 1 17 3.3"/><path d="m9 11 3 3L22 4"/>' }
    };
    var $ = function (id) { return document.getElementById(id); };
    var el = $('qgCast');
    var url = '../api-proxy.php?endpoint=broadcast';
    var orga = <?php echo json_encode((string) ($GLOBALS['SCR']['orga'] ?? '')); ?>;
    var current = null, endsAt = null, total = null, tick = null;

    function pad(n) { return String(n).padStart(2, '0'); }
    function update() {
        var d = new Date();
        $('qgCastClock').textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
        if (endsAt === null) return;
        var left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        $('qgCastLeft').textContent = 'noch ' + Math.floor(left / 60) + ':' + pad(left % 60);
        $('qgCastBarFill').style.transform = 'scaleX(' + (total ? left / total : 0) + ')';
        if (left <= 0) hide();              // leave on time even if the network is gone
    }
    function hide() {
        el.classList.remove('show');
        current = null; endsAt = null; total = null;
        clearInterval(tick); tick = null;
    }
    function show(b) {
        if (!b || !CAT[b.category]) { if (current !== null) hide(); return; }
        if (b.id !== current) {
            // A new announcement: fill it in and replay the entry animation.
            var c = CAT[b.category], t = b.text || '';
            current = b.id;
            el.classList.remove('show'); void el.offsetWidth;
            el.dataset.cat = b.category;
            $('qgCastKicker').textContent = c.label;
            $('qgCastChipIco').innerHTML = c.icon;
            $('qgCastBigIco').innerHTML = c.icon;
            $('qgCastText').textContent = t;
            $('qgCastText').className = 'qg-cast__text' + (t.length > 90 ? ' is-s' : t.length > 45 ? ' is-m' : '');
            $('qgCastEn').textContent = b.text_en || '';
            $('qgCastEn').hidden = !b.text_en;
            $('qgCastFrom').textContent = (orga ? orga + ' · ' : '') + 'Durchsage';
            total = typeof b.expires_in === 'number' ? Math.max(1, b.expires_in) : null;
            el.classList.add('show');
        }
        // Every poll re-syncs the end with the server.
        endsAt = typeof b.expires_in === 'number' ? Date.now() + b.expires_in * 1000 : null;
        $('qgCastBar').hidden = endsAt === null;
        if (endsAt === null) $('qgCastLeft').textContent = 'bis auf Weiteres';
        if (!tick) tick = setInterval(update, 1000);
        update();
    }
    function poll() {
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.status === 'success') show(d.broadcast); })
            .catch(function () {})
            .then(function () { setTimeout(poll, 3000); });
    }
    poll();
})();
</script>
