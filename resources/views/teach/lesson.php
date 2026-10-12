<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var \App\Modules\Courses\Lesson $lesson */
/** @var list<\App\Modules\Assessments\QuizQuestion> $questions */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
$base = '/teach/courses/' . $course->id;
$url = $base . '/lessons/' . $lesson->id;
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$crumb = $lesson->title;
$questionErrors = array_intersect_key($errors, array_flip(['prompt', 'options', 'correct', 'explanation']));
$bodyLabel = match ($lesson->type) {
    'quiz' => 'Instructions shown above the questions',
    'video' => 'Notes shown under the video',
    'assignment' => 'The task: what to do, what to submit, how it is judged',
    default => 'Lesson text',
};
$lessonErrors = array_diff_key($errors, $questionErrors);
?>
<?php include __DIR__ . '/_course_head.php'; ?>

<header class="page-head page-head-row">
  <div>
    <h1><?= $v->e($lesson->title) ?></h1>
    <p class="page-head-meta"><?= $v->badge($lesson->typeLabel(), 'type-' . $lesson->type) ?>
      <?php if ($lesson->type === 'text'): ?><span><?= $lesson->readingMinutes() ?> min read</span><?php endif; ?>
      <?php if ($lesson->isAssignment()): ?><a href="/teach/reviews">Review queue</a><?php endif; ?>
      <?php if ($inUse): ?><span>Learners have started this lesson</span><?php endif; ?></p>
  </div>
  <a class="button button-quiet" href="<?= $url ?>/preview">Preview</a>
</header>

<div class="teach-layout">
  <section aria-labelledby="content-h">
    <h2 id="content-h"><?= $lesson->isQuiz() ? 'Instructions and settings' : 'Lesson content' ?></h2>
    <?= $v->errorSummary($lessonErrors, ['lesson_title' => 'lesson_title']) ?>
    <form method="post" action="<?= $url ?>" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('lesson_title', 'Lesson title', ['value' => $o('lesson_title'), 'error' => $errors['lesson_title'] ?? null, 'maxlength' => 190]) ?>
      <?php if ($lesson->isVideo()): ?>
        <?= $v->field('media_url', 'Video link', [
            'value' => $o('media_url'), 'error' => $errors['media_url'] ?? null, 'inputmode' => 'url', 'maxlength' => 500,
            'hint' => 'A YouTube or Vimeo address. Learners choose when to load it, to save data.',
        ]) ?>
      <?php endif; ?>
      <?= $v->field('body', $bodyLabel, [
          'type' => 'textarea', 'rows' => $lesson->isQuiz() ? 3 : 16, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
          'optional' => $lesson->isQuiz() || $lesson->isVideo(), 'class' => 'field-code',
      ]) ?>
      <?php include __DIR__ . '/_format_help.php'; ?>
      <?php if ($lesson->isQuiz()): ?>
        <?= $v->field('pass_threshold', 'Pass mark (%)', ['value' => $o('pass_threshold'), 'inputmode' => 'numeric', 'error' => $errors['pass_threshold'] ?? null]) ?>
        <?= $v->field('max_attempts', 'Attempts allowed', ['value' => $o('max_attempts'), 'inputmode' => 'numeric', 'hint' => 'Leave blank for unlimited.', 'error' => $errors['max_attempts'] ?? null]) ?>
      <?php endif; ?>
      <button type="submit" class="button">Save lesson</button>
    </form>

    <div class="danger-zone">
      <h2>Delete this lesson</h2>
      <?php if ($inUse): ?>
        <p class="muted">Learners have already used this lesson, so it cannot be deleted. You can still edit it.</p>
      <?php else: ?>
        <p class="muted">No learner has opened it yet, so it can be removed completely.</p>
        <details class="confirm">
          <summary>Delete lesson</summary>
          <form method="post" action="<?= $url ?>/delete">
            <?= $v->csrfField() ?>
            <p class="small">This cannot be undone.</p>
            <button type="submit" class="button button-danger button-small">Yes, delete “<?= $v->e($lesson->title) ?>”</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($lesson->isQuiz()): ?>
  <section aria-labelledby="questions-h" id="questions">
    <h2 id="questions-h"><?= $v->plural(count($questions), 'question') ?></h2>
    <?php if ($attempts > 0): ?>
      <p class="notice small"><?= $v->e($v->plural($attempts, 'attempt')) ?> recorded. Existing questions are locked so earlier scores stay meaningful; you can still add new ones.</p>
    <?php endif; ?>
    <?php if ($questions === []): ?>
      <p class="muted">Add the first question below. Learners need at least one to take the quiz.</p>
    <?php endif; ?>
    <ol class="q-list">
      <?php foreach ($questions as $q): ?>
      <li id="question-<?= $q->id ?>">
        <p class="q-prompt"><?= $v->e($q->prompt) ?></p>
        <ul class="q-options">
          <?php foreach ($q->options as $opt): ?>
          <li<?= $opt->correct ? ' class="is-correct"' : '' ?>><?= $v->e($opt->label) ?><?= $opt->correct ? ' <span class="visually-hidden">(correct answer)</span>' : '' ?></li>
          <?php endforeach; ?>
        </ul>
        <details class="inline-edit">
          <summary><?= $q->explanation === '' ? 'Add an explanation' : 'Explanation' ?></summary>
          <?php if ($q->explanation !== ''): ?><div class="prose prose-small"><?= $v->markdown($q->explanation) ?></div><?php endif; ?>
          <form method="post" action="<?= $url ?>/questions/<?= $q->id ?>/explanation" class="form">
            <?= $v->csrfField() ?>
            <?= $v->field('explanation', 'Why the answer is right', [
                'id' => 'explanation-' . $q->id, 'type' => 'textarea', 'rows' => 3, 'value' => $q->explanation,
                'hint' => 'Learners see this after they pass. You can change it at any time.',
            ]) ?>
            <button type="submit" class="button button-small button-quiet">Save explanation</button>
          </form>
        </details>
        <?php if ($attempts === 0): ?>
        <form method="post" action="<?= $url ?>/questions/<?= $q->id ?>/delete">
          <?= $v->csrfField() ?>
          <button type="submit" class="linklike small">Remove question</button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>

    <div class="panel">
      <h3>Add a question</h3>
      <?= $v->errorSummary($questionErrors, ['options' => 'option-0', 'correct' => 'correct-0']) ?>
      <form method="post" action="<?= $url ?>/questions" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('prompt', 'Question', ['type' => 'textarea', 'rows' => 2, 'value' => $o('prompt'), 'error' => $errors['prompt'] ?? null, 'maxlength' => 1000]) ?>
        <fieldset class="field">
          <legend>Answer options</legend>
          <p class="hint">Fill in two to four options and select the correct one.</p>
          <?php for ($i = 0; $i < 4; $i++): ?>
          <div class="option-row">
            <input type="radio" name="correct" value="<?= $i ?>" id="correct-<?= $i ?>"<?= $o('correct') === (string) $i ? ' checked' : '' ?>>
            <label class="visually-hidden" for="correct-<?= $i ?>">Option <?= $i + 1 ?> is the correct answer</label>
            <label class="visually-hidden" for="option-<?= $i ?>">Option <?= $i + 1 ?></label>
            <input id="option-<?= $i ?>" name="options[<?= $i ?>]" maxlength="500" placeholder="Option <?= $i + 1 ?><?= $i >= 2 ? ' (optional)' : '' ?>" value="<?= $v->e($o('option_' . $i)) ?>"<?= isset($errors['options']) && $i < 2 ? ' aria-invalid="true"' : '' ?>>
          </div>
          <?php endfor; ?>
          <?php foreach (['options', 'correct'] as $f): ?>
            <?php if (isset($errors[$f])): ?><p class="field-error"><?= $v->e($errors[$f]) ?></p><?php endif; ?>
          <?php endforeach; ?>
        </fieldset>
        <?= $v->field('explanation', 'Explanation', [
            'type' => 'textarea', 'rows' => 3, 'value' => $o('explanation'), 'optional' => true, 'error' => $errors['explanation'] ?? null,
            'hint' => 'Why the right answer is right. Shown to learners once they pass.',
        ]) ?>
        <button type="submit" class="button button-small">Add question</button>
      </form>
    </div>
  </section>
  <?php endif; ?>
</div>
