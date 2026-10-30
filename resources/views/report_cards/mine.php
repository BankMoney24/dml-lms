<?php
/**
 * The learner's published report cards (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\ReportCards\ReportCard> $cards
 */
?>
<header class="page-head">
  <h1>Report cards</h1>
  <p class="lead">Your report cards for each term, once your teacher shares them.</p>
</header>
<?php if ($cards === []): ?>
  <?= $v->emptyState('No report cards yet', 'When your teacher shares a term\'s report card, it appears here and you get a notification.', '/exams', 'Go to exams') ?>
<?php else: ?>
<ul class="plain-list rc-list">
  <?php foreach ($cards as $c): ?>
  <li class="set-course"><span><a href="/report-cards/<?= $c->id ?>"><?= $v->e($c->title) ?></a><span class="small muted"><?= $v->e($c->groupName) ?>, shared <?= $v->datetime((string) $c->publishedAt, 'j M Y') ?></span></span></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
