<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="page-head narrow">
  <h1><?= $v->e($title) ?></h1>
  <p class="lead"><?= $v->e($message) ?></p>
  <p class="actions">
    <?php if (in_array($status ?? 0, [409, 413, 419], true)): ?><button type="button" class="button" data-back hidden>Go back</button><?php endif; ?>
    <a class="button<?= in_array($status ?? 0, [409, 413, 419], true) ? ' button-quiet' : '' ?>" href="/">Go to the home page</a>
  </p>
  <p class="muted small">Reference for support: <?= $v->e($requestId) ?></p>
</section>
