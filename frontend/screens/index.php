<?php
/**
 * Screen selector: pick what this display shows. Opened once when a TV or
 * projector is set up, then the chosen screen runs full screen (F11).
 */
require_once __DIR__ . '/../config.php';

$show = getShows() ?: [];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$orga = trim((string) ($show['orga_name'] ?? ''));
$title = trim((string) ($show['title'] ?? ''));

$screens = [
    ['welcome.php', 'Willkommen', 'Folien und Ensemble für das Foyer vor der Vorstellung. Inhalte im Admin unter „Screens“.',
     '<path d="M3 20h18"/><path d="M5 20V9l7-5 7 5v11"/><path d="M10 20v-6h4v6"/>'],
    ['title.php', 'Titel', 'Das Stück als Plakat: Titel, Untertitel, Banner und der heutige Termin.',
     '<rect width="18" height="14" x="3" y="3" rx="2"/><path d="M7 21h10"/><path d="M12 17v4"/><path d="M7 8h10"/><path d="M7 12h6"/>'],
    ['feedback.php', 'Feedback', 'Nach der Vorstellung: großer QR-Code zur Publikumsbewertung.',
     '<path d="M11.5 2.5 14 8l6 .5-4.5 4 1.4 6-5.4-3.3L6.1 18.5l1.4-6L3 8.5 9 8Z"/>'],
    ['live.php', 'Live-Dashboard', 'Für Backstage und Leitung: Einlass, Restplätze, Kasse, Geräte, Durchsagen. Admin-Anmeldung oder Link aus dem Admin.',
     '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
];

$pageTitle = 'Screens' . ($orga !== '' ? ' · ' . $orga : '');
$assetBase = '../';
$forceDark = true;
$extraHead = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&display=swap">'
    . '<link rel="stylesheet" href="screens.css?v=1">';
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="sel-body">
<style>
    .sel-body { margin: 0; min-height: 100vh; background: #070708; color: var(--avo-text); font-family: var(--avo-font-mono); }
    .sel { position: relative; isolation: isolate; min-height: 100vh; box-sizing: border-box; padding: clamp(24px, 6vw, 88px) clamp(16px, 6vw, 96px); display: flex; flex-direction: column; gap: clamp(24px, 4vw, 48px); }
    .sel .scr-bg { position: fixed; animation: none; }
    .sel-head h1 { margin: 10px 0 8px; font-family: 'Syne', var(--avo-font-display); font-weight: 800; font-size: clamp(2.2rem, 5vw, 4.4rem); line-height: 1; letter-spacing: -.02em; }
    .sel-head p { margin: 0; color: color-mix(in oklab, var(--avo-text) 62%, transparent); font-size: clamp(.9rem, 1.3vw, 1.1rem); max-width: 60ch; line-height: 1.5; }
    .sel-kicker { font-size: .8rem; font-weight: 600; letter-spacing: .22em; text-transform: uppercase; color: var(--avo-primary); }
    .sel-kicker::before { content: "// "; opacity: .7; }
    .sel-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: clamp(12px, 2vw, 24px); }
    .sel-card {
        position: relative; display: flex; flex-direction: column; gap: 14px; padding: 26px;
        border-radius: 16px; text-decoration: none; color: inherit;
        background: color-mix(in oklab, #fff 4%, transparent); backdrop-filter: blur(12px);
        box-shadow: inset 0 0 0 1px color-mix(in oklab, #fff 12%, transparent);
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .sel-card:hover, .sel-card:focus-visible { outline: none; transform: translateY(-3px); background: color-mix(in oklab, var(--avo-primary) 10%, transparent); box-shadow: inset 0 0 0 2px var(--avo-primary); }
    .sel-ico { display: grid; place-items: center; width: 52px; height: 52px; border-radius: 14px; color: var(--avo-primary); background: color-mix(in oklab, var(--avo-primary) 14%, transparent); }
    .sel-ico .sel-ico__svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .sel-card h2 { margin: 0; font-size: 1.35rem; font-weight: 600; }
    .sel-card p { margin: 0; font-size: .9rem; line-height: 1.5; color: color-mix(in oklab, var(--avo-text) 62%, transparent); flex: 1; }
    .sel-go { font-size: .75rem; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: var(--avo-primary); }
    .sel-go::after { content: " →"; }
    .sel-tip { font-size: .8rem; color: color-mix(in oklab, var(--avo-text) 50%, transparent); letter-spacing: .06em; }
    .sel-tip kbd { font-family: inherit; padding: 2px 7px; border-radius: 6px; box-shadow: inset 0 0 0 1px color-mix(in oklab, #fff 20%, transparent); color: var(--avo-text); }
</style>
<div class="sel">
    <div class="scr-bg" aria-hidden="true"></div>
    <header class="sel-head">
        <div class="sel-kicker"><?php echo $h($orga !== '' ? $orga : 'QrGate'); ?> · Screens</div>
        <h1>Was soll dieser Bildschirm zeigen?</h1>
        <p><?php echo $title !== '' ? $h($title) . '. ' : ''; ?>Screen wählen, dann mit F11 in den Vollbildmodus. Durchsagen aus dem Admin erscheinen auf allen Screens automatisch.</p>
    </header>
    <nav class="sel-grid" aria-label="Screens">
        <?php foreach ($screens as [$href, $name, $desc, $icon]): ?>
        <a class="sel-card" href="<?php echo $h($href); ?>">
            <span class="sel-ico"><svg class="sel-ico__svg" viewBox="0 0 24 24" aria-hidden="true"><?php echo $icon; ?></svg></span>
            <h2><?php echo $h($name); ?></h2>
            <p><?php echo $h($desc); ?></p>
            <span class="sel-go">Öffnen</span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="sel-tip">Tipp: <kbd>F11</kbd> Vollbild · Mauszeiger verschwindet auf den Screens von selbst · Zurück mit <kbd>Alt</kbd> + <kbd>←</kbd></div>
</div>
</body>
</html>
