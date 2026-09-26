<?php
/**
 * Feedback screen after the show: thanks, and a large QR code to the
 * audience vote (vote/). The QR is drawn in the browser as SVG, so it stays
 * sharp at any size and needs no outside service. Alternates DE/EN every 12 s.
 */
require __DIR__ . '/_screen.php';

$voteUrl = rtrim(ORIGIN_URL, '/') . '/vote/';
$voteShort = preg_replace('#^https?://#', '', rtrim($voteUrl, '/'));

$T = [
    'head' => ['de' => 'Wie war der Abend?', 'en' => 'How was your evening?'],
    'text' => ['de' => 'Danke, dass du da warst! Deine Meinung hilft uns, noch besser zu werden.',
               'en' => 'Thank you for coming! Your opinion helps us get even better.'],
    'step' => ['de' => 'Code scannen, Sterne vergeben, fertig. Dauert keine Minute.',
               'en' => 'Scan the code, give your stars, done. Takes less than a minute.'],
    'scan' => ['de' => 'Mit der Handykamera scannen', 'en' => 'Scan with your phone camera'],
];
$attr = fn($k) => 'data-de="' . $h($T[$k]['de']) . '" data-en="' . $h($T[$k]['en']) . '"';

scr_open('Feedback · ' . ($SCR['title'] ?: 'QrGate'), ['de' => 'Deine Meinung', 'en' => 'Your feedback'],
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js" defer></script>');
?>
<style>
    .fb { height: 100%; display: grid; grid-template-columns: minmax(0, 1.25fr) auto; align-items: center; gap: calc(var(--u) * 9); }
    .fb__text { display: flex; flex-direction: column; align-items: flex-start; gap: calc(var(--u) * 3); min-width: 0; text-align: left; }
    .fb__text .scr-title { font-size: calc(var(--u) * 10); }
    .fb__lead { margin: 0; font-size: calc(var(--u) * 3.8); font-weight: 500; line-height: 1.3; max-width: 28ch; }
    .fb__step { display: flex; align-items: center; gap: calc(var(--u) * 2); font-size: calc(var(--u) * 2.6); color: var(--muted); max-width: 40ch; line-height: 1.35; }
    .fb__stars { color: var(--coral); letter-spacing: .1em; font-size: calc(var(--u) * 4); flex-shrink: 0; }
    .fb__qr { display: flex; flex-direction: column; align-items: center; gap: calc(var(--u) * 2.2); }
    .fb__card {
        position: relative; width: calc(var(--u) * 50); aspect-ratio: 1; padding: calc(var(--u) * 3);
        box-sizing: border-box; border-radius: calc(var(--u) * 3); background: #fff;
        box-shadow: 0 calc(var(--u) * 4) calc(var(--u) * 14) rgba(0,0,0,.55), 0 0 0 calc(var(--u) * .6) color-mix(in oklab, var(--coral) 80%, transparent);
    }
    .fb__card .fb__svg { display: block; width: 100%; height: 100%; }
    .fb__logo {
        position: absolute; left: 50%; top: 50%; width: 20%; aspect-ratio: 1; transform: translate(-50%, -50%);
        border-radius: 18%; object-fit: cover; background: #fff; box-shadow: 0 0 0 calc(var(--u) * .9) #fff;
    }
    .fb__hint { font-size: calc(var(--u) * 2.1); letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }
    .fb__url { font-size: calc(var(--u) * 2.6); font-weight: 600; color: var(--avo-text); }
</style>
<section class="scr-slide is-on">
    <div class="fb">
        <div class="fb__text">
            <h1 class="scr-title" <?php echo $attr('head'); ?>><?php echo $h($T['head']['de']); ?></h1>
            <p class="fb__lead" <?php echo $attr('text'); ?>><?php echo $h($T['text']['de']); ?></p>
            <div class="fb__step"><span class="fb__stars" aria-hidden="true">★★★★★</span><span <?php echo $attr('step'); ?>><?php echo $h($T['step']['de']); ?></span></div>
        </div>
        <div class="fb__qr">
            <div class="fb__card" id="fbQr" data-url="<?php echo $h($voteUrl); ?>">
                <?php if ($SCR['logo']): ?><img class="fb__logo" src="<?php echo $h($SCR['logo']); ?>" alt="" onerror="this.remove()"><?php endif; ?>
            </div>
            <div class="fb__hint" <?php echo $attr('scan'); ?>><?php echo $h($T['scan']['de']); ?></div>
            <div class="fb__url"><?php echo $h($voteShort); ?></div>
        </div>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('fbQr');
        try {
            // Level H survives the logo in the middle.
            var qr = qrcode(0, 'H');
            qr.addData(box.dataset.url);
            qr.make();
            box.insertAdjacentHTML('afterbegin', qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true }));
            box.querySelector('svg').setAttribute('class', 'fb__svg');   // escapes the kit's 16px icon rule
        } catch (e) { box.textContent = box.dataset.url; }
        SCR.rotate(Array.prototype.slice.call(document.querySelectorAll('.scr-slide')), { duration: 12000, langs: ['de', 'en'] });
    });
</script>
<?php scr_close(['langs' => true]); ?>
