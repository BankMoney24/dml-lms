<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var \App\Modules\Courses\Lesson $lesson */
/** @var list<\App\Modules\Assignments\Submission> $submissions */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var list<\App\Modules\Files\StoredFile> $lessonFiles */
/** @var array<int, list<\App\Modules\Files\StoredFile>> $submissionFiles */
/** @var ?array<string, mixed> $lab */
/** @var ?array{saved: bool, body: string, updated_at: string} $note */
$note ??= null;
/** @var list<\App\Modules\Discussions\Question> $questions */
$questions ??= [];
$qaEnabled ??= false;
$qaDrafts ??= [];
$base = '/courses/' . $v->e($course->slug);
$latest = $submissions[0] ?? null;
?>
<?= $v->breadcrumbs([$course->title => '/courses/' . $course->slug, 'Lesson ' . $position . ' of ' . $total => null]) ?>

<div class="lesson-layout">
<article class="lesson">
  <header class="lesson-head lesson-head-row">
    <div>
      <p class="lesson-kind"><?= $v->e($lesson->typeLabel()) ?><?php if ($lesson->type === 'text'): ?>, <?= $lesson->readingMinutes() ?> min read<?php endif; ?></p>
      <h1><?= $v->e($lesson->title) ?></h1>
    </div>
    <?php $isSaved = $note !== null && $note['saved']; ?>
    <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/save" class="save-form">
      <?= $v->csrfField() ?>
      <input type="hidden" name="saved" value="<?= $isSaved ? '0' : '1' ?>">
      <button type="submit" class="save-toggle" aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"><?= $v->icon($isSaved ? 'saved-on' : 'saved') ?><span><?= $isSaved ? 'Saved' : 'Save' ?></span><span class="visually-hidden"> this lesson for later</span></button>
    </form>
  </header>

  <?php if ($lesson->type === 'text' || $lesson->isVideo()): ?>
    <?php if ($lesson->isVideo()): $video = $lesson->video(); ?>
      <?php if ($video !== null): ?>
      <figure class="video" data-embed="<?= $v->e($video->embedUrl()) ?>" data-title="<?= $v->e($lesson->title) ?>">
        <div class="video-frame">
          <div class="video-placeholder">
            <p class="video-note">This video plays from <?= $v->e($video->providerLabel()) ?> and uses mobile data. It loads only when you choose.</p>
            <a class="button video-load" href="<?= $v->e($video->watchUrl()) ?>" rel="noopener noreferrer">▶ Play video</a>
          </div>
        </div>
        <figcaption class="small muted">Trouble playing? <a href="<?= $v->e($video->watchUrl()) ?>" rel="noopener noreferrer" class="external">Watch on <?= $v->e($video->providerLabel()) ?></a></figcaption>
      </figure>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->markdown($lesson->body) ?></div><?php endif; ?>
    <?php include __DIR__ . '/_lesson_files.php'; ?>
    <?php if ($completed): ?>
      <p class="notice notice-done">You have completed this lesson.</p>
    <?php else: ?>
      <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/complete">
        <?= $v->csrfField() ?>
        <button type="submit" class="button"><?= $lesson->isVideo() ? 'I have watched it: mark complete' : 'Mark as complete' ?></button>
      </form>
    <?php endif; ?>

  <?php elseif ($lesson->isAssignment()): ?>
    <section class="assignment-brief" aria-labelledby="brief-h">
      <h2 id="brief-h" class="visually-hidden">The task</h2>
      <div class="prose"><?= $v->markdown($lesson->body) ?></div>
      <?php include __DIR__ . '/_lesson_files.php'; ?>
    </section>

    <section id="submission" class="submission-area" aria-labelledby="sub-h">
      <h2 id="sub-h">Your submission</h2>
      <?php if ($latest !== null): ?>
        <div class="submission-status">
          <?= $v->badge($latest->statusLabel(), $latest->tone()) ?>
          <span class="small muted">Sent <?= $v->datetime($latest->submittedAt) ?></span>
        </div>
        <?php if (($submissionFiles[$latest->id] ?? []) !== []): ?>
          <?php $fileList = $submissionFiles[$latest->id]; include __DIR__ . '/../files/_list.php'; ?>
        <?php endif; ?>
        <?php if ($latest->feedback !== null): ?>
          <div class="feedback">
            <p class="feedback-title">Feedback from your teacher</p>
            <div class="prose"><?= $v->markdown($latest->feedback) ?></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($latest !== null && $latest->isApproved()): ?>
        <p class="notice notice-done">Approved. This lesson is complete.</p>
      <?php else: ?>
        <?php if ($latest !== null && $latest->isPending()): ?>
          <p class="small muted">Waiting for a teacher. You can still correct it below until they review it.</p>
        <?php elseif ($latest !== null): ?>
          <p class="small">Make the changes your teacher asked for, then submit again.</p>
        <?php endif; ?>
        <?= $v->errorSummary($errors) ?>
        <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/submissions" class="form" enctype="multipart/form-data" novalidate>
          <?= $v->csrfField() ?>
          <?= $v->field('answer', $latest !== null && $latest->isPending() ? 'Your answer (replaces what you sent)' : 'Your answer', [
              'type' => 'textarea', 'rows' => 12,
              'value' => $old['answer'] ?? ($latest !== null && !$latest->isApproved() ? $latest->body : ''),
              'error' => $errors['answer'] ?? null,
              'hint' => 'Paste commands and output between lines of three backticks (```) to keep their layout.',
          ]) ?>
          <?= $v->field('link', 'Link to your work', [
              'value' => $old['link'] ?? ($latest?->linkUrl ?? ''), 'error' => $errors['link'] ?? null, 'optional' => true,
              'inputmode' => 'url', 'hint' => 'For example a GitHub repository, a shared document or a screenshot.', 'maxlength' => 500,
          ]) ?>
          <div class="field">
            <label for="attachments">Attach files <span class="optional">(optional, up to 3)</span></label>
            <p class="hint" id="attachments-hint">Screenshots, a PDF, a zip of your project or a config file; up to <?= (int) $policy->maxMegabytes() ?> MB each.<?= $latest !== null && $latest->isPending() ? ' Files you already sent stay attached.' : '' ?></p>
            <input type="file" id="attachments" name="attachments[]" multiple accept="<?= $v->e(\App\Modules\Files\UploadPolicy::acceptAttribute()) ?>" aria-describedby="attachments-hint<?= isset($errors['attachments']) ? ' attachments-error' : '' ?>"<?= isset($errors['attachments']) ? ' aria-invalid="true"' : '' ?> data-max-bytes="<?= (int) $policy->maxBytes ?>" data-max-files="3">
            <?php if (isset($errors['attachments'])): ?><p class="field-error" id="attachments-error"><?= $v->e($errors['attachments']) ?></p><?php endif; ?>
          </div>
          <button type="submit" class="button"><?= $latest !== null && $latest->isPending() ? 'Update submission' : 'Submit for review' ?></button>
        </form>
      <?php endif; ?>

      <?php if (count($submissions) > 1 || ($latest !== null && $latest->isPending())): ?>
      <details class="history">
        <summary>What you sent (<?= $v->plural(count($submissions), 'submission') ?>)</summary>
        <ol class="history-list" reversed>
          <?php foreach ($submissions as $s): ?>
          <li>
            <p class="small"><?= $v->badge($s->statusLabel(), $s->tone()) ?> <?= $v->datetime($s->submittedAt) ?></p>
            <div class="prose prose-small"><?= $v->markdown($s->body) ?></div>
            <?php if ($s->linkUrl !== null): ?><p class="small"><a href="<?= $v->e($s->linkUrl) ?>" rel="noopener noreferrer nofollow" class="external"><?= $v->e($s->linkUrl) ?></a></p><?php endif; ?>
            <?php if (($submissionFiles[$s->id] ?? []) !== []): ?><?php $fileList = $submissionFiles[$s->id]; include __DIR__ . '/../files/_list.php'; ?><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
      </details>
      <?php endif; ?>
    </section>

  <?php elseif ($lesson->isLab() && $lab !== null): ?>
    <?php include __DIR__ . '/_lab.php'; ?>

  <?php elseif ($quiz === null || $quiz['questions'] === []): ?>
    <p class="notice">This quiz is not ready yet. Your teacher still needs to add questions.</p>

  <?php else: ?>
    <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->markdown($lesson->body) ?></div><?php endif; ?>
    <?php include __DIR__ . '/_lesson_files.php'; ?>
    <p class="quiz-rules">
      <?= $v->e($v->plural(count($quiz['questions']), 'question')) ?>. Pass mark <?= $quiz['pass_threshold'] ?>%.
      <?= $quiz['attempts_left'] === null ? 'As many tries as you need.' : $v->e($v->plural($quiz['attempts_left'], 'attempt') . ' left.') ?>
      <?php if ($quiz['best'] !== null): ?> Your best so far: <strong><?= $quiz['best'] ?>%</strong>.<?php endif; ?>
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

  <?php if ($qaEnabled): ?>
  <section class="qa-section" id="questions" aria-labelledby="qa-h">
    <div class="qa-section-head">
      <h2 id="qa-h"><?= $v->icon('help') ?>Questions about this lesson<?php if ($questions !== []): ?> <span class="qa-count"><?= count($questions) ?></span><?php endif; ?></h2>
      <p class="hint">Stuck? Ask your teacher and classmates. Everyone taking this course can read questions and answers.</p>
    </div>
    <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/questions" class="qa-ask" id="ask">
      <?= $v->csrfField() ?>
      <label for="question" class="visually-hidden">Your question</label>
      <textarea id="question" name="question" rows="3" maxlength="3000" placeholder="What are you stuck on? Say what you tried. Put code between lines of three backticks (```)."><?= $v->e($qaDrafts['question-' . $lesson->id] ?? '') ?></textarea>
      <button type="submit" class="button button-small">Ask</button>
    </form>
    <?php if ($questions === []): ?>
      <p class="muted qa-empty">No questions yet. If something is not clear, you are probably not the only one: ask.</p>
    <?php else: ?>
      <div class="qa-list">
        <?php $me = $v->shared('user'); $meId = $me?->id ?? 0; $isStaff = $v->can('course.manage'); $coursePath = $base; $back = ''; $drafts = $qaDrafts; ?>
        <?php foreach ($questions as $q): ?><?php include __DIR__ . '/../discussions/_thread.php'; ?><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</article>
<aside class="lesson-side" aria-labelledby="side-h">
  <section class="note-box" id="notes" aria-labelledby="notes-h">
    <h2 id="notes-h" class="note-title"><?= $v->icon('note') ?>Your notes</h2>
    <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/note" class="note-form">
      <?= $v->csrfField() ?>
      <label for="note" class="visually-hidden">Your notes on this lesson</label>
      <textarea id="note" name="note" rows="<?= $note !== null && $note['body'] !== '' ? 6 : 3 ?>" maxlength="5000" placeholder="Write what you want to remember: a command, a definition, a question for your teacher." aria-describedby="note-hint"><?= $v->e($note['body'] ?? '') ?></textarea>
      <p class="hint" id="note-hint">Only you can see your notes.<?php if ($note !== null && $note['body'] !== ''): ?> Saved <?= $v->datetime($note['updated_at'], 'j M, H:i') ?>.<?php endif; ?></p>
      <button type="submit" class="button button-small button-quiet">Save note</button>
    </form>
  </section>
  <div class="side-trace">
    <h2 id="side-h" class="side-title">Your progress</h2>
    <?php $linkLessons = true; $currentLessonId = $lesson->id; include __DIR__ . '/../courses/_trace.php'; ?>
  </div>
</aside>
</div>

<nav class="lesson-nav" aria-label="Lessons">
  <?php if ($previous !== null): ?>
    <a class="pager pager-prev" href="<?= $base ?>/lessons/<?= $previous->id ?>" rel="prev"><span class="pager-dir">Previous lesson</span><span class="pager-title"><?= $v->e($previous->title) ?></span></a>
  <?php else: ?><span></span><?php endif; ?>
  <?php if ($next !== null): ?>
    <a class="pager pager-next" href="<?= $base ?>/lessons/<?= $next->id ?>" rel="next"><span class="pager-dir">Next lesson</span><span class="pager-title"><?= $v->e($next->title) ?></span></a>
  <?php else: ?>
    <a class="pager pager-next" href="<?= $base ?>"><span class="pager-dir">End of the course</span><span class="pager-title">Back to the course page</span></a>
  <?php endif; ?>
</nav>
