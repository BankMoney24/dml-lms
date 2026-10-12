<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var ?\App\Modules\Learning\Enrolment $enrolment */
$user = $v->shared('user');
$lessonCount = array_sum(array_map(static fn ($m) => count($m['lessons']), $outline));
$firstOpen = null;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        if ($firstOpen === null && !in_array($lesson->id, $completedIds, true)) {
            $firstOpen = $lesson;
        }
    }
}
?>
<div class="course-layout">
  <section class="course-intro">
    <h1><?= $v->e($course->title) ?></h1>
    <?= $v->paragraphs($course->summary) ?>
    <p class="page-head-meta"><?= $v->badge($v->plural($lessonCount, 'lesson')) ?>
      <?php if ($v->can('course.manage')): ?><a href="/teach/courses/<?= $course->id ?>">Edit this course</a><?php endif; ?></p>

    <?php if ($enrolment !== null && $enrolment->isCompleted()): ?>
      <p class="notice notice-done">You have completed this course.</p>
    <?php elseif ($enrolment !== null && $firstOpen !== null): ?>
      <p><a class="button" href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $firstOpen->id ?>">Continue: <?= $v->e($firstOpen->title) ?></a></p>
    <?php elseif ($user === null): ?>
      <p><a class="button" href="/login">Sign in to enrol</a></p>
    <?php elseif ($v->can('course.learn')): ?>
      <form method="post" action="/courses/<?= $v->e($course->slug) ?>/enrol">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Enrol and start</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="course-outline" aria-labelledby="outline-title">
    <h2 id="outline-title">What you will do</h2>
    <?php $linkLessons = $enrolment !== null; include __DIR__ . '/_trace.php'; ?>
  </section>
</div>
