<?php
/**
 * One course card. Expects $card (from CourseCards); the whole card is a link.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{course: \App\Modules\Courses\Course, modules: list<array{title: string, lessons: int, done: int, labs: int}>, lessons: int, labs: int, done: int, enrolled: bool, completed: bool} $card
 */
$c = $card['course'];
?>
<article class="course-card<?= $card['completed'] ? ' is-complete' : '' ?>">
  <?php include __DIR__ . '/_board.php'; ?>
  <h3 class="course-card-title"><a href="/courses/<?= $v->e($c->slug) ?>"><?= $v->e($c->title) ?></a></h3>
  <?php if ($c->summary !== ''): ?><p class="course-card-summary"><?= $v->e(mb_strimwidth($c->summary, 0, 180, '…')) ?></p><?php endif; ?>
  <?php if ($c->trackLabel() !== null): ?><p class="course-card-track"><?= $v->e($c->trackLabel()) ?></p><?php endif; ?>
  <p class="course-card-meta">
    <?php if ($c->levelLabel() !== null): ?><?= $v->badge($c->levelLabel(), 'level') ?><?php endif; ?>
    <?php if ($c->durationHours !== null): ?><span><?= $v->e('About ' . $v->plural($c->durationHours, 'hour')) ?></span><?php endif; ?>
    <span><?= $v->e($v->plural($card['lessons'], 'lesson')) ?></span>
    <?php if (($card['labs'] ?? 0) > 0): ?><span class="course-card-labs"><?= $v->e($v->plural($card['labs'], 'lab')) ?></span><?php endif; ?>
    <?php if ($c->isPaid() && !$card['enrolled']): ?><span class="course-card-price"><?= $v->e((string) $c->priceLabel()) ?></span><?php endif; ?>
  </p>
  <?php if ($card['completed']): ?>
    <p class="course-card-status"><?= $v->badge('Completed', 'done') ?></p>
  <?php elseif ($card['enrolled']): ?>
    <p class="course-card-status"><?= $v->e($card['done'] . ' of ' . $card['lessons'] . ' lessons done') ?></p>
  <?php endif; ?>
</article>
