<?php
/**
 * Meetings (ADR 0030): the ones this person hosts or is invited to.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Meetings\Meeting> $upcoming
 * @var list<\App\Modules\Meetings\Meeting> $past
 * @var bool $mayHost
 * @var bool $ready
 * @var int $userId
 * @var int $now
 */
?>
<header class="page-head page-head-row">
  <div>
    <h1>Meetings</h1>
    <p class="lead">Video meetings in the school’s online classroom: staff meetings, parents’ evenings, a call with one family.</p>
  </div>
  <?php if ($mayHost && $ready): ?><a class="button" href="/meetings/new"><?= $v->icon('live') ?>New meeting</a><?php endif; ?>
</header>

<?php if ($mayHost && !$ready): ?>
  <p class="notice">The online classroom is not connected yet, so meetings cannot start. <?= $v->can('school.manage') ? 'Connect it under <a href="/school/classroom">School, Classroom</a>.' : 'Ask an administrator to connect it.' ?></p>
<?php endif; ?>

<section class="lc-section" aria-labelledby="up-h">
  <h2 id="up-h">Coming up</h2>
  <?php if ($upcoming === []): ?>
    <?= $v->emptyState('No meetings planned', $mayHost ? 'Start one now or plan one for later, and invite staff, a class, its parents or anyone in the school.' : 'When someone invites you to a meeting, it shows here, on your home page and in your calendar.') ?>
  <?php else: ?>
    <ul class="lc-list"><?php foreach ($upcoming as $m): ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
  <?php endif; ?>
</section>

<?php if ($past !== []): ?>
<section class="lc-section" aria-labelledby="past-h">
  <h2 id="past-h">Earlier</h2>
  <ul class="lc-list lc-list-compact"><?php foreach ($past as $m): ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
</section>
<?php endif; ?>
