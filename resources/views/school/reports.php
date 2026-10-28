<?php
/**
 * School → Reports (ADR 0022).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{learners: int, staff: int, new_learners_30: int, active_7: int, active_30: int, enrolments: int, completions: int, completions_30: int, certificates: int, questions_30: int} $totals
 * @var ?list<array{currency: string, total: int, total_30: int, count: int}> $revenue
 * @var list<array{id: int, title: string, slug: string, status: string, lessons: int, enrolled: int, in_progress: int, completed: int, not_started: int, avg_done: int, completion_rate: int, last_activity: ?string}> $courses
 * @var bool $seesPeople
 * @var list<array{user_id: int, name: string, email: string, joined: string, courses: int, completed: int, lessons_done: int, certificates: int, last_activity: ?string}> $learners
 * @var bool $hasMore
 * @var int $page
 * @var string $find
 * @var int $group
 * @var list<array{id: int, name: string}> $groups
 */
use App\Modules\Payments\Money;

$schoolTab = 'reports';
$query = static function (array $q) use ($find, $group): string {
    $q = array_filter($q + ['find' => $find, 'group' => $group > 0 ? $group : null], static fn ($x): bool => $x !== null && $x !== '' && $x !== 1);

    return $q === [] ? '' : '?' . http_build_query($q);
};
$when = static fn (?string $at): string => $at === null ? 'Never' : $v->datetime($at, 'j M');
$figures = [
    ['Learners', $totals['learners'], $totals['new_learners_30'] > 0 ? '+' . $totals['new_learners_30'] . ' in 30 days' : 'none new in 30 days'],
    ['Learning this week', $totals['active_7'], $totals['active_30'] . ' in the last 30 days'],
    ['Course places', $totals['enrolments'], $v->plural($totals['completions'], 'completion')],
    ['Completed in 30 days', $totals['completions_30'], $v->plural($totals['certificates'], 'certificate') . ' issued'],
    ['Questions in 30 days', $totals['questions_30'], 'asked under lessons'],
];
?>
<header class="page-head page-head-row">
  <div>
    <h1>Reports</h1>
    <p class="lead">How learning is going across the school, live. Downloads give times in UTC.</p>
  </div>
  <p class="report-downloads">
    <a class="button button-small button-quiet" href="/school/reports/courses.csv" download><?= $v->icon('chart') ?>Courses (CSV)</a>
    <?php if ($seesPeople): ?><a class="button button-small button-quiet" href="/school/reports/learners.csv<?= $v->e($query([])) ?>" download><?= $v->icon('people') ?>Learners (CSV)</a><?php endif; ?>
  </p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<dl class="report-figures">
  <?php foreach ($figures as [$label, $value, $note]): ?>
  <div class="report-figure">
    <dt><?= $v->e($label) ?></dt>
    <dd><span class="report-value"><?= number_format($value) ?></span><span class="report-note"><?= $v->e($note) ?></span></dd>
  </div>
  <?php endforeach; ?>
</dl>

<?php if ($revenue !== null && $revenue !== []): ?>
<section class="section" aria-labelledby="money-h">
  <h2 id="money-h">Payments received</h2>
  <ul class="report-money">
    <?php foreach ($revenue as $r): ?>
    <li><strong><?= $v->e(Money::format($r['total'], $r['currency'])) ?></strong> from <?= $v->e($v->plural($r['count'], 'payment')) ?><span class="small muted">, <?= $v->e(Money::format($r['total_30'], $r['currency'])) ?> in the last 30 days</span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="section" aria-labelledby="courses-h">
  <h2 id="courses-h">Courses</h2>
  <?php if ($courses === []): ?>
    <p class="muted">No courses yet. <?php if ($v->can('course.manage')): ?><a href="/teach">Build or import one</a>; its progress shows here as learners start.<?php endif; ?></p>
  <?php else: ?>
  <p class="report-key small" aria-hidden="true"><span class="key key-done"></span>Completed <span class="key key-going"></span>In progress <span class="key key-idle"></span>Not started</p>
  <div class="table-wrap">
  <table class="table report-table" data-sortable>
    <thead><tr><th scope="col">Course</th><th scope="col" class="num">Enrolled</th><th scope="col">Learners</th><th scope="col" class="num">Completed</th><th scope="col" class="num">Average progress</th><th scope="col">Last activity</th></tr></thead>
    <tbody>
    <?php foreach ($courses as $c): $n = max(1, $c['enrolled']); ?>
      <tr>
        <th scope="row"><a href="/teach/courses/<?= $c['id'] ?>/progress"><?= $v->e($c['title']) ?></a><?php if ($c['status'] !== 'published'): ?> <?= $v->badge('Draft', 'draft') ?><?php endif; ?><span class="small muted report-sub"><?= $v->e($v->plural($c['lessons'], 'lesson')) ?></span></th>
        <td class="num" data-label="Enrolled"><?= $c['enrolled'] ?></td>
        <td data-label="Learners">
          <?php if ($c['enrolled'] > 0): ?>
          <svg class="report-bar" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="<?= $v->e($c['completed'] . ' completed, ' . $c['in_progress'] . ' in progress, ' . $c['not_started'] . ' not started') ?>">
            <rect class="bar-done" x="0" y="0" height="10" width="<?= round($c['completed'] * 100 / $n, 2) ?>"/>
            <rect class="bar-going" x="<?= round($c['completed'] * 100 / $n, 2) ?>" y="0" height="10" width="<?= round($c['in_progress'] * 100 / $n, 2) ?>"/>
            <rect class="bar-idle" x="<?= round(($c['completed'] + $c['in_progress']) * 100 / $n, 2) ?>" y="0" height="10" width="<?= round($c['not_started'] * 100 / $n, 2) ?>"/>
          </svg>
          <?php else: ?><span class="muted small">Nobody yet</span><?php endif; ?>
        </td>
        <td class="num" data-label="Completed" data-sort="<?= (int) $c['completed'] ?>"><?= $c['completed'] ?><?php if ($c['enrolled'] > 0): ?> <span class="muted small">(<?= $c['completion_rate'] ?>%)</span><?php endif; ?></td>
        <td class="num" data-label="Average progress" data-sort="<?= $c['enrolled'] > 0 ? (int) $c['avg_done'] : -1 ?>"><?= $c['enrolled'] > 0 ? $c['avg_done'] . '%' : '–' ?></td>
        <td data-label="Last activity" data-sort="<?= $v->e((string) ($c['last_activity'] ?? '')) ?>"><?= $v->e($when($c['last_activity'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($seesPeople): ?>
<section class="section" aria-labelledby="learners-h">
  <h2 id="learners-h">Learners</h2>
  <form method="get" action="/school/reports#learners-h" class="filter-row report-filter" role="search">
    <label class="visually-hidden" for="find">Find a learner</label>
    <input type="search" id="find" name="find" value="<?= $v->e($find) ?>" placeholder="Name or email" maxlength="80">
    <?php if ($groups !== []): ?>
    <label class="visually-hidden" for="group">Class</label>
    <select id="group" name="group">
      <option value="0">Every class</option>
      <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"<?= $group === $g['id'] ? ' selected' : '' ?>><?= $v->e($g['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="button button-small button-quiet">Show</button>
    <?php if ($find !== '' || $group > 0): ?><a class="small" href="/school/reports#learners-h">Clear</a><?php endif; ?>
  </form>
  <?php if ($learners === []): ?>
    <p class="muted"><?= $find !== '' || $group > 0 ? 'No learner matches.' : 'No learners yet.' ?><?php if ($find === '' && $group === 0 && $v->can('members.manage')): ?> <a href="/people">Add people</a> or <a href="/school/joining">let learners join</a>.<?php endif; ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table report-table" data-sortable>
    <thead><tr><th scope="col">Learner</th><th scope="col" class="num">Courses</th><th scope="col" class="num">Completed</th><th scope="col" class="num">Lessons done</th><th scope="col" class="num">Certificates</th><th scope="col">Last learned</th></tr></thead>
    <tbody>
    <?php foreach ($learners as $l): ?>
      <tr>
        <th scope="row"><a href="/people?find=<?= $v->e(rawurlencode($l['email'])) ?>"><?= $v->e($l['name']) ?></a><span class="small muted report-sub"><?= $v->e($l['email']) ?></span></th>
        <td class="num" data-label="Courses"><?= $l['courses'] ?></td>
        <td class="num" data-label="Completed"><?= $l['completed'] ?></td>
        <td class="num" data-label="Lessons done"><?= $l['lessons_done'] ?></td>
        <td class="num" data-label="Certificates"><?= $l['certificates'] ?></td>
        <td data-label="Last learned" data-sort="<?= $v->e((string) ($l['last_activity'] ?? '')) ?>"><?= $v->e($when($l['last_activity'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($page > 1 || $hasMore): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a href="/school/reports<?= $v->e($query(['page' => $page - 1])) ?>#learners-h">Previous page</a><?php endif; ?>
    <?php if ($hasMore): ?><a href="/school/reports<?= $v->e($query(['page' => $page + 1])) ?>#learners-h">Next page</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
