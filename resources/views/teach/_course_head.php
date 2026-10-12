<?php
/**
 * Shared header for every course screen in the teaching workspace.
 * Expects $course and $tab ('outline' | 'progress'); optional $crumb for a deeper page.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 */
$base = '/teach/courses/' . $course->id;
$trail = ['Your courses' => '/teach', $course->title => isset($crumb) ? $base : null];
if (isset($crumb)) {
    $trail[$crumb] = null;
}
?>
<?= $v->breadcrumbs($trail) ?>
<?php if (!isset($crumb)): ?>
<header class="page-head">
  <h1><?= $v->e($course->title) ?></h1>
  <p class="page-head-meta">
    <?= $course->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?>
    <?php if ($course->isPublished()): ?><a href="/courses/<?= $v->e($course->slug) ?>">Open the learner page</a><?php endif; ?>
  </p>
</header>
<nav class="tabs" aria-label="Course">
  <a href="<?= $base ?>"<?= ($tab ?? '') === 'outline' ? ' aria-current="page"' : '' ?>>Outline</a>
  <a href="<?= $base ?>/progress"<?= ($tab ?? '') === 'progress' ? ' aria-current="page"' : '' ?>>Learner progress</a>
</nav>
<?php endif; ?>
