<?php
/**
 * Teach → Learning paths (ADR 0023).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Paths\LearningPath> $paths
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
?>
<header class="page-head">
  <h1>Learning paths</h1>
  <p class="lead">Put courses in a recommended order, such as "Junior web developer" or "IT support technician". Learners follow a path and see their progress across all of its courses.</p>
</header>
<div class="teach-layout">
  <section aria-labelledby="list-h">
    <h2 id="list-h" class="visually-hidden">Your paths</h2>
    <?php if ($paths === []): ?>
      <?= $v->emptyState('No paths yet', 'Create one on this page, then add two or more published courses to it.') ?>
    <?php else: ?>
    <ul class="path-list">
      <?php foreach ($paths as $p): ?>
      <li>
        <a href="/teach/paths/<?= $p->id ?>"><?= $v->e($p->title) ?></a>
        <span class="small muted"><?= $v->e($v->plural($p->courseCount, 'course')) ?></span>
        <?= $p->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <aside>
    <section class="panel" aria-labelledby="new-h">
      <h2 id="new-h">New path</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/teach/paths" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('title', 'Name', ['value' => $old['title'], 'error' => $errors['title'] ?? null, 'maxlength' => 190, 'hint' => 'What the learner becomes or can do, for example "Junior web developer".']) ?>
        <?= $v->field('summary', 'Who it is for', ['type' => 'textarea', 'rows' => 3, 'value' => $old['summary'], 'error' => $errors['summary'] ?? null, 'optional' => true]) ?>
        <button type="submit" class="button button-small">Create path</button>
      </form>
    </section>
  </aside>
</div>
