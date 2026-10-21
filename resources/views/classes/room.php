<?php
/**
 * The online classroom for one class (ADR 0029): the school's Jitsi Meet,
 * embedded, with a low-data choice, the class materials beside it, and a
 * calm way back in when the connection drops.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\LiveClasses\ClassSession $session
 * @var bool $teaches
 * @var array{ok: bool, reason: string} $access
 * @var ?array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool} $pass
 * @var list<\App\Modules\Files\StoredFile> $materials
 * @var int $now
 */
$s = $session;
$opensAt = $s->startTs() - ($teaches ? \App\Modules\LiveClasses\Classroom\Classroom::TEACHER_EARLY_MINUTES : \App\Modules\LiveClasses\ClassSession::JOIN_EARLY_MINUTES) * 60;
?>
<?= $v->breadcrumbs(['Live classes' => '/classes', $s->title => '/classes/' . $s->id, 'Classroom' => null]) ?>

<?php if ($pass === null): ?>
<section class="room-closed">
  <h1><?= $v->e($s->title) ?></h1>
  <?php if ($access['reason'] === 'before'): ?>
    <p class="lead">The classroom opens at <strong><?= $v->datetime(gmdate('Y-m-d H:i:s', $opensAt), 'H:i') ?></strong>, <?= $teaches ? '30' : '15' ?> minutes before the class starts.</p>
    <p class="muted" data-reload-at="<?= $opensAt ?>" data-now="<?= $now ?>">This page opens the classroom by itself when it is time, if you leave it open.</p>
  <?php elseif ($access['reason'] === 'not-set-up'): ?>
    <p class="lead">The school’s online classroom is not connected at the moment.</p>
    <p><?= $teaches ? 'Ask an administrator to check School, Classroom, or change this class to use a meeting link.' : 'Your teacher has been told on their class page. Check back shortly.' ?></p>
  <?php elseif ($access['reason'] === 'cancelled'): ?>
    <p class="lead">This class was cancelled.</p>
    <p><?= $v->e((string) $s->cancelledReason) ?></p>
  <?php else: ?>
    <p class="lead">This class has ended.</p>
    <p>Materials, and the recording if your teacher shares one, stay on the class page.</p>
  <?php endif; ?>
  <p><a class="button button-quiet" href="/classes/<?= $s->id ?>">Back to the class page</a></p>
</section>
<?php else: ?>
<div class="room" data-room
     data-domain="<?= $v->e($pass['domain']) ?>"
     data-room-name="<?= $v->e($pass['room']) ?>"
     data-jwt="<?= $v->e($pass['token']) ?>"
     data-moderator="<?= $pass['moderator'] ? '1' : '0' ?>"
     data-low-data="<?= $pass['lowData'] ? '1' : '0' ?>"
     data-subject="<?= $v->e($s->title) ?>"
     data-signal="/classes/<?= $s->id ?>/signal"
     data-csrf="<?= $v->e((string) ($v->shared('csrf_token') ?? '')) ?>"
     data-ends="<?= $s->endTs() ?>"
     data-now="<?= $now ?>">
  <header class="room-head">
    <div class="room-title">
      <p class="room-kicker small"><span class="lc-live<?= $s->phase($now) === 'live' ? '' : ' lc-soon' ?>"><?= $s->phase($now) === 'live' ? 'Live' : 'Starting soon' ?></span> <?= $v->e($s->groupName) ?>, <?= $v->datetime($s->startsAt, 'H:i') ?>–<?= $v->datetime($s->endsAt, 'H:i') ?></p>
      <h1><?= $v->e($s->title) ?></h1>
    </div>
    <fieldset class="room-modes" data-room-modes>
      <legend class="visually-hidden">How much data to use</legend>
      <label><input type="radio" name="room-mode" value="audio"><span><?= $v->icon('headphones') ?>Audio only</span></label>
      <label><input type="radio" name="room-mode" value="saver"><span><?= $v->icon('video') ?>Data saver</span></label>
      <label><input type="radio" name="room-mode" value="video"><span><?= $v->icon('video') ?>Full video</span></label>
    </fieldset>
  </header>

  <p class="room-status" data-room-status role="status" aria-live="polite">Connecting to the classroom…</p>

  <div class="room-layout">
    <div class="room-stage" id="room-stage" data-room-stage>
      <noscript><p class="room-stage-note">The classroom needs JavaScript turned on in your browser.</p></noscript>
    </div>
    <aside class="room-side" aria-label="During the class">
      <section class="room-panel" aria-labelledby="room-help-h">
        <h2 id="room-help-h" class="small-heading">If something goes wrong</h2>
        <ul class="plain-list small room-tips">
          <li><strong>Choppy or stuck?</strong> Switch to Data saver or Audio only above.</li>
          <li><strong>Can’t hear?</strong> Check your phone is not on silent and the browser may use the speaker.</li>
          <li><strong>Dropped out?</strong> <button type="button" class="linklike" data-room-rejoin>Rejoin the class</button>. You stay marked present.</li>
        </ul>
        <?php if (!$teaches): ?><p class="small muted">Your microphone starts muted. Raise your hand to speak.</p><?php else: ?><p class="small muted">Learners start muted. You can mute everyone and share your screen from the toolbar.</p><?php endif; ?>
      </section>
      <?php if ($materials !== []): ?>
      <section class="room-panel" aria-labelledby="room-mat-h">
        <h2 id="room-mat-h" class="small-heading">Class materials</h2>
        <?php $fileList = $materials; $removeUrl = null; include __DIR__ . '/../files/_list.php'; ?>
      </section>
      <?php endif; ?>
      <p class="small"><a href="/classes/<?= $s->id ?>">Leave the classroom</a></p>
    </aside>
  </div>
</div>
<script src="https://<?= $v->e($pass['domain']) ?>/external_api.js" defer></script>
<script src="<?= $v->asset('classroom.js') ?>" defer></script>
<?php endif; ?>
<?php if ($pass === null && $access['reason'] === 'before'): ?>
<script src="<?= $v->asset('classroom.js') ?>" defer></script>
<?php endif; ?>
