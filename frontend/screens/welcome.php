<?php
/**
 * Welcome screen: the slides from the admin (Screens), one after another,
 * 10 s each. Placeholders {orga_name}, {show_title}, {show_subtitle}. A slide
 * with cast members shows their portraits, six per slide at most. With
 * language mode "both" every round alternates German and English.
 */
require __DIR__ . '/_screen.php';

$cfg = $SCR['screens'];
$languageMode = in_array($cfg['language_mode'] ?? 'both', ['both', 'de', 'en'], true) ? ($cfg['language_mode'] ?? 'both') : 'both';
$langs = $languageMode === 'both' ? ['de', 'en'] : [$languageMode];

$defaultSlides = [
    ['icon' => 'fa-smile', 'icon_animation' => 'laugh 0.5s infinite',
     'text_en' => "Welcome to\n{orga_name}", 'text_de' => "Willkommen bei der\n{orga_name}"],
    ['icon' => 'fa-theater-masks', 'icon_animation' => 'bounce 1s infinite',
     'text_en' => "{show_title}\n{show_subtitle}", 'text_de' => "{show_title}\n{show_subtitle}"],
    ['icon' => 'fa-heart', 'icon_animation' => 'pulse 1s infinite',
     'text_en' => 'We are so happy to see you here!', 'text_de' => 'Wir freuen uns sehr, dich hier zu sehen!'],
    ['icon' => 'fa-ticket', 'icon_animation' => 'wobble 1s infinite',
     'text_en' => "To ensure a quick and smooth check-in,\nplease have your ticket ready before entering.",
     'text_de' => "Um einen zügigen Check-in zu ermöglichen,\nhalte bitte dein Ticket vor dem Einlass bereit."],
];
$configSlides = !empty($cfg['slides']) && is_array($cfg['slides']) ? $cfg['slides'] : $defaultSlides;

/** Slide text as safe HTML: placeholders filled, the organiser in coral, a
 *  line that is exactly the subtitle set smaller. Returns [html, plain]. */
function welcome_text(string $raw, array $SCR): array
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $plain = strtr($raw, ['{orga_name}' => $SCR['orga'], '{show_title}' => $SCR['title'], '{show_subtitle}' => $SCR['subtitle']]);
    $lines = [];
    foreach (preg_split('/\r\n|\n|\r/', $plain) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if ($SCR['subtitle'] !== '' && $line === $SCR['subtitle'] && $SCR['subtitle'] !== $SCR['title']) {
            $lines[] = '<span class="sub">' . $h($line) . '</span>';
            continue;
        }
        $html = $h($line);
        if ($SCR['orga'] !== '') {
            $html = str_replace($h($SCR['orga']), '<span class="hl">' . $h($SCR['orga']) . '</span>', $html);
        }
        $lines[] = $html;
    }
    return [implode('<br>', $lines), $plain];
}

// Expand the configured slides; cast slides split into chunks of six.
$slides = [];
foreach ($configSlides as $s) {
    if (!is_array($s)) continue;
    [$de, $dePlain] = welcome_text((string) ($s['text_de'] ?? ''), $SCR);
    [$en, $enPlain] = welcome_text((string) ($s['text_en'] ?? ''), $SCR);
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($s['icon'] ?? '')) ? $s['icon'] : 'fa-star';
    $anim = (string) ($s['icon_animation'] ?? 'none');
    $anim = preg_match('/^(pulse|bounce|wobble|laugh) [0-9.]+s infinite$/', $anim) ? $anim : '';
    $base = ['icon' => $icon, 'anim' => $anim, 'de' => $de, 'en' => $en,
             'size' => scr_size(mb_strlen($dePlain) > mb_strlen($enPlain) ? $dePlain : $enPlain, 32, 70)];
    $cast = array_values(array_filter($s['cast'] ?? [], 'is_array'));
    if (!$cast) {
        $slides[] = $base + ['cast' => []];
        continue;
    }
    foreach (array_chunk($cast, 6) as $chunk) {
        $slides[] = $base + ['cast' => $chunk];
    }
}
$castBase = PUBLIC_API_BASE . '/api/show/cast/image/';
$first = $langs[0];

scr_open(($SCR['title'] ?: 'QrGate') . ' · Willkommen', ['de' => 'Willkommen', 'en' => 'Welcome'],
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">');
?>
<style>
    .wl-cast { width: 100%; display: flex; flex-direction: column; align-items: center; gap: calc(var(--u) * 4); }
    .wl-cast__head { display: flex; align-items: center; gap: calc(var(--u) * 2.4); }
    .wl-cast__head .scr-icon { width: calc(var(--u) * 8); height: calc(var(--u) * 8); margin: 0; font-size: calc(var(--u) * 3.6); }
    .wl-cast__head .scr-text { font-size: calc(var(--u) * 5.2); max-width: none; }
    .wl-grid { --n: 3; --p: calc(var(--u) * 20); display: grid; grid-template-columns: repeat(var(--n), var(--p)); gap: calc(var(--u) * 3) calc(var(--u) * 5); justify-content: center; }
    .wl-grid[data-count="1"] { --n: 1; --p: calc(var(--u) * 40); }
    .wl-grid[data-count="2"] { --n: 2; --p: calc(var(--u) * 36); }
    .wl-grid[data-count="3"] { --n: 3; --p: calc(var(--u) * 32); }
    .wl-grid[data-count="4"] { --n: 4; --p: calc(var(--u) * 28); }
    .wl-person { display: flex; flex-direction: column; align-items: center; gap: calc(var(--u) * 1.2); min-width: 0; animation: wlFloat 7s ease-in-out infinite; }
    .wl-person:nth-child(2n) { animation-duration: 8s; animation-delay: -2s; }
    .wl-person:nth-child(3n) { animation-duration: 6.5s; animation-delay: -4s; }
    .wl-person__img {
        width: var(--p); aspect-ratio: 1; border-radius: calc(var(--u) * 2.2); object-fit: cover;
        background: color-mix(in oklab, var(--avo-text) 6%, transparent);
        box-shadow: 0 0 0 calc(var(--u) * .35) color-mix(in oklab, var(--coral) 70%, transparent), 0 calc(var(--u) * 3) calc(var(--u) * 8) rgba(0,0,0,.55);
        display: grid; place-items: center; color: var(--muted); font-size: calc(var(--p) * .3);
    }
    .wl-person__name { font-size: calc(var(--u) * 2.9); font-weight: 600; line-height: 1.15; text-align: center; max-width: calc(var(--p) + var(--u) * 4); overflow-wrap: anywhere; }
    .wl-person__role { font-size: calc(var(--u) * 2); color: var(--coral); letter-spacing: .06em; text-align: center; }
    @keyframes wlFloat { 50% { transform: translateY(calc(var(--u) * -1.2)); } }
    @media (prefers-reduced-motion: reduce) { .wl-person, .scr-icon i { animation: none !important; } }
</style>
<?php foreach ($slides as $i => $s):
    $iconHtml = '<div class="scr-icon"><i class="fas ' . $h($s['icon']) . '"' . ($s['anim'] ? ' style="animation: ' . $h($s['anim']) . '"' : '') . '></i></div>';
    $text = $s[$first]; ?>
<section class="scr-slide<?php echo $i === 0 ? ' is-on' : ''; ?>">
    <?php if (!$s['cast']): ?>
        <?php echo $iconHtml; ?>
        <h1 class="scr-text <?php echo $s['size']; ?>" data-de="<?php echo $h($s['de']); ?>" data-en="<?php echo $h($s['en']); ?>"><?php echo $text; ?></h1>
    <?php else: ?>
        <div class="wl-cast">
            <div class="wl-cast__head"><?php echo $iconHtml; ?>
                <h1 class="scr-text" data-de="<?php echo $h($s['de']); ?>" data-en="<?php echo $h($s['en']); ?>"><?php echo $text; ?></h1></div>
            <div class="wl-grid" data-count="<?php echo count($s['cast']); ?>">
                <?php foreach ($s['cast'] as $m): ?>
                <div class="wl-person">
                    <?php if (!empty($m['image'])): ?>
                    <img class="wl-person__img" src="<?php echo $h($castBase . rawurlencode(basename((string) $m['image']))); ?>" alt="">
                    <?php else: ?>
                    <div class="wl-person__img"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="wl-person__name"><?php echo $h($m['name'] ?? ''); ?></div>
                    <?php if (!empty($m['role'])): ?><div class="wl-person__role"><?php echo $h($m['role']); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        SCR.rotate(Array.prototype.slice.call(document.querySelectorAll('.scr-slide')),
            { duration: 10000, langs: <?php echo json_encode($langs); ?> });
    });
</script>
<?php scr_close(['langs' => count($langs) > 1, 'progress' => count($slides)]); ?>
