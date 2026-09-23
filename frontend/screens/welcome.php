<?php
require_once '../config.php';

$shows = getShows();

if ($shows && isset($shows['orga_name'], $shows['title'])) {
    $orgaName = htmlspecialchars($shows['orga_name']);
    $showTitle = htmlspecialchars($shows['title']);
    $showSubtitle = htmlspecialchars($shows['subtitle']);
    $wallpaper_url = API_BASE_URL . "api/show/get/wallpaper";
    $logo_url = API_BASE_URL . "api/show/get/logo";
} else {
    $orgaName = "Error loading show data";
    $showTitle = "Error loading show title";
    $showSubtitle = "Error loading show subtitle";
    $wallpaper_url = "";
    $logo_url = "";
}

$screens = null;
if ($shows && isset($shows['screens'])) {
    $screens = $shows['screens'];
}

$languageMode = $screens ? ($screens['language_mode'] ?? 'both') : 'both';

$defaultSlides = [
    ['id' => 'slide_1', 'icon' => 'fa-smile', 'icon_animation' => 'laugh 0.5s infinite',
     'text_en' => "Welcome to\n{orga_name}", 'text_de' => "Willkommen bei der\n{orga_name}", 'cast' => []],
    ['id' => 'slide_2', 'icon' => 'fa-theater-masks', 'icon_animation' => 'bounce 1s infinite',
     'text_en' => "{show_title}\n{show_subtitle}", 'text_de' => "{show_title}\n{show_subtitle}", 'cast' => []],
    ['id' => 'slide_3', 'icon' => 'fa-heart', 'icon_animation' => 'pulse 1s infinite',
     'text_en' => 'We are so happy to see you here!', 'text_de' => 'Wir freuen uns sehr, dich hier zu sehen!', 'cast' => []],
    ['id' => 'slide_4', 'icon' => 'fa-ticket', 'icon_animation' => 'wobble 1s infinite',
     'text_en' => "To ensure a quick and smooth check-in,\nplease have your ticket ready before entering.",
     'text_de' => "Um einen zügigen Check-in zu ermöglichen,\nhalte bitte dein Ticket vor dem Einlass bereit.", 'cast' => []]
];

$configSlides = ($screens && !empty($screens['slides'])) ? $screens['slides'] : $defaultSlides;

function replacePlaceholders($text, $orgaName, $showTitle, $showSubtitle) {
    $text = str_replace('{orga_name}', $orgaName, $text);
    $text = str_replace('{show_title}', $showTitle, $text);
    $text = str_replace('{show_subtitle}', $showSubtitle, $text);
    return $text;
}

function renderSlideText($text, $orgaName, $showTitle, $showSubtitle) {
    $text = replacePlaceholders($text, $orgaName, $showTitle, $showSubtitle);
    $lines = explode("\n", $text);
    $html = '';
    foreach ($lines as $i => $line) {
        if ($i > 0) $html .= '<br>';
        if (strpos($line, $orgaName) !== false && $orgaName !== '') {
            $line = str_replace($orgaName, '<span class="orga-name-span">' . $orgaName . '</span>', $line);
        }
        if (strpos($line, $showSubtitle) !== false && $showSubtitle !== '' && $line === $showSubtitle) {
            $line = '<span class="show-subtitle">' . $line . '</span>';
        }
        $html .= $line;
    }
    return $html;
}

$castImageBase = PUBLIC_API_BASE . '/api/show/cast/image/';

$CAST_PER_SLIDE = 6;
$finalSlides = [];

foreach ($configSlides as $slide) {
    $cast = $slide['cast'] ?? [];
    if (empty($cast)) {
        $finalSlides[] = [
            'type' => 'text',
            'icon' => $slide['icon'] ?? 'fa-star',
            'icon_animation' => $slide['icon_animation'] ?? 'none',
            'text_en' => renderSlideText($slide['text_en'] ?? '', $orgaName, $showTitle, $showSubtitle),
            'text_de' => renderSlideText($slide['text_de'] ?? '', $orgaName, $showTitle, $showSubtitle),
        ];
    } else {
        $castChunks = array_chunk($cast, $CAST_PER_SLIDE);
        foreach ($castChunks as $chunk) {
            $finalSlides[] = [
                'type' => 'cast',
                'icon' => $slide['icon'] ?? 'fa-users',
                'icon_animation' => $slide['icon_animation'] ?? 'pulse 1s infinite',
                'text_en' => renderSlideText($slide['text_en'] ?? '', $orgaName, $showTitle, $showSubtitle),
                'text_de' => renderSlideText($slide['text_de'] ?? '', $orgaName, $showTitle, $showSubtitle),
                'cast' => $chunk,
            ];
        }
    }
}
?>
<?php
$pageTitle = $showTitle . ' — Welcome';
$assetBase = '../';
$forceDark = true;
$extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" async>';
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body>
<style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            height: 100vh; margin: 0;
            line-height: 1.6; overflow: hidden;
            background-size: 31px 31px;
            background-image: repeating-linear-gradient(45deg, var(--avo-surface) 0, var(--avo-surface) 3.1px, var(--avo-bg) 0, var(--avo-bg) 50%);
            background-attachment: fixed;
        }

        .orga-name-span {
            padding: 2px 8px; background-color: color-mix(in oklab, var(--avo-primary) 16%, transparent);
            border-radius: 4px; color: var(--avo-primary);
        }

        #gradientbar {
            height: 20px; width: 100%;
            position: fixed; top: 0; z-index: 2;
            background: linear-gradient(90deg, var(--avo-coral-700), var(--avo-coral-500), var(--avo-coral-300), var(--avo-coral-500), var(--avo-coral-700));
            background-size: 300% 100%;
            animation: avo-bar 8s ease infinite;
        }
        #progressbar {
            height: 20px; background-color: var(--avo-primary); border-radius: 0 0 10px 10px;
            position: fixed; top: 0; left: 0; width: 0; z-index: 3;
            transition: none;
        }

        @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }

        .welcome-text {
            position: relative; width: 100%; text-align: center; margin: 0;
            font-size: 5em; color: var(--avo-text); animation: fadeIn 2s ease forwards;
            font-family: var(--avo-font-display);
        }
        .welcome-text i.slide-icon { margin-right: 10px; color: var(--avo-primary); font-size: 1.2em; }
        .show-subtitle { font-size: 0.5em; color: var(--avo-text-muted); }

        .language-switcher {
            position: absolute; top: 20px; left: 50%; transform: translateX(-50%);
            display: flex; gap: 10px; text-align: center;
        }
        .inactive { opacity: 0.5; font-size: 1.5em; }
        .active   { opacity: 1;   font-size: 2em; }

        footer {
            position: fixed; bottom: 0; width: 100%; text-align: center;
            display: flex; flex-direction: column; align-items: center;
            padding: 10px; color: var(--avo-text-muted); font-size: 1.3em;
        }
        .logo {
            bottom: 0; right: 0; margin: 30px; position: fixed; z-index: 2;
        }
        .logo img { width: 11vh; height: 11vh; border-radius: 12px; border: 5px solid transparent; }

        .wallpaper-div {
            background-image: linear-gradient(rgba(0,0,0,0.8), rgba(0,0,0,0.7)), url(<?php echo $wallpaper_url; ?>);
            width: 100%; height: 100%; background-size: cover;
            position: absolute; z-index: -2; opacity: .7;
        }

        /* Icon animations */
        @keyframes pulse {
            0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); }
        }
        @keyframes bounce {
            0%,20%,50%,80%,100% { transform: translateY(0); }
            40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); }
        }
        @keyframes wobble {
            0% { transform: rotate(0deg); } 25% { transform: rotate(5deg); }
            50% { transform: rotate(-5deg); } 75% { transform: rotate(5deg); } 100% { transform: rotate(0deg); }
        }
        @keyframes laugh {
            0% { transform: translateY(0); } 25% { transform: translateY(-5px); }
            50% { transform: translateY(0); } 75% { transform: translateY(5px); } 100% { transform: translateY(0); }
        }

        /* ---- Floating ---- */
        @keyframes float-1 {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-12px); }
        }
        @keyframes float-2 {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-16px); }
        }
        @keyframes float-3 {
            0%, 100% { transform: translateY(0); }
            40%      { transform: translateY(-10px); }
            70%      { transform: translateY(-18px); }
        }
        @keyframes float-4 {
            0%, 100% { transform: translateY(0); }
            35%      { transform: translateY(-14px); }
            65%      { transform: translateY(-8px); }
        }
        @keyframes float-5 {
            0%, 100% { transform: translateY(0); }
            45%      { transform: translateY(-11px); }
        }
        @keyframes float-6 {
            0%, 100% { transform: translateY(0); }
            30%      { transform: translateY(-9px); }
            60%      { transform: translateY(-15px); }
        }

        /* ---- Cast slide ---- */
        .cast-slide {
            width: 100vw; height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .cast-heading {
            font-size: 3.5em; font-weight: 600; color: var(--avo-text);
            font-family: var(--avo-font-display);
            text-align: center; margin-bottom: 1rem; letter-spacing: 0.05em;
        }
        .cast-heading i.slide-icon { margin-right: 12px; font-size: 0.85em; color: var(--avo-primary); }

        .cast-stage {
            position: relative; width: 90vw; max-height: 68vh; flex: 1;
        }

        .cast-card {
            position: absolute;
            display: flex; flex-direction: column; align-items: center; gap: 1rem;
            will-change: transform;
        }
        .cast-card:nth-child(1) { animation: float-1 6s   ease-in-out infinite; }
        .cast-card:nth-child(2) { animation: float-2 7s   ease-in-out infinite; animation-delay: 1s; }
        .cast-card:nth-child(3) { animation: float-3 7.5s ease-in-out infinite; animation-delay: 2s; }
        .cast-card:nth-child(4) { animation: float-4 6.5s ease-in-out infinite; animation-delay: 0.5s; }
        .cast-card:nth-child(5) { animation: float-5 7.2s ease-in-out infinite; animation-delay: 1.5s; }
        .cast-card:nth-child(6) { animation: float-6 6.8s ease-in-out infinite; animation-delay: 0.8s; }

        .cast-img {
            width: 22vh; height: 22vh; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--avo-border);
            box-shadow: 0 8px 40px rgba(0,0,0,0.5);
        }
        .cast-placeholder-circle {
            width: 22vh; height: 22vh; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--avo-surface);
            border: 3px solid var(--avo-border);
            box-shadow: 0 8px 40px rgba(0,0,0,0.5);
        }
        .cast-placeholder-circle i { font-size: 5vh; color: var(--avo-text-muted); animation: none !important; }

        .cast-name {
            font-size: 2.8vh; font-weight: 700; color: var(--avo-text);
            text-align: center; white-space: nowrap;
        }
        .cast-role {
            font-size: 2.2vh; color: var(--avo-text-muted); font-style: italic;
            text-align: center; margin-top: -0.3rem;
        }

        /* ---- 1 member: large, center ---- */
        .cast-stage[data-count="1"] .cast-card {
            left: 50%; top: 42%; transform: translate(-50%, -50%);
        }
        .cast-stage[data-count="1"] .cast-img,
        .cast-stage[data-count="1"] .cast-placeholder-circle { width: 32vh; height: 32vh; }
        .cast-stage[data-count="1"] .cast-name { font-size: 4vh; }
        .cast-stage[data-count="1"] .cast-role { font-size: 2.8vh; }

        /* ---- 2 members: left / right, offset ---- */
        .cast-stage[data-count="2"] .cast-card:nth-child(1) {
            left: 22%; top: 36%; transform: translateX(-50%);
        }
        .cast-stage[data-count="2"] .cast-card:nth-child(2) {
            left: auto; right: 22%; top: 42%; transform: translateX(50%);
        }
        .cast-stage[data-count="2"] .cast-img,
        .cast-stage[data-count="2"] .cast-placeholder-circle { width: 26vh; height: 26vh; }
        .cast-stage[data-count="2"] .cast-name { font-size: 3.2vh; }
        .cast-stage[data-count="2"] .cast-role { font-size: 2.4vh; }

        /* ---- 3 members: triangle ---- */
        .cast-stage[data-count="3"] .cast-card:nth-child(1) {
            left: 50%; top: 8%; transform: translateX(-50%);
        }
        .cast-stage[data-count="3"] .cast-card:nth-child(2) {
            left: 10%; top: 50%;
        }
        .cast-stage[data-count="3"] .cast-card:nth-child(3) {
            left: auto; right: 10%; top: 46%;
        }

        /* ---- 4 members: staggered ---- */
        .cast-stage[data-count="4"] .cast-card:nth-child(1) {
            left: 8%; top: 18%;
        }
        .cast-stage[data-count="4"] .cast-card:nth-child(2) {
            left: auto; right: 10%; top: 12%;
        }
        .cast-stage[data-count="4"] .cast-card:nth-child(3) {
            left: 18%; top: 56%;
        }
        .cast-stage[data-count="4"] .cast-card:nth-child(4) {
            left: auto; right: 14%; top: 52%;
        }
        .cast-stage[data-count="4"] .cast-img,
        .cast-stage[data-count="4"] .cast-placeholder-circle { width: 18vh; height: 18vh; }
        .cast-stage[data-count="4"] .cast-name { font-size: 2.4vh; }
        .cast-stage[data-count="4"] .cast-role { font-size: 1.8vh; }

        /* ---- 5 members: pentagon (1 top, 2 mid, 2 bottom) ---- */
        .cast-stage[data-count="5"] .cast-card:nth-child(1) {
            left: 50%; top: 2%; transform: translateX(-50%);
        }
        .cast-stage[data-count="5"] .cast-card:nth-child(2) {
            left: 8%; top: 30%;
        }
        .cast-stage[data-count="5"] .cast-card:nth-child(3) {
            left: auto; right: 8%; top: 30%;
        }
        .cast-stage[data-count="5"] .cast-card:nth-child(4) {
            left: 18%; top: 62%;
        }
        .cast-stage[data-count="5"] .cast-card:nth-child(5) {
            left: auto; right: 18%; top: 62%;
        }
        .cast-stage[data-count="5"] .cast-img,
        .cast-stage[data-count="5"] .cast-placeholder-circle { width: 16vh; height: 16vh; }
        .cast-stage[data-count="5"] .cast-name { font-size: 2.2vh; }
        .cast-stage[data-count="5"] .cast-role { font-size: 1.7vh; }

        /* ---- 6 members: 2 rows of 3 ---- */
        .cast-stage[data-count="6"] .cast-card:nth-child(1) {
            left: 8%; top: 6%;
        }
        .cast-stage[data-count="6"] .cast-card:nth-child(2) {
            left: 50%; top: 2%; transform: translateX(-50%);
        }
        .cast-stage[data-count="6"] .cast-card:nth-child(3) {
            left: auto; right: 8%; top: 6%;
        }
        .cast-stage[data-count="6"] .cast-card:nth-child(4) {
            left: 8%; top: 54%;
        }
        .cast-stage[data-count="6"] .cast-card:nth-child(5) {
            left: 50%; top: 54%; transform: translateX(-50%);
        }
        .cast-stage[data-count="6"] .cast-card:nth-child(6) {
            left: auto; right: 8%; top: 54%;
        }
        .cast-stage[data-count="6"] .cast-img,
        .cast-stage[data-count="6"] .cast-placeholder-circle { width: 15vh; height: 15vh; }
        .cast-stage[data-count="6"] .cast-name { font-size: 2vh; }
        .cast-stage[data-count="6"] .cast-role { font-size: 1.6vh; }

        /* ---- Mobile ---- */
        @media (max-width: 600px) {
            .welcome-text { font-size: 3em; }
            .cast-heading { font-size: 2em; }
            .cast-img, .cast-placeholder-circle { width: 14vh !important; height: 14vh !important; }
            .cast-name { font-size: 2vh !important; }
            .cast-role { font-size: 1.6vh !important; }
            .cast-stage[data-count="2"] .cast-card:nth-child(1) { left: 15%; }
            .cast-stage[data-count="2"] .cast-card:nth-child(2) { right: 15%; }
            .cast-stage[data-count="3"] .cast-card:nth-child(2) { left: 5%; }
            .cast-stage[data-count="3"] .cast-card:nth-child(3) { right: 5%; }
            .cast-stage[data-count="4"] .cast-card:nth-child(1) { left: 3%; }
            .cast-stage[data-count="4"] .cast-card:nth-child(2) { right: 3%; }
            .cast-stage[data-count="4"] .cast-card:nth-child(3) { left: 8%; }
            .cast-stage[data-count="4"] .cast-card:nth-child(4) { right: 8%; }
        }
    </style>
    <div id="gradientbar"></div>
    <div id="progressbar"></div>
    <div class="wallpaper-div"></div>
    <div class="logo"><img src="<?php echo $logo_url; ?>" alt=""></div>

    <main>
        <?php if ($languageMode === 'both'): ?>
        <div class="language-switcher">
            <span id="flag-en" class="active"><b>EN</b></span>
            <span id="flag-de" class="inactive"><b>DE</b></span>
        </div>
        <?php endif; ?>

        <?php foreach ($finalSlides as $i => $slide):
            $icon = $slide['icon'] ?? 'fa-star';
            $anim = ($slide['icon_animation'] ?? 'none') !== 'none' ? 'animation: ' . htmlspecialchars($slide['icon_animation']) . ';' : '';
            $display = ($i === 0) ? 'block' : 'none';
            $text = ($languageMode === 'de') ? $slide['text_de'] : $slide['text_en'];

            if ($slide['type'] === 'cast'):
                $cast = $slide['cast'];
                $count = count($cast);
        ?>
        <div class="slide-element" id="slide_<?php echo $i; ?>" style="display:<?php echo $display; ?>"
            data-type="cast"
            data-text-en="<?php echo htmlspecialchars($slide['text_en'], ENT_QUOTES); ?>"
            data-text-de="<?php echo htmlspecialchars($slide['text_de'], ENT_QUOTES); ?>"
            data-icon="<?php echo htmlspecialchars($icon); ?>"
            data-animation="<?php echo htmlspecialchars($slide['icon_animation'] ?? 'none'); ?>">
            <div class="cast-slide">
                <div class="cast-heading">
                    <i class="fas <?php echo htmlspecialchars($icon); ?> slide-icon" style="<?php echo $anim; ?>"></i>
                    <span class="cast-heading-text"><?php echo $text; ?></span>
                </div>
                <div class="cast-stage" data-count="<?php echo $count; ?>">
                    <?php foreach ($cast as $m): ?>
                    <div class="cast-card">
                        <?php if (!empty($m['image'])): ?>
                        <img class="cast-img" src="<?php echo $castImageBase . htmlspecialchars($m['image']); ?>" alt="<?php echo htmlspecialchars($m['name'] ?? ''); ?>">
                        <?php else: ?>
                        <div class="cast-placeholder-circle"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <span class="cast-name"><?php echo htmlspecialchars($m['name'] ?? ''); ?></span>
                        <?php if (!empty($m['role'])): ?>
                        <span class="cast-role"><?php echo htmlspecialchars($m['role']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <h1 class="welcome-text slide-element" id="slide_<?php echo $i; ?>" style="display:<?php echo $display; ?>"
            data-type="text"
            data-text-en="<?php echo htmlspecialchars($slide['text_en'], ENT_QUOTES); ?>"
            data-text-de="<?php echo htmlspecialchars($slide['text_de'], ENT_QUOTES); ?>"
            data-icon="<?php echo htmlspecialchars($icon); ?>"
            data-animation="<?php echo htmlspecialchars($slide['icon_animation'] ?? 'none'); ?>">
            <i class="fas <?php echo htmlspecialchars($icon); ?> slide-icon" style="<?php echo $anim; ?>"></i><br>
            <?php echo $text; ?>
        </h1>
        <?php endif; ?>
        <?php endforeach; ?>
    </main>

    <div style="position: fixed; bottom: 0; left: 0; width: 100%; z-index: 4;">
        <?php
        $assetBase = '../';
        $orgName = $orgaName;
        $showToggle = false;
        $privacyHref = '../datenschutz.php';
        include __DIR__ . '/../partials/footer.php';
        ?>
    </div>

    <script>
        const languageMode = '<?php echo $languageMode; ?>';
        let currentTextIndex = 0;
        const slides = document.querySelectorAll('.slide-element');
        let currentLanguage = languageMode === 'de' ? 'de' : 'en';

        function switchText() {
            const cur = slides[currentTextIndex];
            cur.style.animation = 'fadeOut 2s forwards';
            setTimeout(() => {
                cur.style.display = 'none';
                currentTextIndex = (currentTextIndex + 1) % slides.length;
                const next = slides[currentTextIndex];
                next.style.display = 'block';
                next.style.animation = 'fadeIn 2s forwards';
                if (currentTextIndex === 0 && languageMode === 'both') {
                    currentLanguage = currentLanguage === 'en' ? 'de' : 'en';
                    updateAllSlides(currentLanguage);
                    updateFlags(currentLanguage);
                }
                advanceProgressBar();
            }, 2000);
        }

        function updateAllSlides(lang) {
            slides.forEach(s => {
                const txt = lang === 'de' ? s.getAttribute('data-text-de') : s.getAttribute('data-text-en');
                const icon = s.getAttribute('data-icon');
                const anim = s.getAttribute('data-animation');
                const style = anim !== 'none' ? 'animation:' + anim + ';' : '';
                if (s.getAttribute('data-type') === 'cast') {
                    const h = s.querySelector('.cast-heading-text');
                    if (h) h.innerHTML = txt;
                    const ic = s.querySelector('.cast-heading .slide-icon');
                    if (ic) { ic.className = 'fas ' + icon + ' slide-icon'; ic.style.cssText = style; }
                } else {
                    s.innerHTML = `<i class="fas ${icon} slide-icon" style="${style}"></i><br>` + txt;
                }
            });
        }

        function updateFlags(lang) {
            const en = document.getElementById('flag-en'), de = document.getElementById('flag-de');
            if (!en || !de) return;
            en.className = lang === 'en' ? 'active' : 'inactive';
            de.className = lang === 'de' ? 'active' : 'inactive';
        }

        const totalSlides = slides.length;
        const slideDuration = 10; // seconds per slide
        let currentCycle = 0;

        function advanceProgressBar() {
            const bar = document.getElementById('progressbar');
            const segmentPercent = 100 / totalSlides;
            const targetWidth = segmentPercent * (currentTextIndex + 1);

            // Reset to 0 instantly on cycle start (first load or wrap-around)
            if (currentTextIndex === 0) {
                bar.style.transition = 'none';
                bar.style.width = '0%';
                bar.offsetWidth; // force reflow so reset commits before transition starts
                currentCycle++;
            }

            bar.style.transition = 'width ' + slideDuration + 's linear';
            bar.style.width = targetWidth + '%';
        }

        // Start first segment immediately
        advanceProgressBar();
        setInterval(switchText, 10000);
    </script>
</body>
</html>
