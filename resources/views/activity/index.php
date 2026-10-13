<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{who: string, what: string, at: string}> $rows */
?>
<header class="page-head">
  <h1>Activity</h1>
  <p class="lead">Sign-ins, changes to people and courses, and learner milestones, newest first. Times are in <?= $v->e($v->shared('tenant')?->timezone ?? 'UTC') ?>.</p>
</header>
<?php $schoolTab = 'activity'; include __DIR__ . '/../school/_tabs.php'; ?>
<?php if ($rows === []): ?>
  <?= $v->emptyState('Nothing yet', 'Activity appears here as people sign in, build courses and learn.') ?>
<?php else: ?>
<ol class="activity">
  <?php foreach ($rows as $r): ?>
  <li>
    <span class="activity-text"><strong><?= $v->e($r['who']) ?></strong> <?= $v->e($r['what']) ?></span>
    <time class="small muted"><?= $v->datetime($r['at']) ?></time>
  </li>
  <?php endforeach; ?>
</ol>
<nav class="pager" aria-label="Pages">
  <?php if (!$isFirstPage): ?><a href="/activity">Newest</a><?php endif; ?>
  <?php if ($nextBefore !== null): ?><a href="/activity?before=<?= (int) $nextBefore ?>">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
