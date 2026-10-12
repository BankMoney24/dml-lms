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
  <p class="lesson-kind"><?= $v->e($lesson->typeLabel()) ?><?php if ($lesson->type === 'text'): ?> · <?= $lesson->readingMinutes() ?> min read<?php endif; ?></p>
  <h1><?= $v->e($lesson->title) ?></h1>
  <?php if ($lesson->isVideo()): $video = $lesson->video(); ?>
    <?php if ($video === null): ?>
      <p class="notice notice-err">No valid video link yet.</p>
    <?php else: ?>
      <figure class="video" data-embed="<?= $v->e($video->embedUrl()) ?>" data-title="<?= $v->e($lesson->title) ?>">
        <div class="video-frame"><div class="video-placeholder">
          <p class="video-note">This video plays from <?= $v->e($video->providerLabel()) ?> and uses mobile data. It loads only when you choose.</p>
          <a class="button video-load" href="<?= $v->e($video->watchUrl()) ?>" rel="noopener noreferrer">▶ Play video</a>
        </div></div>
      </figure>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->markdown($lesson->body) ?></div><?php endif; ?>

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
      <?php if ($q->explanation !== ''): ?><div class="explanation prose prose-small"><?= $v->markdown($q->explanation) ?></div><?php endif; ?>
    </fieldset>
    <?php endforeach; ?>
  <?php elseif ($lesson->isAssignment()): ?>
    <div class="field"><label for="preview-answer">Your answer</label><textarea id="preview-answer" rows="6" disabled></textarea></div>
    <p><button type="button" class="button" disabled>Submit for review</button></p>
  <?php else: ?>
    <p><button type="button" class="button" disabled>Mark as complete</button></p>
  <?php endif; ?>
</article>
