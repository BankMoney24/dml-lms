<?php
/**
 * One live class in a list: a date tile, the time, title, where, and its state.
 * Expects $s (ClassSession), $now (int) or $rowNow (int, wins when set), optional $showGroup (bool), $mark (?string),
 * $linkTitle (bool, false where the reader cannot open the class page).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\LiveClasses\ClassSession $s
 * @var int $now
 */
$phase = $s->phase(isset($rowNow) ? (int) $rowNow : (int) $now);
$showGroup ??= true;
$mark ??= null;
$markLabels = \App\Modules\LiveClasses\ClassSessionRepository::STATUSES + ['unmarked' => 'Not marked'];
$markTones = ['present' => 'done', 'late' => 'draft', 'absent' => 'danger', 'excused' => 'neutral'];
?>
<li class="lc-row is-<?= $v->e($phase) ?>">
  <span class="lc-date" aria-hidden="true">
    <span class="lc-day"><?= $v->datetime($s->startsAt, 'j') ?></span>
    <span class="lc-mon"><?= $v->datetime($s->startsAt, 'M') ?></span>
  </span>
  <div class="lc-body">
    <?php if ($linkTitle ?? true): ?><a class="lc-title" href="/classes/<?= $s->id ?>"><?= $v->e($s->title) ?></a><?php else: ?><span class="lc-title"><?= $v->e($s->title) ?></span><?php endif; ?>
    <p class="lc-meta small">
      <span><?= $v->datetime($s->startsAt, 'D j M, H:i') ?>–<?= $v->datetime($s->endsAt, 'H:i') ?></span>
      <?php if ($showGroup): ?><span><?= $v->e($s->groupName) ?></span><?php endif; ?>
      <span><?= $v->e($s->whereLabel()) ?></span>
    </p>
  </div>
  <span class="lc-state">
    <?php if ($phase === 'cancelled'): ?><?= $v->badge('Cancelled', 'danger') ?>
    <?php elseif (($phase === 'live' || $phase === 'open') && $s->inClassroom() && ($linkTitle ?? true)): ?><a class="button button-small lc-enter" href="/classes/<?= $s->id ?>/room"><?= $v->icon('live') ?><?= $phase === 'live' ? 'Enter' : 'Enter early' ?></a>
    <?php elseif ($phase === 'live'): ?><span class="lc-live">Live now</span>
    <?php elseif ($phase === 'open'): ?><span class="lc-live lc-soon">Starting soon</span>
    <?php elseif ($mark !== null): ?><?= $v->badge($markLabels[$mark] ?? $mark, $markTones[$mark] ?? 'neutral') ?>
    <?php elseif ($phase === 'ended'): ?><span class="small muted">Ended</span>
    <?php endif; ?>
  </span>
</li>
