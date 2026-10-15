<?php
/**
 * A learning path (ADR 0023): its courses in order, and where this person stands.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Paths\LearningPath $path
 * @var array{steps: list<array{course: \App\Modules\Courses\Course, state: string, done: int, lessons: int}>, completed: int, total: int, next: ?\App\Modules\Courses\Course, complete: bool, percent: int} $progress
 * @var bool $following
 * @var bool $canLearn
 * @var bool $signedIn
 * @var bool $signUpOpen
 */
$slug = $v->e($path->slug);
$hours = array_sum(array_map(static fn (array $s): int => (int) $s['course']->durationHours, $progress['steps']));
?>
<?= $v->breadcrumbs(['Courses' => '/courses', $path->title => null]) ?>
<div class="path-layout">
  <section class="path-intro">
    <p class="path-kind"><?= $v->icon('link') ?>Learning path</p>
    <h1><?= $v->e($path->title) ?></h1>
    <p class="page-head-meta">
      <?php if ($path->levelLabel() !== null): ?><?= $v->badge($path->levelLabel(), 'level') ?><?php endif; ?>
      <?= $v->badge($v->plural($progress['total'], 'course')) ?>
      <?php if ($hours > 0): ?><?= $v->badge('About ' . $v->plural($hours, 'hour')) ?><?php endif; ?>
      <?php if ($v->can('course.manage')): ?><a href="/teach/paths/<?= $path->id ?>">Edit this path</a><?php endif; ?>
    </p>
    <?php if ($path->summary !== ''): ?><div class="prose"><?= $v->markdown($path->summary) ?></div><?php endif; ?>

    <div class="start-box path-start<?= $progress['complete'] ? ' is-done' : '' ?>">
      <?php if ($progress['complete'] && $signedIn): ?>
        <p class="start-title">Path complete</p>
        <p>You finished every course in this path. Well done.</p>
      <?php elseif ($following || ($signedIn && $progress['completed'] > 0)): ?>
        <p class="start-progress"><strong><?= $progress['completed'] ?> of <?= $progress['total'] ?></strong> courses done</p>
        <progress class="meter" max="<?= max(1, $progress['total']) ?>" value="<?= $progress['completed'] ?>" aria-label="Your progress on this path"></progress>
        <?php if ($progress['next'] !== null): ?><p class="start-action"><a class="button" href="/courses/<?= $v->e($progress['next']->slug) ?>">Continue: <?= $v->e($progress['next']->title) ?></a></p><?php endif; ?>
        <?php if (!$following && $canLearn): ?>
          <form method="post" action="/paths/<?= $slug ?>/start" class="start-alt"><?= $v->csrfField() ?><button type="submit" class="linklike">Follow this path</button> to see it on your home page.</form>
        <?php endif; ?>
      <?php elseif (!$signedIn): ?>
        <p class="start-title">Follow this path</p>
        <p>Do the courses in this order and track your progress across all of them.</p>
        <p class="start-action"><a class="button" href="<?= $signUpOpen ? '/join' : '/login' ?>"><?= $signUpOpen ? 'Create a free account to start' : 'Sign in to start' ?></a></p>
        <?php if ($signUpOpen): ?><p class="start-alt">Already have an account? <a href="/login">Sign in</a></p><?php endif; ?>
      <?php elseif ($canLearn): ?>
        <p class="start-title">Follow this path</p>
        <p>We put the courses on your home page in this order and start you on the first<?= $progress['next'] !== null && $progress['next']->isPaid() ? ' (it has a price, so you pay on its page first)' : '' ?>.</p>
        <form method="post" action="/paths/<?= $slug ?>/start" class="start-action"><?= $v->csrfField() ?><button type="submit" class="button">Start this path</button></form>
      <?php else: ?>
        <p class="muted">Learners follow this path from here.</p>
      <?php endif; ?>
      <?php if ($following): ?>
        <form method="post" action="/paths/<?= $slug ?>/leave" class="start-alt"><?= $v->csrfField() ?><button type="submit" class="linklike small">Stop following</button></form>
      <?php endif; ?>
    </div>
  </section>

  <section class="path-steps" aria-labelledby="steps-h">
    <h2 id="steps-h">The courses, in order</h2>
    <ol class="route">
      <?php foreach ($progress['steps'] as $i => $s): $c = $s['course']; $isNext = $progress['next'] !== null && $progress['next']->id === $c->id && $signedIn; ?>
      <li class="route-step is-<?= $v->e($s['state']) ?><?= $isNext ? ' is-next' : '' ?>">
        <span class="route-node" aria-hidden="true"><?= $s['state'] === 'completed' ? '✓' : $i + 1 ?></span>
        <div class="route-card">
          <h3><a href="/courses/<?= $v->e($c->slug) ?>"><?= $v->e($c->title) ?></a></h3>
          <?php if ($c->summary !== ''): ?><p class="route-summary"><?= $v->e(mb_strimwidth($c->summary, 0, 180, '…')) ?></p><?php endif; ?>
          <p class="route-meta">
            <?php if ($c->levelLabel() !== null): ?><?= $v->badge($c->levelLabel(), 'level') ?><?php endif; ?>
            <span><?= $v->e($v->plural($s['lessons'], 'lesson')) ?></span>
            <?php if ($c->durationHours !== null): ?><span><?= $v->e($v->plural($c->durationHours, 'hour')) ?></span><?php endif; ?>
            <?php if ($s['state'] === 'completed'): ?><?= $v->badge('Done', 'done') ?>
            <?php elseif ($s['state'] === 'enrolled'): ?><span class="route-progress"><?= $s['done'] ?> of <?= $s['lessons'] ?> lessons</span>
            <?php elseif ($c->isPaid()): ?><span class="route-price"><?= $v->e((string) $c->priceLabel()) ?></span>
            <?php elseif ($c->isOpen()): ?><span class="tag tag-open">Open</span>Free
            <?php else: ?><span>Free</span><?php endif; ?>
            <?php if ($isNext): ?><span class="route-next">Up next</span><?php endif; ?>
          </p>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
</div>
