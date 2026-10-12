<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var \App\Modules\Courses\Lesson $lesson */
$base = '/courses/' . $v->e($course->slug);
?>
<nav class="crumbs" aria-label="Breadcrumb">
  <a href="<?= $base ?>"><?= $v->e($course->title) ?></a>
  <span aria-hidden="true">/</span>
  <span>Lesson <?= $position ?> of <?= $total ?></span>
</nav>

<div class="lesson-layout">
<article class="lesson">
  <h1><?= $v->e($lesson->title) ?></h1>

  <?php if (!$lesson->isQuiz()): ?>
    <div class="prose"><?= $v->paragraphs($lesson->body) ?></div>
    <?php if ($completed): ?>
      <p class="notice notice-done">You have completed this lesson.</p>
    <?php else: ?>
      <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/complete">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Mark as complete</button>
      </form>
    <?php endif; ?>

  <?php elseif ($quiz === null || $quiz['questions'] === []): ?>
    <p class="notice">This quiz is not ready yet. Your teacher still needs to add questions.</p>

  <?php else: ?>
    <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->paragraphs($lesson->body) ?></div><?php endif; ?>
    <p class="quiz-rules">
      Pass mark: <?= $quiz['pass_threshold'] ?>%.
      <?= $quiz['attempts_left'] === null ? 'You can try as many times as you need.' : $v->e($quiz['attempts_left'] . ' attempts left.') ?>
      <?php if ($quiz['best'] !== null): ?>Your best so far: <?= $quiz['best'] ?>%.<?php endif; ?>
    </p>
    <?php if ($completed): ?><p class="notice notice-done">You have passed this quiz. You can still practise.</p><?php endif; ?>

    <?php if ($quiz['attempts_left'] === 0): ?>
      <p class="notice">You have used all your attempts. Ask your teacher if you need another try.</p>
    <?php else: ?>
    <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/attempts" class="quiz" data-draft-key="quiz-<?= $lesson->id ?>">
      <?= $v->csrfField() ?>
      <input type="hidden" name="idempotency_key" value="<?= $v->e($quiz['idempotency_key']) ?>">
      <?php foreach ($quiz['questions'] as $i => $question): ?>
      <fieldset class="question">
        <legend><span class="q-num"><?= $i + 1 ?>.</span> <?= $v->e($question['prompt']) ?></legend>
        <?php foreach ($question['options'] as $option): ?>
        <label class="choice">
          <input type="radio" name="answers[<?= $question['id'] ?>]" value="<?= $option['id'] ?>" required>
          <span><?= $v->e($option['label']) ?></span>
        </label>
        <?php endforeach; ?>
      </fieldset>
      <?php endforeach; ?>
      <p class="draft-status small muted" data-draft-status aria-live="polite"></p>
      <button type="submit" class="button">Submit answers</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</article>
<aside class="lesson-side" aria-labelledby="side-h">
  <h2 id="side-h" class="side-title">Your progress</h2>
  <?php $linkLessons = true; $currentLessonId = $lesson->id; include __DIR__ . '/../courses/_trace.php'; ?>
</aside>
</div>

<nav class="lesson-nav" aria-label="Lessons">
  <?php if ($previous !== null): ?><a href="<?= $base ?>/lessons/<?= $previous->id ?>">Previous: <?= $v->e($previous->title) ?></a><?php else: ?><span></span><?php endif; ?>
  <?php if ($next !== null): ?><a href="<?= $base ?>/lessons/<?= $next->id ?>">Next: <?= $v->e($next->title) ?></a><?php endif; ?>
</nav>
