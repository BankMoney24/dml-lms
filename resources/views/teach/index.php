<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="page-head page-head-row">
  <h1>Your courses</h1>
  <a class="button" href="/teach/courses/new">New course</a>
</section>
<?php if ($rows === []): ?>
  <section class="empty">
    <h2>Create your first course</h2>
    <p>A course is a set of modules, each with reading lessons and quizzes. You can publish it when it has at least one lesson.</p>
  </section>
<?php else: ?>
<table class="table">
  <thead><tr><th scope="col">Course</th><th scope="col">Lessons</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): $c = $row['course']; ?>
    <tr>
      <td><a href="/teach/courses/<?= $c->id ?>"><?= $v->e($c->title) ?></a></td>
      <td><?= $row['lesson_count'] ?></td>
      <td><span class="status status-<?= $v->e($c->status) ?>"><?= $c->isPublished() ? 'Published' : 'Draft' ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
