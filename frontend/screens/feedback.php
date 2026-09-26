<?php
require_once '../config.php';

$shows = getShows();

if ($shows && isset($shows['orga_name'], $shows['title'])) {
    $orgaName = htmlspecialchars($shows['orga_name']);
    $showTitle = htmlspecialchars($shows['title']);
    $wallpaper_url = API_BASE_URL . "api/show/get/wallpaper";
    $logo_url = API_BASE_URL . "api/show/get/logo";
} else {
    $orgaName = "Unbekannte Organisation";
    $showTitle = "Unbekannte Vorstellung";
    $wallpaper_url = "";
    $logo_url = "";
}

$vote_url = ORIGIN_URL . 'vote/';


$qrcode_url = "https://quickchart.io/qr?text=" . urlencode($vote_url) . "&ecLevel=H&margin=2&size=200&centerImageUrl=" . urlencode($logo_url);
?>

<?php
$pageTitle = 'Feedback — ' . $showTitle;
$assetBase = '../';
$forceDark = true;
$extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" async>';
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">

<?php include __DIR__ . '/../partials/head.php'; ?>
<body>
<style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: var(--avo-bg);
            color: var(--avo-text);
            line-height: 1.6;
            background-size: 31px 31px;
            background-image: repeating-linear-gradient(45deg, var(--avo-surface) 0, var(--avo-surface) 3.1px, var(--avo-bg) 0, var(--avo-bg) 50%);
            background-repeat: repeat;
            background-attachment: fixed;
        }

        .container {
            max-width: 800px;
            margin: auto;
            padding: 20px;
            background: var(--avo-surface);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            text-align: center;
            border: 2px solid var(--avo-border);
            transition: transform 0.3s ease;
            animation: fadeIn 4s ease forwards;
        }

        .container:hover {
            transform: scale(1.02);
        }

        .orga-name {
            transition: color 0.3s ease;
            animation: textAnimation 6s ease infinite alternate;
        }

        #gradientbar {
            height: 20px;
            background: linear-gradient(90deg, var(--avo-coral-700), var(--avo-coral-500), var(--avo-coral-300), var(--avo-coral-500), var(--avo-coral-700));
            width: 100%;
            position: fixed;
            top: 0;
            z-index: 2;
            background-size: 300% 100%;
            animation: gradient 10s ease infinite;
        }

        @keyframes gradient {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .orga-name-span {
            padding: 2px 8px;
            background-color: color-mix(in oklab, var(--avo-primary) 16%, transparent);
            border-radius: 4px;
            color: var(--avo-primary);
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes textAnimation {
            0% {
                transform: translateY(0);
            }

            100% {
                transform: translateY(-2px);
            }
        }


        .welcome-text {
            position: relative;
            width: 100%;
            text-align: center;
            margin: 0;
            font-size: 3em;
            color: var(--avo-text);
            font-family: var(--avo-font-display);
            animation: fadeIn 2s ease forwards;
            margin-bottom: 15px;
            text-shadow: 1px 1px 5px rgba(0, 0, 0, 0.5);
        }

        .language-switcher {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            vertical-align: middle;
            text-align: center;
        }

        .inactive {
            opacity: 0.5;
            font-size: 1.5em;
            vertical-align: middle;
        }

        .active {
            opacity: 1;
            font-size: 2em;
            vertical-align: middle;
        }

        .language-switcher i {
            margin-left: 5px;
            font-size: 0.9em;
            color: var(--avo-text);
        }

        .welcome-text i {
            margin-right: 10px;
            color: var(--avo-primary);
            font-size: 1em;
        }

        footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            align-items: center;
            justify-content: center;
            display: flex;
            flex-direction: column;
            padding: 10px;
            color: var(--avo-text-muted);
            font-size: 1.3em;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        @keyframes bounce {

            0%,
            20%,
            50%,
            80%,
            100% {
                transform: translateY(0);
            }

            40% {
                transform: translateY(-10px);
            }

            60% {
                transform: translateY(-5px);
            }
        }

        @keyframes wobble {
            0% {
                transform: rotate(0deg);
            }

            25% {
                transform: rotate(5deg);
            }

            50% {
                transform: rotate(-5deg);
            }

            75% {
                transform: rotate(5deg);
            }

            100% {
                transform: rotate(0deg);
            }
        }

        @keyframes laugh {
            0% {
                transform: translateY(0);
            }

            25% {
                transform: translateY(-5px);
            }

            50% {
                transform: translateY(0);
            }

            75% {
                transform: translateY(5px);
            }

            100% {
                transform: translateY(0);
            }
        }

        @media (max-width: 600px) {
            .welcome-text {
                font-size: 3em;
            }
        }

        #progressbar {
            height: 20px;
            background-color: var(--avo-primary);
            position: fixed;
            top: 0;
            left: 0;
            width: 0;
            opacity: 1;
            z-index: 3;
            transition: width 0.4s ease, opacity 1s ease;
        }

        .content-container {
            margin: 30px auto;
            max-width: 1300px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 12px;
            padding: 20px;
        }

        .qrcode {
            width: 350px;
            height: 350px;
            margin-top: 10px;
            background-color: #ffffff;
            border-radius: 20px;
            padding: 10px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .logo {
            bottom: 0;
            right: 0;
            margin: 30px;
            position: fixed;
            z-index: 2;
            border-radius: 18px;
            display: flex;
            justify-content: flex-end;
            align-items: flex-end;
        }

        .logo img {
            width: 11vh;
            height: 11vh;
            border-radius: 12px;
            border: 5px solid transparent;
        }

        .wallpaper-div {
            background-image: linear-gradient(rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.7)), url(<?php echo $wallpaper_url; ?>);
            width: 100%;
            height: 100%;
            background-size: cover;
            position: absolute;
            z-index: -2;
            opacity: .7;
        }
    </style>
    <div id="gradientbar"></div>
    <div id="progressbar"></div>
    <div class="wallpaper-div"></div>
    <div class="logo">
        <img src="<?php echo $logo_url; ?>" alt="">
    </div>
    <main>
        <div class="language-switcher">
            <span id="flag-en" class="active" aria-label="Switch to English"><b>EN</b></span>
            <span id="flag-de" class="inactive" aria-label="Switch to German"><b>DE</b></span>
        </div>
        <div class="content-container">
            <div class="avo-kicker" style="font-size: 1rem; margin-bottom: 0.5rem;">your turn</div>
            <h1 class="welcome-text" id="welcomeText" style="display: block;" aria-live="polite">
                <i class="fas fa-smile" style="animation: laugh 0.5s infinite;" aria-hidden="true"></i>
                <br>
                Thank you for being here! We look forward to seeing you again soon.<br>Your feedback and opinion are very important to us!
            </h1>
            <img src="<?php echo $qrcode_url; ?>" alt="QR Code for Voting" class="qrcode" />
        </div>
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
        let currentTextIndex = 0;
        const texts = [
            document.getElementById('welcomeText')
        ];

        let currentLanguage = 'en';
        let progressBarInterval;

        function switchText() {
            const currentText = texts[currentTextIndex];
            currentText.style.animation = 'fadeOut 2s forwards';
            setTimeout(function() {
                currentText.style.display = 'none';
                currentTextIndex = (currentTextIndex + 1) % texts.length;
                const nextText = texts[currentTextIndex];
                nextText.style.display = 'block';
                nextText.style.animation = 'fadeIn 2s forwards';

                if (currentTextIndex === 0) {
                    currentLanguage = currentLanguage === 'en' ? 'de' : 'en';
                    updateTextLanguage(currentLanguage);
                    updateFlags(currentLanguage);

                }

            }, 2000);
        }

        function updateTextLanguage(language) {
            const translations = {
                en: {
                    welcome: `<i class='fas fa-smile' style="animation: laugh 0.5s infinite;"></i> <br>Thank you for being here! We look forward to seeing you again soon.<br>Your feedback and opinion are very important to us!`,
                },
                de: {
                    welcome: `<i class="fas fa-smile" style="animation: laugh 0.5s infinite;"></i> <br>Danke, dass du hier warst! Wir freuen uns darauf, dich bald wiederzusehen.<br>Dein Feedback und deine Meinung sind uns sehr wichtig!.`,
                }
            };

            document.getElementById('welcomeText').innerHTML = translations[language].welcome;
        }

        function updateFlags(language) {
            const flagEn = document.getElementById('flag-en');
            const flagDe = document.getElementById('flag-de');
            resetProgressBar();
            if (language === 'en') {
                flagEn.classList.add('active');
                flagEn.classList.remove('inactive');
                flagDe.classList.add('inactive');
                flagDe.classList.remove('active');
            } else {
                flagDe.classList.add('active');
                flagDe.classList.remove('inactive');
                flagEn.classList.add('inactive');
                flagEn.classList.remove('active');
            }
        }

        function resetProgressBar() {
            clearInterval(progressBarInterval);
            const progressBar = document.getElementById('progressbar');
            progressBar.style.width = '0%';
            startProgressBar();
        }

        function startProgressBar() {
            const progressBar = document.getElementById('progressbar');
            progressBar.style.width = '0%';

            let width = 0;
            progressBarInterval = setInterval(() => {
                width++;
                progressBar.style.width = width + '%';
            }, 150);
        }

        setInterval(switchText, 15000);
        startProgressBar();
    </script>
<?php include __DIR__ . '/_broadcast.php'; ?>
</body>

</html>