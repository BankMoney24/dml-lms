<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var \App\Modules\Courses\Lesson $lesson */
/** @var list<\App\Modules\Assessments\QuizQuestion> $questions */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var ?\App\Modules\Files\StoredFile $mediaFile */
$mediaFile ??= null;
$base = '/teach/courses/' . $course->id;
$url = $base . '/lessons/' . $lesson->id;
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$crumb = $lesson->title;
$questionErrors = array_intersect_key($errors, array_flip(['prompt', 'options', 'correct', 'explanation']));
$bodyLabel = match ($lesson->type) {
    'quiz' => 'Instructions shown above the questions',
    'video' => 'Notes shown under the video',
    'audio' => 'Notes or a transcript shown under the recording',
    'assignment' => 'The task: what to do, what to submit, how it is judged',
    'lab' => 'Introduction shown above the lab',
    default => 'Lesson text',
};
$lessonErrors = array_diff_key($errors, $questionErrors, ['file' => true, 'media' => true]);
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
    <?php if ($lesson->takesMedia()): $isVideo = $lesson->isVideo(); ?>
    <section id="lesson-media" class="media-section" aria-labelledby="media-h">
      <h2 id="media-h"><?= $isVideo ? 'The video' : 'The recording' ?></h2>
      <?php if ($mediaFile !== null): ?>
        <?php $mediaSrc = '/files/' . $mediaFile->id; include __DIR__ . '/../files/_player.php'; ?>
        <p class="small muted"><?= $v->e($mediaFile->originalName) ?>, uploaded <?= $v->datetime($mediaFile->createdAt, 'j M Y') ?>.</p>
      <?php elseif ($isVideo && $lesson->video() !== null): ?>
        <p class="muted small">This lesson plays a <?= $v->e((string) $lesson->video()?->providerLabel()) ?> video from the link below. Upload a file instead to keep learners on this site and let them download it for offline viewing.</p>
      <?php else: ?>
        <p class="notice">No <?= $isVideo ? 'video' : 'recording' ?> yet. Learners cannot use this lesson until you <?= $isVideo ? 'upload a video or add a link below' : 'upload one' ?>.</p>
      <?php endif; ?>
      <form method="post" action="<?= $url ?>/media" enctype="multipart/form-data" class="form upload-form" novalidate data-progress>
        <?= $v->csrfField() ?>
        <div class="field">
          <label for="media"><?= $mediaFile !== null ? ($isVideo ? 'Replace the video' : 'Replace the recording') : ($isVideo ? 'Upload a video' : 'Upload a recording') ?></label>
          <p class="hint" id="media-hint"><?= $isVideo
              ? 'MP4 or WebM, up to ' . (int) $policy->webMegabytes(true) . ' MB. Keep it small for learners on mobile data: 480p or 720p is plenty for a screen recording or a talk.'
              : 'MP3, M4A, OGG, Opus or WAV, up to ' . (int) $policy->webMegabytes(true) . ' MB. A phone voice recording is fine; MP3 or M4A keeps it light.' ?></p>
          <input type="file" id="media" name="media" accept="<?= $v->e(\App\Modules\Files\UploadPolicy::mediaAcceptAttribute($isVideo ? 'video' : 'audio')) ?>" aria-describedby="media-hint<?= isset($errors['media']) ? ' media-error' : '' ?>"<?= isset($errors['media']) ? ' aria-invalid="true"' : '' ?> data-max-bytes="<?= (int) $policy->webLimit(true) ?>" data-media-max-bytes="<?= (int) $policy->webLimit(true) ?>">
          <?php if (isset($errors['media'])): ?><p class="field-error" id="media-error"><?= $v->e($errors['media']) ?></p><?php endif; ?>
        </div>
        <div class="upload-progress" data-progress-box hidden>
          <div class="upload-track"><span class="upload-bar" data-progress-bar></span></div>
          <p class="small" data-progress-label aria-live="polite">Starting the upload…</p>
        </div>
        <button type="submit" class="button button-small"><?= $v->icon('upload') ?>Upload</button>
      </form>
      <?php if ($mediaFile !== null): ?>
      <form method="post" action="<?= $url ?>/media/remove" class="inline">
        <?= $v->csrfField() ?>
        <button type="submit" class="linklike small">Remove the <?= $isVideo ? 'video' : 'recording' ?></button>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <h2 id="content-h"><?= $lesson->isQuiz() ? 'Instructions and settings' : 'Lesson content' ?></h2>
    <?= $v->errorSummary($lessonErrors, ['lesson_title' => 'lesson_title']) ?>
    <form method="post" action="<?= $url ?>" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('lesson_title', 'Lesson title', ['value' => $o('lesson_title'), 'error' => $errors['lesson_title'] ?? null, 'maxlength' => 190]) ?>
      <?php if ($lesson->isLab()): ?>
        <p class="notice">This lesson uses the lab <strong><?= $v->e((string) $lesson->labKey) ?></strong>. <a href="/teach/labs/<?= $v->e((string) $lesson->labKey) ?>">Try it</a>. To use a different lab, add a new lab lesson and delete this one.</p>
      <?php endif; ?>
      <?php if ($lesson->isVideo()): ?>
        <?= $v->field('media_url', 'Video link', [
            'value' => $o('media_url'), 'error' => $errors['media_url'] ?? null, 'inputmode' => 'url', 'maxlength' => 500, 'optional' => true,
            'hint' => $mediaFile !== null
                ? 'Not used while an uploaded video is set. A YouTube or Vimeo address.'
                : 'Instead of uploading: a YouTube or Vimeo address. Learners choose when to load it, to save data.',
        ]) ?>
      <?php endif; ?>
      <?= $v->field('body', $bodyLabel, [
          'type' => 'textarea', 'rows' => $lesson->isQuiz() ? 3 : 16, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
          'optional' => $lesson->isQuiz() || $lesson->takesMedia() || $lesson->isLab(), 'class' => 'field-code',
      ]) ?>
      <?php include __DIR__ . '/_format_help.php'; ?>
      <?php if ($lesson->isQuiz()): ?>
        <?= $v->field('pass_threshold', 'Pass mark (%)', ['value' => $o('pass_threshold'), 'inputmode' => 'numeric', 'error' => $errors['pass_threshold'] ?? null]) ?>
        <?= $v->field('max_attempts', 'Attempts allowed', ['value' => $o('max_attempts'), 'inputmode' => 'numeric', 'hint' => 'Leave blank for unlimited.', 'error' => $errors['max_attempts'] ?? null]) ?>
      <?php endif; ?>
      <?php if ($lesson->canBePreview()): ?>
      <label class="choice choice-tight preview-choice"><input type="checkbox" name="preview" value="1"<?= $o('preview', $lesson->preview ? '1' : '0') === '1' ? ' checked' : '' ?>><span><strong>Free preview.</strong> Anyone can read this lesson from the course page before joining<?= $course->isPaid() ? ' or paying' : '' ?>. Good for a first lesson.</span></label>
      <?php endif; ?>
      <button type="submit" class="button">Save lesson</button>
    </form>

    <section id="files" class="section" aria-labelledby="files-h">
      <h2 id="files-h">Files for learners</h2>
      <p class="muted small">Handouts, PDFs, example configs or scripts. Learners see them under the lesson, with their size. Up to <?= (int) $policy->webMegabytes(false) ?> MB each; extra video or audio clips up to <?= (int) $policy->webMegabytes(true) ?> MB.</p>
      <?php if ($files !== []): ?>
        <?php $fileList = $files; $removeUrl = static fn ($f): string => $url . '/files/' . $f->id . '/delete'; include __DIR__ . '/../files/_list.php'; ?>
      <?php endif; ?>
      <form method="post" action="<?= $url ?>/files" enctype="multipart/form-data" class="form upload-form" novalidate data-progress>
        <?= $v->csrfField() ?>
        <div class="field">
          <label for="file">Add a file</label>
          <p class="hint" id="file-hint"><?= $v->e(\App\Modules\Files\UploadPolicy::summary()) ?>.</p>
          <input type="file" id="file" name="file" accept="<?= $v->e(\App\Modules\Files\UploadPolicy::acceptAttribute()) ?>" aria-describedby="file-hint<?= isset($errors['file']) ? ' file-error' : '' ?>"<?= isset($errors['file']) ? ' aria-invalid="true"' : '' ?> data-max-bytes="<?= (int) $policy->webLimit(false) ?>" data-media-max-bytes="<?= (int) $policy->webLimit(true) ?>">
          <?php if (isset($errors['file'])): ?><p class="field-error" id="file-error"><?= $v->e($errors['file']) ?></p><?php endif; ?>
        </div>
        <div class="upload-progress" data-progress-box hidden>
          <div class="upload-track"><span class="upload-bar" data-progress-bar></span></div>
          <p class="small" data-progress-label aria-live="polite">Starting the upload…</p>
        </div>
        <button type="submit" class="button button-small button-quiet">Upload</button>
      </form>
    </section>

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
