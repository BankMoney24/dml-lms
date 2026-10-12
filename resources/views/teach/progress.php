<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var array{quizzes: list<array{assessment_id: int, title: string, pass_threshold: int}>, total: int, rows: list<array<string, mixed>>} $report */
$tab = 'progress';
$rows = $report['rows'];
$completed = count(array_filter($rows, static fn ($r) => $r['status'] === 'completed'));
?>
<?php include __DIR__ . '/_course_head.php'; ?>

<?php if ($rows === []): ?>
  <?= $v->emptyState(
      'No learners yet',
      $course->isPublished()
          ? 'Nobody has enrolled. Learners enrol from the course page.'
          : 'Publish the course so learners can find and enrol in it.',
  ) ?>
<?php else: ?>
<p class="lead"><?= $v->e($v->plural(count($rows), 'learner')) ?> enrolled · <?= $completed ?> completed</p>
<div class="table-wrap">
<table class="table">
  <thead>
    <tr>
      <th scope="col">Learner</th>
      <th scope="col">Lessons done</th>
      <?php foreach ($report['quizzes'] as $quiz): ?>
        <th scope="col" class="num hide-narrow"><?= $v->e($quiz['title']) ?><span class="visually-hidden">, best score</span></th>
      <?php endforeach; ?>
      <?php foreach ($report['assignments'] as $assignment): ?>
        <th scope="col" class="hide-narrow"><?= $v->e($assignment['title']) ?></th>
      <?php endforeach; ?>
      <th scope="col" class="hide-narrow">Last active</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $v->e($r['name']) ?><span class="small muted"><?= $v->e($r['email']) ?></span></td>
      <td>
        <?php if ($r['status'] === 'completed'): ?>
          <?= $v->badge('Completed', 'done') ?>
        <?php else: ?>
          <?= (int) $r['done'] ?> of <?= (int) $report['total'] ?>
          <progress class="meter" max="<?= max(1, (int) $report['total']) ?>" value="<?= (int) $r['done'] ?>" aria-hidden="true"></progress>
        <?php endif; ?>
      </td>
      <?php foreach ($report['quizzes'] as $quiz): $best = $r['best'][$quiz['assessment_id']] ?? null; ?>
        <td class="num hide-narrow">
          <?php if ($best === null): ?><span class="muted">–</span>
          <?php elseif ($best >= $quiz['pass_threshold']): ?><?= $v->badge('✓ ' . $best . '%', 'ok') ?><span class="visually-hidden"> passed</span>
          <?php else: ?><?= $v->badge($best . '%', 'draft') ?><span class="visually-hidden"> not passed yet</span><?php endif; ?>
        </td>
      <?php endforeach; ?>
      <?php foreach ($report['assignments'] as $assignment): $st = $r['submissions'][$assignment['lesson_id']] ?? null; ?>
        <td class="hide-narrow">
          <?php if ($st === null): ?><span class="muted">–</span>
          <?php elseif ($st === 'approved'): ?><?= $v->badge('Approved', 'done') ?>
          <?php elseif ($st === 'submitted'): ?><a href="/teach/reviews"><?= $v->badge('To review', 'draft') ?></a>
          <?php else: ?><?= $v->badge('Changes asked', 'danger') ?><?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="hide-narrow"><?= $r['last_activity'] === null ? '<span class="muted">Not started</span>' : $v->datetime($r['last_activity']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="small muted">Quiz columns show each learner’s best score; ✓ means it reached the pass mark. Assignment columns show the latest submission.</p>
<?php endif; ?>
