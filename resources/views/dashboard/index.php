<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{course_id: int, slug: string, title: string, status: string, done: int, total: int}> $enrolments */
/** @var list<\App\Modules\Courses\Course> $suggested */
$user = $v->shared('user');
$enrolledIds = array_column($enrolments, 'course_id');
$suggested = array_values(array_filter($suggested, static fn ($c) => !in_array($c->id, $enrolledIds, true)));
?>
<section class="page-head">
  <h1>Hello, <?= $v->e(explode(' ', $user->displayName)[0]) ?></h1>
</section>

<?php if ($enrolments !== []): ?>
<section>
  <h2>My learning</h2>
  <ul class="progress-list">
    <?php foreach ($enrolments as $row):
      $pct = $row['total'] === 0 ? 0 : intdiv($row['done'] * 100, $row['total']); ?>
    <li>
      <a href="/courses/<?= $v->e($row['slug']) ?>"><?= $v->e($row['title']) ?></a>
      <progress class="meter" max="<?= max(1, $row['total']) ?>" value="<?= $row['done'] ?>" aria-label="<?= $v->e('Progress in ' . $row['title']) ?>"><?= $pct ?>%</progress>
      <p class="small muted">
        <?= $row['status'] === 'completed' ? 'Complete' : $v->e($row['done'] . ' of ' . $row['total'] . ' lessons done') ?>
      </p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif ($v->can('course.learn')): ?>
<section class="empty">
  <h2>Pick your first course</h2>
  <p>You are not enrolled in anything yet. Choose a course below to start.</p>
</section>
<?php endif; ?>

<?php if ($v->can('course.manage')): ?>
<section>
  <h2>Teaching</h2>
  <p><a class="button" href="/teach">Go to your courses</a></p>
</section>
<?php endif; ?>

<?php if ($suggested !== []): ?>
<section>
  <h2>Courses you can join</h2>
  <?php $courses = $suggested; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>
