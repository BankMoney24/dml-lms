<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{course_id: int, slug: string, title: string, status: string, done: int, total: int}> $enrolments */
/** @var list<\App\Modules\Courses\Course> $suggested */
$user = $v->shared('user');
$enrolledIds = array_column($enrolments, 'course_id');
$suggested = array_values(array_filter($suggested, static fn ($c) => !in_array($c->id, $enrolledIds, true)));
$firstName = explode(' ', trim($user->displayName))[0];
?>
<header class="page-head">
  <h1>Hello, <?= $v->e($firstName) ?></h1>
  <p class="lead"><?= $enrolments !== [] ? 'Pick up where you left off.' : ($v->can('course.manage') ? 'Here is what needs your attention.' : 'Choose a course to begin.') ?></p>
</header>

<?php if ($enrolments !== []): ?>
<section aria-labelledby="my-learning">
  <h2 id="my-learning" class="visually-hidden">My learning</h2>
  <ul class="progress-list">
    <?php foreach ($enrolments as $row):
      $pct = $row['total'] === 0 ? 0 : intdiv($row['done'] * 100, $row['total']); ?>
    <li>
      <a href="/courses/<?= $v->e($row['slug']) ?>"><?= $v->e($row['title']) ?></a>
      <progress class="meter" max="<?= max(1, $row['total']) ?>" value="<?= $row['done'] ?>" aria-label="<?= $v->e('Progress in ' . $row['title']) ?>"><?= $pct ?>%</progress>
      <p class="small muted">
        <?= $row['status'] === 'completed' ? $v->badge('Complete', 'done') : $v->e($row['done'] . ' of ' . $v->plural($row['total'], 'lesson') . ' done') ?>
      </p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif ($v->can('course.learn') && $suggested === []): ?>
  <?= $v->emptyState('No courses yet', 'Your school has not published any courses. Check back soon, or ask your teacher which course to join.') ?>
<?php endif; ?>

<?php if ($v->can('course.manage')): ?>
<section class="section" aria-labelledby="teaching">
  <h2 id="teaching">Teaching</h2>
  <p class="actions">
    <a class="button" href="/teach">Your courses</a>
    <a class="button button-quiet" href="/teach/courses/new">New course</a>
    <?php if ($v->can('members.manage')): ?><a class="button button-quiet" href="/people">Add people</a><?php endif; ?>
  </p>
</section>
<?php endif; ?>

<?php if ($suggested !== []): ?>
<section class="section" aria-labelledby="join">
  <h2 id="join"><?= $enrolments === [] ? 'Courses you can join' : 'More courses' ?></h2>
  <?php $courses = $suggested; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>
