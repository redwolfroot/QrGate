<?php
/**
 * Shared frame for the foyer screens (title, welcome, feedback).
 *
 *   require __DIR__ . '/_screen.php';
 *   scr_open('Titel', 'Jetzt');          // <html> … top bar, opens the stage
 *   … the screen's slides …
 *   scr_close(['langs' => true, 'progress' => 4]);
 *
 * Images go through PUBLIC_API_BASE (same origin behind nginx), never the
 * internal API_BASE_URL, which a screen's browser cannot reach.
 */
require_once __DIR__ . '/../config.php';

$show = getShows() ?: [];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

$SCR = [
    'orga'     => trim((string) ($show['orga_name'] ?? '')),
    'title'    => trim((string) ($show['title'] ?? '')),
    'subtitle' => trim((string) ($show['subtitle'] ?? '')),
    'logo'     => scr_image($show['logo'] ?? ''),
    'wall'     => scr_image($show['wallpaper'] ?? ''),
    'banner'   => scr_image($show['banner'] ?? ''),
    'next'     => scr_next_date($show),
    'screens'  => is_array($show['screens'] ?? null) ? $show['screens'] : [],
];

function scr_image($file): string
{
    $file = basename((string) $file);
    return $file === '' ? '' : PUBLIC_API_BASE . '/api/image/get/' . rawurlencode($file);
}

/** Today's date, else the next upcoming one: [date, time, venue, is_today] or null. */
function scr_next_date(array $show): ?array
{
    $tz = new DateTimeZone('Europe/Berlin');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $best = null;
    foreach (($show['dates'] ?? []) as $d) {
        $date = (string) ($d['date'] ?? '');
        if ($date === '' || $date < $today) continue;
        if ($best === null || $date . ($d['time'] ?? '') < $best['date'] . ($best['time'] ?? '')) {
            $best = $d;
        }
    }
    if ($best === null) return null;
    $loc = $show['locations'][$best['location'] ?? ''] ?? null;
    return [
        'date'  => (string) $best['date'],
        'time'  => (string) ($best['time'] ?? ''),
        'venue' => is_array($loc) ? trim((string) ($loc['name'] ?? '')) : '',
        'today' => $best['date'] === $today,
    ];
}

/** "Sa, 26.09." / "Sat, 26 Sep" */
function scr_day(string $iso, string $lang = 'de'): string
{
    $t = strtotime($iso);
    if (!$t) return $iso;
    $wd = $lang === 'de' ? ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return $wd[(int) date('w', $t)] . ', ' . ($lang === 'de' ? date('d.m.', $t) : date('j M', $t));
}

/** Size class for a line of text so long sentences still fit the stage. */
function scr_size(string $text, int $m = 40, int $s = 90): string
{
    $n = mb_strlen(trim(strip_tags($text)));
    return $n > $s ? 'is-s' : ($n > $m ? 'is-m' : '');
}

/** $kicker: a string, or ['de' => …, 'en' => …] when the screen alternates. */
function scr_open(string $pageTitle, $kicker, string $extraHead = ''): void
{
    global $SCR, $h;
    $k = is_array($kicker) ? $kicker : ['de' => $kicker, 'en' => $kicker];
    $assetBase = '../';
    $forceDark = true;
    $faviconUrl = $SCR['logo'] ?: null;
    if ($faviconUrl === null) unset($faviconUrl);
    $extraHead = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&display=swap">'
        . '<link rel="stylesheet" href="screens.css?v=1">' . $extraHead;
    ?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="scr-body">
<div class="scr"<?php if ($SCR['wall']): ?> style="--scr-wall: url('<?php echo $h($SCR['wall']); ?>')"<?php endif; ?>>
    <div class="scr-bg" aria-hidden="true"></div>
    <header class="scr-top">
        <?php if ($SCR['logo']): ?><img class="scr-logo" src="<?php echo $h($SCR['logo']); ?>" alt="" onerror="this.hidden=true"><?php endif; ?>
        <div class="scr-org">
            <span class="scr-kicker" id="scrKicker" data-de="<?php echo $h($k['de']); ?>" data-en="<?php echo $h($k['en']); ?>"><?php echo $h($k['de']); ?></span>
            <span class="scr-org__name"><?php echo $h($SCR['orga'] ?: 'QrGate'); ?></span>
        </div>
        <span class="scr-sp"></span>
        <div class="scr-clock"><b id="scrTime">--:--</b><span id="scrDate">&nbsp;</span></div>
    </header>
    <main class="scr-stage" id="scrStage">
    <?php
}

/**
 * Closes the stage and prints the bottom bar.
 *   langs    bool  show the DE/EN chips (screen alternates languages)
 *   progress int   number of progress segments (0 = none)
 */
function scr_close(array $opt = []): void
{
    $langs = !empty($opt['langs']);
    $progress = (int) ($opt['progress'] ?? 0);
    ?>
    </main>
    <footer class="scr-foot">
        <div class="scr-lang" id="scrLang"<?php if (!$langs): ?> hidden<?php endif; ?>><span data-lang="de">DE</span><span data-lang="en">EN</span></div>
        <div class="scr-progress" id="scrProgress" aria-hidden="true"><?php echo str_repeat('<i></i>', max(0, $progress)); ?></div>
        <div class="scr-brand">Powered by
            <svg class="scr-brand__mark" viewBox="0 0 72 72" fill="none" aria-hidden="true"><path d="M22 18 L12 18 L12 54 L22 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M50 18 L60 18 L60 54 L50 54" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M26 30 L33 36 L26 42" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><rect x="40" y="28" width="4" height="16" rx="2" fill="#FF6B4A"/></svg>
            <b>avocloud<span>.net</span></b>
        </div>
    </footer>
</div>
<script>
/* Shared screen runtime: clock, language chips, progress segments, and a
   slide rotator the screens drive with SCR.rotate(). */
window.SCR = (function () {
    var WD = { de: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
               en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] };
    var lang = 'de';
    function clock() {
        var d = new Date();
        document.getElementById('scrTime').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        document.getElementById('scrDate').textContent = WD[lang][d.getDay()] + ' · ' +
            String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.';
    }
    function setLang(l) {
        lang = l;
        document.documentElement.lang = l;
        document.querySelectorAll('#scrLang span').forEach(function (s) { s.classList.toggle('is-on', s.dataset.lang === l); });
        document.querySelectorAll('[data-de]').forEach(function (el) {
            var v = el.getAttribute('data-' + l);
            if (v !== null) el.innerHTML = v;
        });
        clock();
    }
    function progress(i, dur) {
        var segs = document.querySelectorAll('#scrProgress i');
        segs.forEach(function (s, k) {
            s.classList.remove('is-run');
            s.classList.toggle('is-done', k < i);
        });
        if (segs[i]) {
            void segs[i].offsetWidth;                  // restart the fill animation
            segs[i].style.setProperty('--dur', dur + 'ms');
            segs[i].classList.add('is-run');
        }
    }
    /* Show slides one after another; `langs` alternates DE/EN per round. */
    function rotate(slides, opt) {
        opt = opt || {};
        var dur = opt.duration || 10000, langs = opt.langs || ['de'], round = 0, i = -1;
        var stage = document.getElementById('scrStage');
        function show() {
            if (i === 0) { setLang(langs[round % langs.length]); round++; }
            slides.forEach(function (s, k) { s.classList.toggle('is-on', k === i); });
            progress(i, dur);
            if (slides.length > 1 || langs.length > 1) setTimeout(next, dur);
        }
        function next() {
            i = (i + 1) % slides.length;
            // A single slide only changes language: fade the stage through it.
            if (slides.length === 1 && round > 0) {
                stage.classList.add('is-fading');
                setTimeout(function () { show(); stage.classList.remove('is-fading'); }, 600);
            } else show();
        }
        next();
    }
    setInterval(clock, 1000 * 15);
    clock();
    return { rotate: rotate, setLang: setLang, lang: function () { return lang; } };
})();
</script>
<?php include __DIR__ . '/_broadcast.php'; ?>
</body>
</html>
    <?php
}
