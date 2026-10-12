<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var \App\Modules\Courses\Lesson $lesson */
/** @var list<\App\Modules\Assessments\QuizQuestion> $questions */
$url = '/teach/courses/' . $course->id . '/lessons/' . $lesson->id;
$crumb = 'Preview';
?>
<?php include __DIR__ . '/_course_head.php'; ?>

<p class="notice">Preview: this is what learners see. Correct answers are marked here only, and nothing you do is recorded. <a href="<?= $url ?>">Back to editing</a></p>

<article class="lesson">
  <h1><?= $v->e($lesson->title) ?></h1>
  <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->paragraphs($lesson->body) ?></div><?php endif; ?>

  <?php if ($lesson->isQuiz()): ?>
    <?php if ($assessment !== null): ?>
      <p class="quiz-rules">Pass mark: <?= (int) $assessment['pass_threshold'] ?>%.
        <?= $assessment['max_attempts'] === null ? 'Unlimited attempts.' : $v->e($v->plural((int) $assessment['max_attempts'], 'attempt') . ' allowed.') ?></p>
    <?php endif; ?>
    <?php if ($questions === []): ?>
      <p class="notice">No questions yet. Learners would see “This quiz is not ready yet”.</p>
    <?php endif; ?>
    <?php foreach ($questions as $i => $q): ?>
    <fieldset class="question" disabled>
      <legend><span class="q-num"><?= $i + 1 ?>.</span> <?= $v->e($q->prompt) ?></legend>
      <?php foreach ($q->options as $opt): ?>
      <label class="choice<?= $opt->correct ? ' is-correct' : '' ?>">
        <input type="radio" name="q<?= $q->id ?>"<?= $opt->correct ? ' checked' : '' ?>>
        <span><?= $v->e($opt->label) ?><?= $opt->correct ? ' ' . $v->badge('Correct', 'ok') : '' ?></span>
      </label>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
  <?php else: ?>
    <p><button type="button" class="button" disabled>Mark as complete</button></p>
  <?php endif; ?>
</article>
