<?php
/**
 * One meeting in a list. Expects $m (Meeting), $now (int), $userId (int).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Meetings\Meeting $m
 * @var int $now
 * @var int $userId
 */
$phase = $m->phase($now);
?>
<li class="lc-row is-<?= $v->e($phase) ?>">
  <span class="lc-date lc-date-meeting" aria-hidden="true">
    <span class="lc-day"><?= $v->datetime($m->startsAt, 'j') ?></span>
    <span class="lc-mon"><?= $v->datetime($m->startsAt, 'M') ?></span>
  </span>
  <div class="lc-body">
    <a class="lc-title" href="/meetings/<?= $m->id ?>"><?= $v->e($m->title) ?></a>
    <p class="lc-meta small">
      <span><?= $v->datetime($m->startsAt, 'D j M, H:i') ?>–<?= $v->datetime($m->endsAt, 'H:i') ?></span>
      <span><?= $m->hostId === $userId ? 'You host' : 'Hosted by ' . $v->e($m->hostName) ?></span>
      <span><?= $v->e($v->plural($m->invited, 'person', 'people')) ?> invited</span>
    </p>
  </div>
  <span class="lc-state">
    <?php if ($phase === 'cancelled'): ?><?= $v->badge('Cancelled', 'danger') ?>
    <?php elseif ($phase === 'live' || $phase === 'open'): ?><a class="button button-small lc-enter" href="/meetings/<?= $m->id ?>/room"><?= $v->icon('live') ?>Join</a>
    <?php elseif ($phase === 'ended'): ?><span class="small muted">Ended</span>
    <?php endif; ?>
  </span>
</li>
