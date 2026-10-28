<?php
/**
 * The course list with filters (ADR 0021): kind of course, free / open / paid,
 * level, and words in the title or summary. Filters combine and stay in links.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array<string, mixed>> $cards
 * @var int $page
 * @var bool $hasMore
 * @var ?string $track
 * @var array{track: ?string, show: ?string, level: ?string, q: string} $filters
 * @var array{free: int, open: int, paid: int, levels: array<string, int>, tracks: array<string, int>, all: int} $counts
 * @var bool $openOnly
 */
use App\Modules\Courses\Course;
use App\Modules\Courses\Http\CatalogueController;

$active = array_filter($filters, static fn ($f): bool => $f !== null && $f !== '');
$url = static function (array $change) use ($active): string {
    $q = array_filter($change + $active, static fn ($f): bool => $f !== null && $f !== '');
    unset($q['page']);
    ksort($q);

    return $q === [] ? '/courses' : '/courses?' . http_build_query($q);
};
$pageUrl = static function (int $p) use ($active): string {
    $q = $active + ($p > 1 ? ['page' => $p] : []);

    return $q === [] ? '/courses' : '/courses?' . http_build_query($q);
};
$current = static fn (bool $on): string => $on ? ' aria-current="true"' : '';
$school = $v->shared('tenant');
$showChoices = array_filter(CatalogueController::SHOW, static fn (string $label, string $key): bool => ($counts[$key] ?? 0) > 0 && !($openOnly && $key !== 'open'), ARRAY_FILTER_USE_BOTH);
$levelChoices = array_filter(Course::LEVELS, static fn (string $label, string $key): bool => ($counts['levels'][$key] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
?>
<header class="page-head page-head-row catalogue-head">
  <div>
    <h1><?= $filters['show'] === 'open' ? 'Open courses' : ($filters['show'] === 'free' ? 'Free courses' : 'Courses') ?></h1>
    <p class="lead"><?= $openOnly
        ? 'Free courses anyone can read, no account needed. Sign in to see everything ' . $v->e($school?->name) . ' teaches.'
        : ($filters['show'] === 'open' ? 'Free, and anyone can read the lessons without an account.' : 'Everything ' . $v->e($school?->name) . ' has published.') ?></p>
  </div>
  <form class="catalogue-search" role="search" method="get" action="/courses">
    <label class="visually-hidden" for="catalogue-q">Find a course</label>
    <input type="search" id="catalogue-q" name="q" value="<?= $v->e($filters['q']) ?>" placeholder="Find a course" maxlength="80" autocomplete="off">
    <?php foreach (['track', 'show', 'level'] as $keep): if (($filters[$keep] ?? null) !== null): ?><input type="hidden" name="<?= $keep ?>" value="<?= $v->e((string) $filters[$keep]) ?>"><?php endif; endforeach; ?>
    <button type="submit" class="button button-small button-quiet"><?= $v->icon('search') ?><span class="visually-hidden">Search</span></button>
  </form>
</header>

<?php if (array_sum($counts['tracks']) > 0 && !$openOnly): ?>
<nav class="tabs" aria-label="Kind of course">
  <a href="<?= $v->e($url(['track' => null])) ?>"<?= $filters['track'] === null ? ' aria-current="page"' : '' ?>>All</a>
  <?php foreach (Course::TRACKS as $key => $label): if (($counts['tracks'][$key] ?? 0) === 0) { continue; } ?>
    <a href="<?= $v->e($url(['track' => $key])) ?>"<?= $filters['track'] === $key ? ' aria-current="page"' : '' ?>><?= $v->e($key === 'school' ? 'School subjects' : $label) ?> <span class="tab-count">(<?= (int) $counts['tracks'][$key] ?>)</span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (count($showChoices) + count($levelChoices) > 1 || $active !== []): ?>
<div class="chip-bar">
  <?php if ($showChoices !== [] && !$openOnly): ?>
  <nav class="chip-row" aria-label="Price and access">
    <a class="chip" href="<?= $v->e($url(['show' => null])) ?>"<?= $current($filters['show'] === null) ?>>Any price</a>
    <?php foreach ($showChoices as $key => $label): ?>
      <a class="chip chip-<?= $key ?>" href="<?= $v->e($url(['show' => $key])) ?>"<?= $current($filters['show'] === $key) ?>><?php if ($key === 'open'): ?><?= $v->icon('lesson') ?><?php endif; ?><?= $v->e($label) ?> <span class="chip-count"><?= (int) $counts[$key] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php if ($levelChoices !== []): ?>
  <nav class="chip-row" aria-label="Level">
    <a class="chip" href="<?= $v->e($url(['level' => null])) ?>"<?= $current($filters['level'] === null) ?>>Any level</a>
    <?php foreach ($levelChoices as $key => $label): ?>
      <a class="chip" href="<?= $v->e($url(['level' => $key])) ?>"<?= $current($filters['level'] === $key) ?>><?= $v->e($label) ?> <span class="chip-count"><?= (int) $counts['levels'][$key] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php if ($active !== []): ?><p class="chip-clear"><a href="/courses">Clear filters</a></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (($paths ?? []) !== []): ?>
<section class="section path-strip" aria-labelledby="paths-h">
  <h2 id="paths-h">Learning paths</h2>
  <ul class="path-cards">
    <?php foreach ($paths as $p): ?>
    <li class="path-card">
      <span class="path-card-steps" aria-hidden="true"><?php for ($i = 0; $i < min($p->courseCount, 8); $i++): ?><span></span><?php endfor; ?></span>
      <h3><a href="/paths/<?= $v->e($p->slug) ?>"><?= $v->e($p->title) ?></a></h3>
      <p class="small muted"><?= $v->e($v->plural($p->courseCount, 'course')) ?> in order<?= $p->levelLabel() !== null ? ', ' . $v->e(strtolower($p->levelLabel())) : '' ?></p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($cards === []): ?>
  <?php if ($page > 1): ?>
    <?= $v->emptyState('No more courses', 'You have reached the end of the list.', '/courses', 'Back to the first page') ?>
  <?php elseif ($active !== []): ?>
    <?= $v->emptyState('No courses match', $filters['q'] !== '' ? 'Nothing matches "' . $filters['q'] . '" with these filters. Try fewer words or clear the filters.' : 'No published course fits all of these filters.', '/courses', 'See all courses') ?>
  <?php else: ?>
    <?= $v->can('course.manage')
        ? $v->emptyState('No courses yet', 'Published courses appear here for learners. Build one from scratch, or import a ready-made course with lessons, quizzes and labs.', '/teach', 'Go to Your courses')
        : $v->emptyState('No courses yet', 'Nothing has been published yet. Check back soon, or ask your teacher which course to join.') ?>
  <?php endif; ?>
<?php else: ?>
  <?php $cardHeading = 'h2'; include __DIR__ . '/_list.php'; ?>
<?php endif; ?>
<?php if ($page > 1 || $hasMore): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="<?= $v->e($pageUrl($page - 1)) ?>">Previous page</a><?php endif; ?>
  <?php if ($hasMore): ?><a href="<?= $v->e($pageUrl($page + 1)) ?>">Next page</a><?php endif; ?>
</nav>
<?php endif; ?>
