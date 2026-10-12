<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<\App\Modules\Courses\Course> $courses */
?>
<ul class="course-list">
  <?php foreach ($courses as $course): ?>
  <li>
    <a href="/courses/<?= $v->e($course->slug) ?>"><?= $v->e($course->title) ?></a>
    <?php if ($course->levelLabel() !== null || $course->durationHours !== null): ?>
    <p class="course-meta small">
      <?php if ($course->levelLabel() !== null): ?><?= $v->badge($course->levelLabel(), 'level') ?><?php endif; ?>
      <?php if ($course->durationHours !== null): ?><span class="muted">About <?= $v->e($v->plural($course->durationHours, 'hour')) ?></span><?php endif; ?>
    </p>
    <?php endif; ?>
    <?php if ($course->summary !== ''): ?><p><?= $v->e(mb_strimwidth($course->summary, 0, 160, '…')) ?></p><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
