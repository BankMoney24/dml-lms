<?php
/**
 * The teachers' question inbox (ADR 0022): questions from every course,
 * oldest waiting first, answered right here.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $show
 * @var list<\App\Modules\Discussions\Question> $questions
 * @var int $waiting
 * @var array<string, string> $drafts
 */
$me = $v->shared('user');
$meId = $me?->id ?? 0;
$isStaff = true;
$back = 'teach';
$inboxShow = $show;
$openReply = $show === 'waiting';
?>
<header class="page-head">
  <h1>Questions</h1>
  <p class="lead">What learners ask under lessons. Answer here; they get a notification and see it under the lesson.</p>
</header>
<nav class="tabs" aria-label="Which questions">
  <a href="/teach/questions"<?= $show === 'waiting' ? ' aria-current="page"' : '' ?>>Waiting for a teacher <span class="tab-count">(<?= $waiting ?>)</span></a>
  <a href="/teach/questions?show=all"<?= $show === 'all' ? ' aria-current="page"' : '' ?>>All recent</a>
  <a href="/teach/questions?show=hidden"<?= $show === 'hidden' ? ' aria-current="page"' : '' ?>>Hidden</a>
</nav>
<?php if ($questions === []): ?>
  <?= $show === 'waiting'
      ? $v->emptyState('Nothing waiting', 'Every question has a teacher\'s reply or an accepted answer. New ones appear here and in your notifications.')
      : $v->emptyState('No questions here', $show === 'hidden' ? 'Nothing has been hidden.' : 'Learners have not asked anything yet.') ?>
<?php else: ?>
<div class="qa-inbox">
  <?php foreach ($questions as $q): $coursePath = '/courses/' . $v->e($q->courseSlug); ?>
  <section class="qa-inbox-item">
    <p class="qa-where"><a href="<?= $v->e($q->lessonUrl()) ?>"><?= $v->e($q->courseTitle) ?>: <?= $v->e($q->lessonTitle) ?></a><span class="small muted"><?= $show === 'waiting' ? ' waiting since ' . $v->datetime($q->lastActivityAt, 'j M, H:i') : '' ?></span></p>
    <?php include __DIR__ . '/../discussions/_thread.php'; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
