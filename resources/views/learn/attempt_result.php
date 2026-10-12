<?php
/** @var \App\Core\View\ViewHelpers $v */
$base = '/courses/' . $v->e($course->slug);
?>
<section class="narrow result <?= $attempt['passed'] ? 'is-pass' : 'is-retry' ?>">
  <p class="muted"><?= $v->e($lesson->title) ?></p>
  <h1><?= $attempt['passed'] ? 'You passed' : 'Not passed yet' ?></h1>
  <p class="score"><span class="score-num"><?= $attempt['score_percent'] ?>%</span>
    <span class="score-detail"><?= $attempt['correct_count'] ?> of <?= $attempt['question_count'] ?> correct</span></p>
  <p class="muted small">Saved on the server. Receipt number <?= $attempt['id'] ?>.</p>

  <?php if ($courseCompleted): ?>
    <p class="notice notice-done">You have completed the whole course.</p>
  <?php endif; ?>

  <p class="actions">
    <?php if ($attempt['passed'] && $next !== null): ?>
      <a class="button" href="<?= $base ?>/lessons/<?= $next->id ?>">Next lesson: <?= $v->e($next->title) ?></a>
    <?php elseif (!$attempt['passed']): ?>
      <a class="button" href="<?= $base ?>/lessons/<?= $lesson->id ?>">Try the quiz again</a>
    <?php endif; ?>
    <a class="button button-quiet" href="<?= $base ?>">Back to the course</a>
  </p>
</section>
