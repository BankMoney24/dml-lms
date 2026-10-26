<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Core\View\Pager $pager */
/** @var string $pagerLabel e.g. "People pages" */
if ($pager->pages <= 1) {
    return;
}
?>
<nav class="pager" aria-label="<?= $v->e($pagerLabel ?? 'Pages') ?>">
  <?php if ($pager->page > 1): ?>
    <a class="pager-step" href="<?= $v->e($pager->url($pager->page - 1)) ?>" rel="prev">Previous<span class="visually-hidden"> page</span></a>
  <?php endif; ?>
  <ul>
    <?php foreach ($pager->window() as $p): ?>
      <?php if ($p === null): ?>
        <li class="pager-gap" aria-hidden="true">…</li>
      <?php elseif ($p === $pager->page): ?>
        <li><a href="<?= $v->e($pager->url($p)) ?>" aria-current="page"><span class="visually-hidden">Page </span><?= $p ?></a></li>
      <?php else: ?>
        <li><a href="<?= $v->e($pager->url($p)) ?>"><span class="visually-hidden">Page </span><?= $p ?></a></li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ul>
  <?php if ($pager->page < $pager->pages): ?>
    <a class="pager-step" href="<?= $v->e($pager->url($pager->page + 1)) ?>" rel="next">Next<span class="visually-hidden"> page</span></a>
  <?php endif; ?>
</nav>
