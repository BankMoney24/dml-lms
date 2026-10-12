<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="page-head">
  <h1>Courses</h1>
</section>
<?php if ($courses === []): ?>
  <section class="empty">
    <p><?= $page > 1 ? 'There are no more courses.' : 'No courses are open yet. Check back soon, or ask your teacher which course to join.' ?></p>
  </section>
<?php else: ?>
  <?php include __DIR__ . '/_list.php'; ?>
<?php endif; ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="/courses?page=<?= $page - 1 ?>">Previous page</a><?php endif; ?>
  <?php if ($hasMore): ?><a href="/courses?page=<?= $page + 1 ?>">Next page</a><?php endif; ?>
</nav>
