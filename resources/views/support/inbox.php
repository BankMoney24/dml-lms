<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Support\SupportRequest> $requests
 * @var array<string, int> $counts
 * @var string $filter
 */
$schoolTab = 'support';
$statusTabs = ['open' => 'Needs an answer', 'answered' => 'Waiting for them', 'resolved' => 'Resolved'];
?>
<header class="page-head">
  <h1>Help desk</h1>
  <p class="lead">Questions from learners, teachers and parents. The oldest unanswered ones come first.</p>
</header>
<?php include __DIR__ . '/../school/_tabs.php'; ?>
<nav class="tabs tabs-quiet" aria-label="Show requests">
  <?php foreach ($statusTabs as $key => $label): ?>
    <a href="/school/support<?= $key === 'open' ? '' : '?status=' . $key ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
  <?php endforeach; ?>
</nav>
<?php if ($requests === []): ?>
  <p class="muted"><?= $filter === 'open' ? 'Nothing waiting. New requests show up here and in your notifications.' : 'Nothing here.' ?></p>
<?php else: ?>
<ul class="help-list">
  <?php foreach ($requests as $r): [$label, $tone] = $r->statusFor(true); ?>
  <li><a href="/help/<?= $r->id ?>"><span class="help-subject"><?= $v->e($r->subject) ?></span><span class="small muted"><?= $v->e($r->requesterName) ?>, <?= $v->e($r->categoryLabel()) ?>, <?= $v->datetime($r->updatedAt) ?></span></a><?= $v->badge($label, $tone) ?></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
