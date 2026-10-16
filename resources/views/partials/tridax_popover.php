<?php
/**
 * The panel behind the TridaxLabs credit: software categories by sector, from
 * resources/data/tridax.php. Rendered once per page, hidden until opened.
 *
 * @var \App\Core\View\ViewHelpers $v
 */
/** @var array{name: string, url: string, pitch: string, sectors: array<string, list<string>>} $tridax */
$tridax = require __DIR__ . '/../../data/tridax.php';
$total = array_sum(array_map('count', $tridax['sectors']));
$headline = $total > 10 ? 'Over ' . (intdiv($total - 1, 10) * 10) . ' kinds of software' : $total . ' kinds of software';
?>
<div class="tridax-pop" id="tridax-pop" role="dialog" aria-modal="false" aria-labelledby="tridax-pop-h" hidden data-tridax-pop>
  <div class="tridax-pop-head">
    <img src="/assets/brand/tridaxlabs-64.png" width="40" height="40" alt="">
    <div>
      <p class="tridax-pop-kicker">Developed and powered by</p>
      <h2 id="tridax-pop-h"><?= $v->e($tridax['name']) ?></h2>
    </div>
    <button type="button" class="tridax-pop-close" data-tridax-close aria-label="Close"><?= $v->icon('close') ?></button>
  </div>
  <p class="tridax-pop-pitch"><strong><?= $v->e($headline) ?></strong> in <?= count($tridax['sectors']) ?> sectors. <?= $v->e($tridax['pitch']) ?> This learning platform is one of them.</p>
  <div class="tridax-pop-grid">
    <?php foreach ($tridax['sectors'] as $sector => $items): ?>
    <section class="tridax-sector">
      <h3><?= $v->e($sector) ?></h3>
      <ul><?php foreach ($items as $item): ?><li><?= $v->e($item) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endforeach; ?>
  </div>
  <p class="tridax-pop-foot"><a class="button button-small" href="<?= $v->e($tridax['url']) ?>" rel="noopener">Talk to <?= $v->e($tridax['name']) ?> about your project</a></p>
</div>
