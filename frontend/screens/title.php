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

?>

<?php
$pageTitle = $showTitle;
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
            color: var(--avo-text);
            line-height: 1.6;
            background-color: var(--avo-bg);
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
        }

        .welcome-text {
            font-size: 5em;
            color: var(--avo-text);
            font-family: var(--avo-font-display);
            animation: fadeIn 2s ease forwards;
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
            font-size: 1.2em;
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


        .show-subtitle {
            font-size: 0.7em;
            color: var(--avo-text-muted);
        }

        .show-orga {
            font-size: 0.5em;
            color: var(--avo-text-muted);
        }

        .show-title {
            font-size: 1.5em;
            color: var(--avo-text);
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
            width:100%;
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

        <div class="content-container">
            <div class="avo-kicker" style="font-size: 1rem; margin-bottom: 0.5rem;">now showing</div>
            <h1 class="welcome-text" id="welcomeText" style="display: block;" aria-live="polite">
                <i class="fas fa-theater-masks" style="animation: bounce 1s infinite;"></i>
                <br>
                <span class="show-title"><?php echo $showTitle; ?></span></br>
                <span class="show-subtitle"><?php echo $showSubtitle; ?></span>
            </h1>
            <br>
            <br>
            <h1 class="welcome-text" id="welcomeText" style="display: block;" aria-live="polite">
                <span class="show-orga"><?php echo $orgaName; ?></span>
            </h1>
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
</body>

</html>