<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Assignments\Submission $submission */
/** @var ?\App\Modules\Identity\User $learner */
/** @var \App\Modules\Courses\Lesson $lesson */
/** @var \App\Modules\Courses\Course $course */
/** @var list<\App\Modules\Assignments\Submission> $history */
/** @var array<int, list<\App\Modules\Files\StoredFile>> $submissionFiles */
/** @var array<int, string> $reviewerNames */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
$name = $learner?->displayName ?? 'Former member';
/** @var ?\App\Modules\Joining\LearnerProfile $learnerProfile */
$learnerProfile ??= null;
?>
<?= $v->breadcrumbs(['Your courses' => '/teach', 'Reviews' => '/teach/reviews', $name => null]) ?>
<header class="page-head">
  <h1><?= $v->e($lesson->title) ?></h1>
  <p class="page-head-meta">
    <span><?= $v->e($name) ?></span>
    <?php if ($learnerProfile?->github !== null): ?><a href="<?= $v->e((string) $learnerProfile->githubUrl()) ?>" rel="noopener noreferrer"><?= $v->icon('code') ?>github.com/<?= $v->e($learnerProfile->github) ?></a><?php endif; ?>
    <?php if ($learnerProfile?->portfolio !== null): ?><a href="<?= $v->e($learnerProfile->portfolio) ?>" rel="noopener noreferrer nofollow" class="external">Portfolio</a><?php endif; ?>
    <a href="/teach/courses/<?= $course->id ?>"><?= $v->e($course->title) ?></a>
    <?= $v->badge($submission->statusLabel(), $submission->tone()) ?>
  </p>
</header>

<div class="review-layout">
  <section aria-labelledby="work-h">
    <h2 id="work-h">Submitted work</h2>
    <p class="small muted">Sent <?= $v->datetime($submission->submittedAt) ?><?= $history !== [] ? ', attempt ' . (count($history) + 1) : '' ?></p>
    <div class="submission-body prose"><?= $v->markdown($submission->body) ?></div>
    <?php if ($submission->linkUrl !== null): ?>
      <p>Link: <a href="<?= $v->e($submission->linkUrl) ?>" rel="noopener noreferrer nofollow" class="external"><?= $v->e($submission->linkUrl) ?></a></p>
    <?php endif; ?>
    <?php if (($submissionFiles[$submission->id] ?? []) !== []): ?>
      <h3 class="small-heading">Attached files</h3>
      <?php $fileList = $submissionFiles[$submission->id]; include __DIR__ . '/../files/_list.php'; ?>
    <?php endif; ?>

    <details class="panel">
      <summary>The task as learners see it</summary>
      <div class="prose prose-small"><?= $v->markdown($lesson->body) ?></div>
    </details>

    <?php if ($history !== []): ?>
    <section class="section" aria-labelledby="hist-h">
      <h2 id="hist-h">Earlier submissions</h2>
      <ol class="history-list" reversed>
        <?php foreach ($history as $s): ?>
        <li>
          <p class="small"><?= $v->badge($s->statusLabel(), $s->tone()) ?> <?= $v->datetime($s->submittedAt) ?></p>
          <div class="prose prose-small"><?= $v->markdown($s->body) ?></div>
          <?php if (($submissionFiles[$s->id] ?? []) !== []): ?><?php $fileList = $submissionFiles[$s->id]; include __DIR__ . '/../files/_list.php'; ?><?php endif; ?>
          <?php if ($s->feedback !== null): ?>
            <div class="feedback"><p class="feedback-title">Feedback from <?= $v->e($reviewerNames[$s->reviewedBy ?? 0] ?? 'a teacher') ?></p><div class="prose prose-small"><?= $v->markdown($s->feedback) ?></div></div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </section>

  <aside class="panel review-decision" aria-labelledby="decide-h">
    <h2 id="decide-h">Your decision</h2>
    <?= $v->errorSummary($errors) ?>
    <?php if (!$submission->isPending()): ?>
      <p>Reviewed by <?= $v->e($reviewerNames[$submission->reviewedBy ?? 0] ?? 'a teacher') ?> on <?= $v->datetime($submission->reviewedAt) ?>.</p>
      <?php if ($submission->feedback !== null): ?><div class="prose prose-small"><?= $v->markdown($submission->feedback) ?></div><?php endif; ?>
      <p><a href="/teach/reviews">Back to the queue</a></p>
    <?php elseif ($isOwnWork): ?>
      <p>This is your own submission. Another teacher needs to review it.</p>
    <?php else: ?>
      <form method="post" action="/teach/reviews/<?= $submission->id ?>" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('feedback', 'Feedback for the learner', [
            'type' => 'textarea', 'rows' => 8, 'value' => $old['feedback'] ?? '', 'error' => $errors['feedback'] ?? null,
            'hint' => 'Required when asking for changes. Say what is good and exactly what to fix.',
        ]) ?>
        <?php if (isset($errors['decision'])): ?><p class="field-error" id="decision"><?= $v->e($errors['decision']) ?></p><?php endif; ?>
        <div class="decision-buttons">
          <button type="submit" name="decision" value="approve" class="button">Approve</button>
          <button type="submit" name="decision" value="changes" class="button button-quiet">Ask for changes</button>
        </div>
        <p class="small muted">The learner sees your feedback on the lesson page and by email when email is set up.</p>
      </form>
    <?php endif; ?>
  </aside>
</div>
