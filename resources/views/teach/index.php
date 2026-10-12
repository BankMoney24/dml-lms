<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<header class="page-head page-head-row">
  <div>
    <h1>Your courses</h1>
    <p class="lead">Build courses, publish them, and follow how learners are doing.</p>
  </div>
  <a class="button" href="/teach/courses/new">New course</a>
</header>
<?php if ($rows === []): ?>
  <?= $v->emptyState(
      'Create your first course',
      'A course is a set of modules, each with reading lessons and quizzes. You can publish it once it has at least one lesson.',
      '/teach/courses/new',
      'New course',
  ) ?>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Course</th><th scope="col" class="num hide-narrow">Lessons</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): $c = $row['course']; ?>
    <tr>
      <td><a href="/teach/courses/<?= $c->id ?>"><?= $v->e($c->title) ?></a></td>
      <td class="num hide-narrow"><?= $row['lesson_count'] ?></td>
      <td><?= $c->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?></td>
      <td class="num"><a href="/teach/courses/<?= $c->id ?>/progress">Progress</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
