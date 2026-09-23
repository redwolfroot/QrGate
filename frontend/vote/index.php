<?php

require_once '../config.php';
$shows = getShows();
$stars = htmlspecialchars($shows['votes']['average'] ?? '');
$wallpaper_url = API_BASE_URL . "api/show/get/wallpaper";
$message = '';
$error = '';
$wallpaper_url = API_BASE_URL . "api/show/get/wallpaper";
$logo_url = API_BASE_URL . "api/show/get/logo";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ratingValue = $_POST['rating'] ?? 0;
    $comment = $_POST['comment'] ?? '';

    // only accept integer ratings 1-5
    $ratingValue = (int) $ratingValue;
    if ($ratingValue < 0 || $ratingValue > 5) {
        $ratingValue = 0;
    }


    if (isset($_SESSION['has_voted']) && $_SESSION['has_voted'] === true) {
        $error = 'You have already voted.';
    } elseif ($ratingValue == 0) {
        $error = 'Please select a rating.';
    } else {

        $ch = curl_init(API_BASE_URL . 'api/vote');
        $requestData = json_encode(['value' => $ratingValue, 'comment' => $comment]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . API_KEY
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $message = $result['message'] ?? 'Feedback submitted successfully.';
            $_SESSION['has_voted'] = true;
        } else {
            $message = 'Error while submitting feedback.';
        }
    }
}
?>

<?php
$pageTitle = 'Bewertung - QR Gate';
$assetBase = '../';
$extraHead = <<<HTML
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            line-height: 1.6;
            background-image: linear-gradient(color-mix(in oklab, var(--avo-bg) 88%, transparent), color-mix(in oklab, var(--avo-bg) 80%, transparent)), url($wallpaper_url);
            background-size: cover;
            background-attachment: fixed;
        }

        .container {
            max-width: 800px;
            width: 90%;
            margin: auto;
            padding: 20px;
            background: var(--avo-surface);
            border-radius: var(--avo-radius-lg);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            text-align: center;
            border: 1px solid var(--avo-border);
            transition: transform 0.3s ease;
        }

        .container:hover {
            transform: scale(1.02);
        }

        .icon {
            margin-right: 8px;
            color: var(--avo-primary);
        }

        .fa-star {
            font-size: 2rem;
        }

        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }
        }

        .blur-sm {
            filter: blur(4px);
            pointer-events: none;
        }

        #responseMessage {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 1000;
        }

        textarea {
            background-color: var(--avo-bg);
            color: var(--avo-text);
            border: 1px solid var(--avo-border);
            border-radius: var(--avo-radius-sm);
        }
    </style>
HTML;
?>
<!DOCTYPE html>
<html lang="de" class="avo-ui">

<?php include __DIR__ . '/../partials/head.php'; ?>

<body>
    <div class="container mx-auto px-4 py-8 <?php echo isset($_SESSION['has_voted']) && $_SESSION['has_voted'] === true ? 'blur-sm' : ''; ?>" style="position: relative;">
        <div class="avo-kicker mb-2">feedback</div>
        <h1 class="text-3xl font-bold mb-4">
            <i class="fas fa-pencil-alt icon"></i>
            Submit your <span class="avo-hl">feedback</span> to "<?php echo htmlspecialchars($shows['title'] ?? ''); ?>" from <?php echo htmlspecialchars($shows['orga_name'] ?? ''); ?>
        </h1>
        <p class="text-lg mb-4 avo-muted">Average rating: <?php echo $stars; ?> stars</p>
        <form method="POST" action="">
            <div class="flex items-center mb-4">
                <span class="mr-2">Your rating:</span>
                <div id="starRating" class="flex cursor-pointer">
                    <i class="fas fa-star avo-muted" data-value="1" onclick="setRating(1)"></i>
                    <i class="fas fa-star avo-muted" data-value="2" onclick="setRating(2)"></i>
                    <i class="fas fa-star avo-muted" data-value="3" onclick="setRating(3)"></i>
                    <i class="fas fa-star avo-muted" data-value="4" onclick="setRating(4)"></i>
                    <i class="fas fa-star avo-muted" data-value="5" onclick="setRating(5)"></i>
                </div>
                <input type="hidden" name="rating" id="rating" value="0">
            </div>
            <div class="flex items-center mb-4">
                <i class="fas fa-comment-dots icon"></i>
                <textarea id="comment" name="comment" placeholder="Your comment (optional)" class="w-full p-2 mb-4"></textarea>
            </div>
            <button type="submit" class="avo-btn" <?php echo isset($_SESSION['has_voted']) && $_SESSION['has_voted'] === true ? 'disabled' : ''; ?>>
                <i class="fas fa-paper-plane" style="margin-right:8px;"></i>
                Submit feedback
            </button>
        </form>


    </div>
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div id="responseMessage" class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-center z-10">
            <p class="text-lg mb-4 avo-muted">Average rating: <?php echo $stars; ?> stars</p>
            <?php if ($error): ?>
                <div style="padding: 2px 8px; background-color: color-mix(in oklab, var(--avo-error) 20%, transparent); border-radius: 4px; color: var(--avo-error); font-size: 1.5rem; "><?php echo htmlspecialchars($error); ?></div>
            <?php elseif ($message): ?>
                <div style="padding: 2px 8px; background-color: color-mix(in oklab, var(--avo-success) 24%, transparent); border-radius: 4px; color: var(--avo-success); font-size: 1.5rem;"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <script>
        function setRating(value) {
            document.getElementById('rating').value = value;
            const stars = document.querySelectorAll('#starRating i');
            stars.forEach(star => {
                star.classList.remove('avo-coral');
                star.classList.add('avo-muted');
            });
            for (let i = 0; i < value; i++) {
                stars[i].classList.add('avo-coral');
            }
        }
    </script>
</body>

</html>