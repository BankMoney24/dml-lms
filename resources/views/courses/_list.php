<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<\App\Modules\Courses\Course> $courses */
?>
<ul class="course-list">
  <?php foreach ($courses as $course): ?>
  <li>
    <a href="/courses/<?= $v->e($course->slug) ?>"><?= $v->e($course->title) ?></a>
    <?php if ($course->summary !== ''): ?><p><?= $v->e(mb_strimwidth($course->summary, 0, 160, '…')) ?></p><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
