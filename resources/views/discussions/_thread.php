<?php
/**
 * One question with its answers and the reply form (ADR 0022). Used under
 * lessons and in the teachers' inbox.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Discussions\Question $q
 * @var int $meId
 * @var bool $isStaff
 * @var string $coursePath  /courses/{slug}, escaped
 * @var string $back        "teach" when posted from the inbox
 * @var array<string, string> $drafts
 */
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = mb_substr((string) ($parts[0] ?? '?'), 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

    return mb_strtoupper($first . $last);
};
$mayAccept = $q->isOpen() && ($q->userId === $meId || $isStaff);
$draft = $drafts['answer-' . $q->id] ?? '';
?>
<article class="qa<?= $q->isAnswered() ? ' is-answered' : '' ?><?= $q->isOpen() ? '' : ' is-hidden' ?>" id="q<?= $q->id ?>">
  <header class="qa-head">
    <span class="qa-avatar" aria-hidden="true"><?= $v->e($initials($q->authorName)) ?></span>
    <span class="qa-who"><span class="qa-name"><strong><?= $v->e($q->authorName) ?></strong><?= $q->userId === $meId ? ' <span class="muted">(you)</span>' : '' ?></span>
      <span class="small muted">asked <?= $v->datetime($q->createdAt, 'j M, H:i') ?></span></span>
    <?php if (!$q->isOpen()): ?><?= $v->badge('Hidden from learners', 'warn') ?>
    <?php elseif ($q->acceptedAnswerId !== null): ?><?= $v->badge('Answered', 'ok') ?>
    <?php elseif ($q->staffAnswered): ?><?= $v->badge('Teacher replied', 'ok') ?>
    <?php else: ?><?= $v->badge($q->answerCount === 0 ? 'No answers yet' : $v->plural($q->answerCount, 'answer')) ?><?php endif; ?>
  </header>
  <div class="qa-body prose"><?= $v->markdown($q->body) ?></div>
  <div class="qa-tools">
    <?php if ($q->userId === $meId): ?>
      <form method="post" action="<?= $coursePath ?>/questions/<?= $q->id ?>/delete"><?= $v->csrfField() ?><button type="submit" class="linklike small">Delete my question</button></form>
    <?php endif; ?>
    <?php if ($isStaff): ?>
      <form method="post" action="<?= $coursePath ?>/questions/moderate"><?= $v->csrfField() ?><input type="hidden" name="kind" value="question"><input type="hidden" name="id" value="<?= $q->id ?>"><input type="hidden" name="hide" value="<?= $q->isOpen() ? '1' : '0' ?>"><input type="hidden" name="show" value="<?= $v->e($inboxShow ?? 'waiting') ?>"><button type="submit" class="linklike small"><?= $q->isOpen() ? 'Hide from learners' : 'Show again' ?></button></form>
    <?php endif; ?>
  </div>

  <?php if ($q->answers !== []): ?>
  <ol class="qa-answers">
    <?php foreach ($q->answers as $a): $accepted = $a->id === $q->acceptedAnswerId; ?>
    <li class="qa-answer<?= $accepted ? ' is-accepted' : '' ?><?= $a->byStaff ? ' is-staff' : '' ?><?= $a->isVisible() ? '' : ' is-hidden' ?>" id="a<?= $a->id ?>">
      <header class="qa-head">
        <span class="qa-avatar" aria-hidden="true"><?= $v->e($initials($a->authorName)) ?></span>
        <span class="qa-who"><span class="qa-name"><strong><?= $v->e($a->authorName) ?></strong><?= $a->userId === $meId ? ' <span class="muted">(you)</span>' : '' ?><?= $a->byStaff ? ' ' . $v->badge('Teacher', 'level') : '' ?></span>
          <span class="small muted"><?= $v->datetime($a->createdAt, 'j M, H:i') ?></span></span>
        <?php if ($accepted): ?><span class="qa-accepted"><?= $v->icon('review') ?>The answer</span><?php endif; ?>
        <?php if (!$a->isVisible()): ?><?= $v->badge('Hidden from learners', 'warn') ?><?php endif; ?>
      </header>
      <div class="qa-body prose"><?= $v->markdown($a->body) ?></div>
      <div class="qa-tools">
        <?php if ($mayAccept && $a->isVisible()): ?>
          <form method="post" action="<?= $coursePath ?>/questions/<?= $q->id ?>/accept"><?= $v->csrfField() ?><input type="hidden" name="answer" value="<?= $accepted ? 0 : $a->id ?>"><?php if ($back !== ''): ?><input type="hidden" name="back" value="<?= $v->e($back) ?>"><?php endif; ?><button type="submit" class="linklike small"><?= $accepted ? 'Unmark' : 'Mark as the answer' ?></button></form>
        <?php endif; ?>
        <?php if ($a->userId === $meId): ?>
          <form method="post" action="<?= $coursePath ?>/answers/<?= $a->id ?>/delete"><?= $v->csrfField() ?><?php if ($back !== ''): ?><input type="hidden" name="back" value="<?= $v->e($back) ?>"><?php endif; ?><button type="submit" class="linklike small">Delete</button></form>
        <?php endif; ?>
        <?php if ($isStaff): ?>
          <form method="post" action="<?= $coursePath ?>/questions/moderate"><?= $v->csrfField() ?><input type="hidden" name="kind" value="answer"><input type="hidden" name="id" value="<?= $a->id ?>"><input type="hidden" name="hide" value="<?= $a->isVisible() ? '1' : '0' ?>"><input type="hidden" name="show" value="<?= $v->e($inboxShow ?? 'waiting') ?>"><button type="submit" class="linklike small"><?= $a->isVisible() ? 'Hide' : 'Show again' ?></button></form>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <?php if ($q->isOpen()): ?>
  <details class="qa-reply"<?= $draft !== '' || ($openReply ?? false) ? ' open' : '' ?>>
    <summary><?= $q->answerCount === 0 ? 'Answer this question' : 'Add an answer' ?></summary>
    <form method="post" action="<?= $coursePath ?>/questions/<?= $q->id ?>/answers" class="qa-form">
      <?= $v->csrfField() ?>
      <?php if ($back !== ''): ?><input type="hidden" name="back" value="<?= $v->e($back) ?>"><?php endif; ?>
      <label class="visually-hidden" for="answer-<?= $q->id ?>">Your answer</label>
      <textarea id="answer-<?= $q->id ?>" name="answer" rows="4" maxlength="5000" placeholder="Explain it the way you would to a classmate. Put code between lines of three backticks (```)."><?= $v->e($draft) ?></textarea>
      <button type="submit" class="button button-small">Post answer</button>
    </form>
  </details>
  <?php endif; ?>
</article>
