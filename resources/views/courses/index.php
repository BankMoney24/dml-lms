<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<header class="page-head">
  <h1>Courses</h1>
  <p class="lead">Everything <?= $v->e($v->shared('tenant')?->name) ?> has published.</p>
</header>
<?php if ($cards === []): ?>
  <?= $page > 1
      ? $v->emptyState('No more courses', 'You have reached the end of the list.', '/courses', 'Back to the first page')
      : $v->emptyState('No courses yet', 'Nothing has been published yet. Check back soon, or ask your teacher which course to join.') ?>
<?php else: ?>
  <?php include __DIR__ . '/_list.php'; ?>
<?php endif; ?>
<?php if ($page > 1 || $hasMore): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="/courses?page=<?= $page - 1 ?>">Previous page</a><?php endif; ?>
  <?php if ($hasMore): ?><a href="/courses?page=<?= $page + 1 ?>">Next page</a><?php endif; ?>
</nav>
<?php endif; ?>
