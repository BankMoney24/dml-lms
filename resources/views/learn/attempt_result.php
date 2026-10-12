<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{prompt: string, chosen: ?string, correct: bool, answer: ?string, explanation: string}> $review */
$base = '/courses/' . $v->e($course->slug);
?>
<section class="narrow result <?= $attempt['passed'] ? 'is-pass' : 'is-retry' ?>">
  <p class="muted"><?= $v->e($lesson->title) ?></p>
  <h1><?= $attempt['passed'] ? 'You passed' : 'Not passed yet' ?></h1>
  <p class="score"><span class="score-num"><?= $attempt['score_percent'] ?>%</span>
    <span class="score-detail"><?= $attempt['correct_count'] ?> of <?= $attempt['question_count'] ?> correct</span></p>
  <p class="muted small">Saved on the server. Receipt number <?= $attempt['id'] ?>.</p>

  <?php if ($courseCompleted): ?>
    <p class="notice notice-done">You have completed the whole course. <a href="<?= $base ?>">Get your certificate</a>.</p>
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

<?php if ($review !== []): ?>
<section class="narrow review-answers" aria-labelledby="review-h">
  <h2 id="review-h">Your answers</h2>
  <?php if (!$revealed): ?>
    <p class="small muted">Correct answers and explanations appear once you pass. For now, look again at the questions marked “not right yet”.</p>
  <?php endif; ?>
  <ol class="answer-list">
    <?php foreach ($review as $item): ?>
    <li class="<?= $item['correct'] ? 'is-right' : 'is-wrong' ?>">
      <p class="answer-q"><?= $v->e($item['prompt']) ?></p>
      <p class="small">
        <?= $item['correct'] ? $v->badge('Right', 'ok') : $v->badge('Not right yet', 'danger') ?>
        <?php if ($item['chosen'] !== null): ?>You answered: <strong><?= $v->e($item['chosen']) ?></strong><?php else: ?><span class="muted">Not answered</span><?php endif; ?>
      </p>
      <?php if (!$item['correct'] && $item['answer'] !== null): ?>
        <p class="small">Correct answer: <strong><?= $v->e($item['answer']) ?></strong></p>
      <?php endif; ?>
      <?php if ($item['explanation'] !== ''): ?>
        <div class="explanation prose prose-small"><?= $v->markdown($item['explanation']) ?></div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
