<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{id: int, submitted_at: string, learner: string, course_id: int, course: string, lesson: string, resubmission: bool}> $queue */
?>
<?= $v->breadcrumbs(['Your courses' => '/teach', 'Reviews' => null]) ?>
<header class="page-head">
  <h1>Reviews</h1>
  <p class="lead">Assignments learners have submitted, oldest first. Approving one completes that lesson for the learner.</p>
</header>
<?php if ($queue === []): ?>
  <?= $v->emptyState('Nothing to review', 'When learners submit work for an assignment lesson, it appears here.') ?>
<?php else: ?>
<p class="actions"><a class="button" href="/teach/reviews/<?= $queue[0]['id'] ?>">Start with the oldest</a></p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Learner</th><th scope="col">Assignment</th><th scope="col" class="hide-narrow">Sent</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
  <tbody>
  <?php foreach ($queue as $row): ?>
    <tr>
      <td><?= $v->e($row['learner']) ?><?php if ($row['resubmission']): ?> <?= $v->badge('Resubmitted') ?><?php endif; ?></td>
      <td><?= $v->e($row['lesson']) ?><span class="small muted"><?= $v->e($row['course']) ?></span></td>
      <td class="hide-narrow"><?= $v->datetime($row['submitted_at']) ?></td>
      <td class="num"><a href="/teach/reviews/<?= $row['id'] ?>">Review<span class="visually-hidden"> <?= $v->e($row['learner'] . ': ' . $row['lesson']) ?></span></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
