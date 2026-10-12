<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="page-head narrow">
  <h1><?= $v->e($title) ?></h1>
  <p class="lead"><?= $v->e($message) ?></p>
  <p><a class="button" href="/">Go to the home page</a></p>
  <p class="muted small">Reference for support: <?= $v->e($requestId) ?></p>
</section>
