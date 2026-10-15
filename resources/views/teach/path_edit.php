<?php
/**
 * Editing one learning path (ADR 0023).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Paths\LearningPath $path
 * @var list<array{course: \App\Modules\Courses\Course, state: string, done: int, lessons: int}> $steps
 * @var list<\App\Modules\Courses\Course> $available
 * @var list<string> $problems
 * @var int $followers
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$base = '/teach/paths/' . $path->id;
?>
<?= $v->breadcrumbs(['Learning paths' => '/teach/paths', $path->title => null]) ?>
<header class="page-head page-head-row">
  <div>
    <h1><?= $v->e($path->title) ?></h1>
    <p class="page-head-meta">
      <?= $path->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?>
      <?= $v->badge($v->plural(count($steps), 'course')) ?>
      <?= $v->badge($v->plural($followers, 'follower')) ?>
      <?php if ($path->isPublished()): ?><a href="/paths/<?= $v->e($path->slug) ?>">See it as learners do</a><?php endif; ?>
    </p>
  </div>
  <form method="post" action="<?= $base ?>/publish">
    <?= $v->csrfField() ?>
    <input type="hidden" name="publish" value="<?= $path->isPublished() ? '0' : '1' ?>">
    <button type="submit" class="button<?= $path->isPublished() ? ' button-quiet' : '' ?>"<?= !$path->isPublished() && $problems !== [] ? ' disabled' : '' ?>><?= $path->isPublished() ? 'Unpublish' : 'Publish' ?></button>
  </form>
</header>
<?php if ($problems !== []): ?>
<div class="notice"><p><strong>Before you publish:</strong></p><ul><?php foreach ($problems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="teach-layout">
  <section aria-labelledby="steps" class="path-editor">
    <h2 id="steps">Courses, in order</h2>
    <?php if ($steps === []): ?>
      <p class="muted">No courses yet. Add the first one with the form.</p>
    <?php else: ?>
    <ol class="route route-edit">
      <?php foreach ($steps as $i => $s): $c = $s['course']; ?>
      <li class="route-step">
        <span class="route-node" aria-hidden="true"><?= $i + 1 ?></span>
        <div class="route-card">
          <h3><a href="/teach/courses/<?= $c->id ?>"><?= $v->e($c->title) ?></a></h3>
          <p class="route-meta"><?= $c->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?><span><?= $v->e($v->plural($s['lessons'], 'lesson')) ?></span><?php if ($c->isPaid()): ?><span class="route-price"><?= $v->e((string) $c->priceLabel()) ?></span><?php endif; ?></p>
          <div class="row-actions">
            <?php if ($i > 0): ?><form method="post" action="<?= $base ?>/courses/<?= $c->id ?>/move"><?= $v->csrfField() ?><input type="hidden" name="direction" value="up"><button type="submit" class="linklike small">Move up<span class="visually-hidden">: <?= $v->e($c->title) ?></span></button></form><?php endif; ?>
            <?php if ($i < count($steps) - 1): ?><form method="post" action="<?= $base ?>/courses/<?= $c->id ?>/move"><?= $v->csrfField() ?><input type="hidden" name="direction" value="down"><button type="submit" class="linklike small">Move down<span class="visually-hidden">: <?= $v->e($c->title) ?></span></button></form><?php endif; ?>
            <form method="post" action="<?= $base ?>/courses/<?= $c->id ?>/remove"><?= $v->csrfField() ?><button type="submit" class="linklike small">Remove<span class="visually-hidden">: <?= $v->e($c->title) ?></span></button></form>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <form method="post" action="<?= $base ?>/courses" class="form path-add" novalidate>
      <?= $v->csrfField() ?>
      <?php if ($available === []): ?>
        <p class="muted small">Every course is already in this path.</p>
      <?php else: ?>
      <?= $v->field('course_id', 'Add a course', [
          'type' => 'select', 'error' => $errors['course_id'] ?? null,
          'options' => ['' => 'Choose a course'] + array_combine(
              array_map(static fn ($c): string => (string) $c->id, $available),
              array_map(static fn ($c): string => $c->title . ($c->isPublished() ? '' : ' (draft)'), $available),
          ),
          'value' => '',
      ]) ?>
      <button type="submit" class="button button-small">Add to the end</button>
      <?php endif; ?>
    </form>
  </section>

  <aside>
    <section class="panel" aria-labelledby="details-h">
      <h2 id="details-h">Details</h2>
      <form method="post" action="<?= $base ?>" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('title', 'Name', ['value' => $old['title'], 'error' => $errors['title'] ?? null, 'maxlength' => 190]) ?>
        <?= $v->field('summary', 'Who it is for and what they will be able to do', ['type' => 'textarea', 'rows' => 5, 'value' => $old['summary'], 'error' => $errors['summary'] ?? null, 'optional' => true]) ?>
        <?= $v->field('level', 'Level', ['type' => 'select', 'options' => ['' => 'Not set'] + \App\Modules\Courses\Course::LEVELS, 'value' => $old['level'], 'error' => $errors['level'] ?? null]) ?>
        <button type="submit" class="button button-small">Save</button>
      </form>
    </section>
    <details class="panel" id="delete">
      <summary>Delete this path</summary>
      <p class="small">The courses stay as they are; only the path and its followers' list go. Type <strong><?= $v->e($path->slug) ?></strong> to confirm.</p>
      <form method="post" action="<?= $base ?>/delete" class="form">
        <?= $v->csrfField() ?>
        <label class="visually-hidden" for="confirm">Path address</label>
        <input id="confirm" name="confirm" autocomplete="off">
        <button type="submit" class="button button-small button-danger">Delete path</button>
      </form>
    </details>
  </aside>
</div>
