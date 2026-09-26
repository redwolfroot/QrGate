<?php
/**
 * Title screen: the show as a poster. Title, subtitle and organiser, the
 * banner beside it when one is uploaded, and today's (or the next) date with
 * time and venue. Alternates German and English every 12 s.
 */
require __DIR__ . '/_screen.php';

$next = $SCR['next'];
$title = $SCR['title'] ?: $SCR['orga'] ?: 'QrGate';

// The date line in both languages ("Heute · 19:30 · Aula").
$when = ['de' => '', 'en' => ''];
if ($next) {
    foreach (['de', 'en'] as $l) {
        $day = $next['today'] ? ($l === 'de' ? 'Heute' : 'Tonight') : scr_day($next['date'], $l);
        $time = $next['time'] !== '' && preg_match('/^\d{1,2}:\d{2}$/', $next['time'])
            ? $next['time'] . ($l === 'de' ? ' Uhr' : '') : '';
        $when[$l] = implode(' · ', array_filter([$day, $time, $next['venue']]));
    }
}
$kicker = ['de' => $next && $next['today'] ? 'Heute auf der Bühne' : 'Demnächst',
           'en' => $next && $next['today'] ? 'Now showing' : 'Coming up'];

scr_open($title, $kicker);
?>
<style>
    .ttl { display: grid; align-items: center; gap: calc(var(--u) * 7); grid-template-columns: minmax(0, 1fr); height: 100%; }
    .ttl.has-banner { grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }
    .ttl__text { display: flex; flex-direction: column; align-items: flex-start; gap: calc(var(--u) * 2.6); min-width: 0; text-align: left; }
    .ttl:not(.has-banner) .ttl__text { align-items: center; text-align: center; }
    .ttl__sub { margin: 0; font-size: calc(var(--u) * 3.6); font-weight: 500; line-height: 1.25; color: var(--muted); max-width: 30ch; }
    .ttl__rule { width: calc(var(--u) * 12); height: calc(var(--u) * .6); background: var(--coral); border-radius: 99px; }
    .ttl__when {
        display: inline-flex; align-items: center; gap: calc(var(--u) * 1.6);
        padding: calc(var(--u) * 1.4) calc(var(--u) * 2.6); border-radius: 999px;
        font-size: calc(var(--u) * 2.8); font-weight: 600;
        background: color-mix(in oklab, var(--avo-text) 7%, transparent); box-shadow: inset 0 0 0 1px var(--faint);
    }
    .ttl__when::before { content: ""; width: calc(var(--u) * 1.4); height: calc(var(--u) * 1.4); border-radius: 50%; background: var(--coral); box-shadow: 0 0 calc(var(--u) * 2) var(--coral); }
    .ttl__art { min-width: 0; height: 100%; display: grid; place-items: center; }
    .ttl__frame { position: relative; width: 100%; }
    .ttl__frame img {
        display: block; width: 100%; max-height: 62vh; aspect-ratio: 16 / 10; object-fit: cover;
        border-radius: calc(var(--u) * 1.6);
        box-shadow: 0 calc(var(--u) * 4) calc(var(--u) * 12) rgba(0,0,0,.6), 0 0 0 1px var(--faint);
    }
    .ttl__frame::before, .ttl__frame::after {
        content: ""; position: absolute; width: calc(var(--u) * 5); height: calc(var(--u) * 5);
        border: calc(var(--u) * .5) solid var(--coral);
    }
    .ttl__frame::before { top: calc(var(--u) * -1.6); left: calc(var(--u) * -1.6); border-right: 0; border-bottom: 0; }
    .ttl__frame::after { bottom: calc(var(--u) * -1.6); right: calc(var(--u) * -1.6); border-left: 0; border-top: 0; }
</style>
<section class="scr-slide is-on">
    <div class="ttl<?php echo $SCR['banner'] ? ' has-banner' : ''; ?>" id="ttl">
        <div class="ttl__text">
            <?php if ($when['de'] !== ''): ?>
            <div class="ttl__when" data-de="<?php echo $h($when['de']); ?>" data-en="<?php echo $h($when['en']); ?>"><?php echo $h($when['de']); ?></div>
            <?php endif; ?>
            <h1 class="scr-title <?php echo scr_size($title, 14, 26); ?>"><?php echo $h($title); ?></h1>
            <div class="ttl__rule"></div>
            <?php if ($SCR['subtitle'] !== ''): ?><p class="ttl__sub"><?php echo $h($SCR['subtitle']); ?></p><?php endif; ?>
        </div>
        <?php if ($SCR['banner']): ?>
        <div class="ttl__art"><div class="ttl__frame"><img src="<?php echo $h($SCR['banner']); ?>" alt="" onerror="document.getElementById('ttl').classList.remove('has-banner'); this.closest('.ttl__art').remove();"></div></div>
        <?php endif; ?>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        SCR.rotate(Array.prototype.slice.call(document.querySelectorAll('.scr-slide')), { duration: 12000, langs: ['de', 'en'] });
    });
</script>
<?php scr_close(['langs' => true]); ?>
