<?php
/**
 * One course card. Expects $card (from CourseCards); the whole card is a link.
 * Every card has the same parts in the same places, so a row of cards lines up:
 * the module trace, what kind of course, the title and summary, then a footer
 * with the facts on the left and where the learner stands (or the price) on the right.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{course: \App\Modules\Courses\Course, modules: list<array{title: string, lessons: int, done: int, labs: int}>, lessons: int, labs: int, done: int, enrolled: bool, completed: bool} $card
 */
$c = $card['course'];
$pct = $card['lessons'] === 0 ? 0 : (int) round($card['done'] * 100 / $card['lessons']);
?>
<article class="course-card<?= $card['completed'] ? ' is-complete' : '' ?><?= $card['enrolled'] ? ' is-enrolled' : '' ?>">
  <div class="course-cover">
    <?php include __DIR__ . '/_cover.php'; ?>
    <?php include __DIR__ . '/_board.php'; ?>
  </div>
  <?php if ($c->trackLabel() !== null): ?><p class="course-card-track"><?= $v->e($c->trackLabel()) ?></p><?php endif; ?>
  <?php $cardTag = ($cardHeading ?? 'h3') === 'h2' ? 'h2' : 'h3'; ?>
  <<?= $cardTag ?> class="course-card-title"><a href="/courses/<?= $v->e($c->slug) ?>"><?= $v->e($c->title) ?></a></<?= $cardTag ?>>
  <?php if (isset($cardReasons) && is_array($cardReasons) && isset($cardReasons[$c->id])): ?><p class="course-card-why"><?= $v->icon('sparkle') ?>For you: <?= $v->e(implode(', ', $cardReasons[$c->id])) ?></p><?php endif; ?>
  <?php if ($c->summary !== ''): ?><p class="course-card-summary"><?= $v->e(mb_strimwidth($c->summary, 0, 220, '…')) ?></p><?php endif; ?>
  <div class="course-card-foot">
    <p class="course-card-meta">
      <?php if ($c->levelLabel() !== null): ?><?= $v->badge($c->levelLabel(), 'level') ?><?php endif; ?>
      <?php if ($c->durationHours !== null): ?><span><?= $v->e($v->plural($c->durationHours, 'hour')) ?></span><?php endif; ?>
      <span><?= $v->e($v->plural($card['lessons'], 'lesson')) ?></span>
      <?php if (($card['labs'] ?? 0) > 0): ?><span class="course-card-labs"><?= $v->e($v->plural($card['labs'], 'lab')) ?></span><?php endif; ?>
    </p>
    <?php if ($card['completed']): ?>
      <p class="course-card-end"><?= $v->badge('Completed', 'done') ?></p>
    <?php elseif ($card['enrolled']): ?>
      <div class="course-card-end course-card-progress">
        <span><?= $v->e($card['done'] . ' of ' . $card['lessons'] . ' done') ?></span>
        <progress class="meter meter-thin" max="100" value="<?= $pct ?>" aria-label="<?= $v->e($c->title) ?>: <?= $pct ?>% done"></progress>
      </div>
    <?php elseif ($c->isPaid()): ?>
      <p class="course-card-end course-card-price"><?= $v->e((string) $c->priceLabel()) ?></p>
    <?php else: ?>
      <p class="course-card-end course-card-free"><?php if ($c->isOpen()): ?><span class="tag tag-open" title="Anyone can read the lessons, no account needed">Open</span><?php endif; ?>Free</p>
    <?php endif; ?>
  </div>
</article>
