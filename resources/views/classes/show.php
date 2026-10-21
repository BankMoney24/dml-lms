<?php
/**
 * One live class (ADR 0028): when and where, joining, and for teachers the
 * register, changes and cancelling.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\LiveClasses\ClassSession $session
 * @var bool $teaches
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var array<int, array{status: string, note: string, self_checked_in: bool, marked_by: ?int}> $marks
 * @var ?array{status: string, note: string, self_checked_in: bool} $myMark
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $teachers
 * @var list<\App\Modules\Courses\Course> $courses
 * @var int $now
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$s = $session;
$phase = $s->phase($now);
$statuses = \App\Modules\LiveClasses\ClassSessionRepository::STATUSES;
$school = $v->shared('tenant');
$o = static fn (string $k): string => $old[$k] ?? '';
$editErrors = array_diff_key($errors, ['reason' => 1]);
?>
<?= $v->breadcrumbs(['Live classes' => '/classes', $s->title => null]) ?>
<header class="page-head lc-head">
  <p class="lc-kicker small"><?= $v->e($s->groupName) ?><?php if ($s->courseTitle !== ''): ?> · <a href="/courses/<?= $v->e($s->courseSlug) ?>"><?= $v->e($s->courseTitle) ?></a><?php endif; ?></p>
  <h1><?= $v->e($s->title) ?></h1>
  <p class="lc-when"><strong><?= $v->datetime($s->startsAt, 'l j F') ?></strong>, <?= $v->datetime($s->startsAt, 'H:i') ?>–<?= $v->datetime($s->endsAt, 'H:i') ?> <span class="small muted">(<?= $v->e($school?->timezone ?? 'UTC') ?> time, <?= $s->minutes() ?> minutes)</span></p>
</header>
<?= $v->errorSummary($errors) ?>

<div class="lc-layout">
  <div class="lc-main">
    <section class="lc-card lc-card-<?= $v->e($phase) ?>" aria-labelledby="where-h">
      <h2 id="where-h" class="visually-hidden">Where and how to join</h2>
      <?php if ($phase === 'cancelled'): ?>
        <p class="lc-status"><?= $v->badge('Cancelled', 'danger') ?></p>
        <p><?= $v->e((string) $s->cancelledReason) ?></p>
      <?php else: ?>
        <?php if ($phase === 'live'): ?><p class="lc-status"><span class="lc-live">Live now</span></p><?php elseif ($phase === 'open'): ?><p class="lc-status"><span class="lc-live lc-soon">Starting soon</span></p><?php endif; ?>
        <?php if ($s->inRoom()): ?><p class="lc-where"><?= $v->icon('school') ?><span><strong>Room:</strong> <?= $v->e($s->location) ?></span></p><?php endif; ?>
        <?php if ($s->isOnline()): ?>
          <?php if (in_array($phase, ['open', 'live'], true)): ?>
            <div class="lc-join" data-join>
              <a class="button lc-join-button" href="<?= $v->e((string) $s->joinUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $v->icon('play') ?>Join the class</a>
              <?php if (!$teaches): ?><form method="post" action="/classes/<?= $s->id ?>/join" data-checkin hidden><?= $v->csrfField() ?></form><?php endif; ?>
              <span class="small muted"><?= $teaches ? 'Opens the meeting in a new tab.' : 'Opens the meeting in a new tab and marks you present.' ?></span>
            </div>
          <?php elseif ($phase === 'before'): ?>
            <p class="lc-where"><?= $v->icon('link') ?><span>Online. The Join button appears here 15 minutes before the start.</span></p>
          <?php else: ?>
            <p class="lc-where"><?= $v->icon('link') ?><span>This online class has ended.</span></p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($s->recordingUrl !== null): ?><p class="lc-where"><?= $v->icon('play') ?><a href="<?= $v->e($s->recordingUrl) ?>" rel="noopener noreferrer" target="_blank">Watch the recording</a></p><?php endif; ?>
      <?php endif; ?>
      <?php if (!$teaches && $myMark !== null): ?>
        <p class="small">Your attendance: <strong><?= $v->e($statuses[$myMark['status']] ?? $myMark['status']) ?></strong><?= $myMark['self_checked_in'] ? ' (you joined)' : '' ?><?= $myMark['note'] !== '' ? '. ' . $v->e($myMark['note']) : '' ?></p>
      <?php endif; ?>
    </section>

    <?php if ($s->description !== ''): ?>
    <section class="lc-notes" aria-labelledby="notes-h">
      <h2 id="notes-h">Before the class</h2>
      <div class="prose"><?= $v->markdown($s->description) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($teaches && !$s->isCancelled()): ?>
    <section class="lc-register" id="register" aria-labelledby="reg-h">
      <div class="settings-head"><h2 id="reg-h">Register</h2><span class="small muted"><?= $v->e($v->plural(count($learners), 'learner')) ?></span></div>
      <?php if ($phase === 'before'): ?>
        <p class="muted">Take the register once the class has started. Learners who press Join are marked present for you.</p>
      <?php elseif ($learners === []): ?>
        <p class="muted">There are no learners in <?= $v->e($s->groupName) ?> yet.</p>
      <?php else: ?>
      <form method="post" action="/classes/<?= $s->id ?>/register" data-register>
        <?= $v->csrfField() ?>
        <p class="lc-reg-tools"><button type="button" class="button button-small button-quiet" data-all-present hidden>Mark everyone not marked as present</button></p>
        <ul class="lc-reg-list">
          <?php foreach ($learners as $l): $m = $marks[$l['user_id']] ?? null; $current = $m['status'] ?? ''; ?>
            <li class="lc-reg-row">
              <fieldset>
                <legend><?= $v->e($l['name']) ?><?php if ($m !== null && $m['self_checked_in'] && $m['marked_by'] === null): ?> <span class="small muted">joined online</span><?php endif; ?></legend>
                <div class="lc-seg" role="presentation">
                  <?php foreach ($statuses as $key => $label): ?>
                    <label class="lc-seg-<?= $key ?>"><input type="radio" name="status[<?= $l['user_id'] ?>]" value="<?= $key ?>"<?= $current === $key ? ' checked' : '' ?>><span><?= $v->e($label) ?></span></label>
                  <?php endforeach; ?>
                </div>
                <label class="visually-hidden" for="note-<?= $l['user_id'] ?>">Note for <?= $v->e($l['name']) ?></label>
                <input class="lc-note" id="note-<?= $l['user_id'] ?>" name="note[<?= $l['user_id'] ?>]" maxlength="200" placeholder="Note (optional)" value="<?= $v->e($m['note'] ?? '') ?>">
              </fieldset>
            </li>
          <?php endforeach; ?>
        </ul>
        <button type="submit" class="button">Save the register</button>
        <p class="small muted">Parents who follow notices hear when their child is marked absent.</p>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="lc-side" aria-label="About this class">
    <dl class="facts small">
      <dt>Taught by</dt><dd><?= $teachers === [] ? '–' : $v->e(implode(', ', array_column($teachers, 'name'))) ?></dd>
      <dt>Where</dt><dd><?= $v->e(\App\Modules\LiveClasses\ClassSession::MODES[$s->mode] ?? $s->mode) ?></dd>
    </dl>
    <?php if ($teaches): ?>
      <details class="panel lc-edit"<?= $o('edit_open') === '1' ? ' open' : '' ?>>
        <summary>Change this class</summary>
        <form method="post" action="/classes/<?= $s->id ?>" class="form" novalidate>
          <?= $v->csrfField() ?>
          <?= $v->field('title', 'Title', ['value' => $o('title'), 'maxlength' => 160, 'error' => $editErrors['title'] ?? null]) ?>
          <div class="field-row">
            <?= $v->field('date', 'Day', ['type' => 'date', 'value' => $o('date'), 'error' => $editErrors['date'] ?? null]) ?>
            <?= $v->field('time', 'Starts', ['type' => 'time', 'value' => $o('time'), 'error' => $editErrors['time'] ?? null]) ?>
          </div>
          <?= $v->field('minutes', 'Minutes', ['type' => 'number', 'value' => $o('minutes'), 'error' => $editErrors['minutes'] ?? null]) ?>
          <?= $v->field('mode', 'Where', ['type' => 'select', 'options' => \App\Modules\LiveClasses\ClassSession::MODES, 'value' => $o('mode'), 'error' => $editErrors['mode'] ?? null]) ?>
          <?= $v->field('location', 'Room', ['value' => $o('location'), 'maxlength' => 200, 'error' => $editErrors['location'] ?? null]) ?>
          <?= $v->field('join_url', 'Meeting link', ['type' => 'url', 'value' => $o('join_url'), 'maxlength' => 500, 'error' => $editErrors['join_url'] ?? null]) ?>
          <?= $v->field('recording_url', 'Recording link (after the class)', ['type' => 'url', 'value' => $o('recording_url'), 'maxlength' => 500, 'error' => $editErrors['recording_url'] ?? null]) ?>
          <?php $courseOptions = ['' => 'No course'];
          foreach ($courses as $c) {
              $courseOptions[(string) $c->id] = $c->title;
          } ?>
          <?= $v->field('course_id', 'Course', ['type' => 'select', 'options' => $courseOptions, 'value' => $o('course_id'), 'error' => $editErrors['course_id'] ?? null]) ?>
          <?= $v->field('description', 'Notes for learners', ['type' => 'textarea', 'rows' => 3, 'value' => $o('description'), 'error' => $editErrors['description'] ?? null]) ?>
          <button type="submit" class="button button-small">Save changes</button>
        </form>
      </details>
      <?php if (!$s->isCancelled()): ?>
      <details class="panel lc-cancel"<?= $o('cancel_open') === '1' ? ' open' : '' ?>>
        <summary>Cancel this class</summary>
        <form method="post" action="/classes/<?= $s->id ?>/cancel" class="form">
          <?= $v->csrfField() ?>
          <?= $v->field('reason', 'Tell learners why', ['value' => $o('reason'), 'maxlength' => 255, 'error' => $errors['reason'] ?? null]) ?>
          <button type="submit" class="button button-small button-danger">Cancel the class</button>
        </form>
      </details>
      <?php endif; ?>
    <?php endif; ?>
  </aside>
</div>
