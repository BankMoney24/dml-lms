<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array<string, mixed>> $cards
 * @var int $page
 * @var bool $hasMore
 * @var ?string $track
 * @var array<string, int> $trackCounts
 */
$query = static fn (array $q): string => $q === [] ? '/courses' : '/courses?' . http_build_query($q);
$base = $track === null ? [] : ['track' => $track];
?>
<header class="page-head">
  <h1>Courses</h1>
  <p class="lead">Everything <?= $v->e($v->shared('tenant')?->name) ?> has published.</p>
</header>
<?php if (count($trackCounts) > 0): ?>
<nav class="tabs" aria-label="Kind of course">
  <a href="/courses"<?= $track === null ? ' aria-current="page"' : '' ?>>All</a>
  <?php foreach (\App\Modules\Courses\Course::TRACKS as $key => $label): if (($trackCounts[$key] ?? 0) === 0) { continue; } ?>
    <a href="<?= $v->e($query(['track' => $key])) ?>"<?= $track === $key ? ' aria-current="page"' : '' ?>><?= $v->e($key === 'school' ? 'School subjects' : $label) ?> <span class="tab-count">(<?= (int) $trackCounts[$key] ?>)</span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($cards === []): ?>
  <?= $page > 1
      ? $v->emptyState('No more courses', 'You have reached the end of the list.', '/courses', 'Back to the first page')
      : $v->emptyState('No courses yet', 'Nothing has been published yet. Check back soon, or ask your teacher which course to join.') ?>
<?php else: ?>
  <?php include __DIR__ . '/_list.php'; ?>
<?php endif; ?>
<?php if ($page > 1 || $hasMore): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="<?= $v->e($query($base + ['page' => $page - 1])) ?>">Previous page</a><?php endif; ?>
  <?php if ($hasMore): ?><a href="<?= $v->e($query($base + ['page' => $page + 1])) ?>">Next page</a><?php endif; ?>
</nav>
<?php endif; ?>
