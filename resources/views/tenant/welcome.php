<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<\App\Modules\Courses\Course> $courses */
$tenant = $v->shared('tenant');
?>
<section class="hero">
  <h1><?= $v->e($tenant->name) ?></h1>
  <p class="lead">Learn it. Practise it. Prove it. Sign in to continue your courses, or look through what we teach.</p>
  <p class="actions"><a class="button" href="/login">Sign in</a> <a class="button button-quiet" href="/courses">Browse courses</a></p>
</section>
<?php if ($courses !== []): ?>
<section class="section" aria-labelledby="latest">
  <h2 id="latest">Latest courses</h2>
  <?php include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>
