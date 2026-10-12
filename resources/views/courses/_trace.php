<?php
/**
 * Course outline drawn as a circuit trace: each lesson is a node that lights
 * up when completed. Expects $outline, $completedIds, $linkLessons, $course,
 * and optionally $currentLessonId.
 *
 * @var \App\Core\View\ViewHelpers $v
 */
$currentLessonId ??= null;
$n = 0;
?>
<div class="trace">
  <?php foreach ($outline as $module): ?>
  <section class="trace-module">
    <?php
      $moduleIds = array_map(static fn ($l) => $l->id, $module['lessons']);
      $moduleDone = count(array_intersect($moduleIds, $completedIds));
    ?>
    <h3 class="trace-module-title">
      <?= $v->e($module['title']) ?>
      <?php if ($linkLessons && $moduleIds !== []): ?>
        <span class="trace-module-count<?= $moduleDone === count($moduleIds) ? ' is-done' : '' ?>"><?= $moduleDone === count($moduleIds) ? 'All done' : $v->e($moduleDone . ' of ' . count($moduleIds)) ?></span>
      <?php endif; ?>
    </h3>
    <?php if ($module['lessons'] === []): ?>
      <p class="muted small">No lessons in this module yet.</p>
    <?php else: ?>
    <ol class="trace-list">
      <?php foreach ($module['lessons'] as $lesson):
        $n++;
        $done = in_array($lesson->id, $completedIds, true);
        $current = $lesson->id === $currentLessonId;
        $classes = 'trace-node' . ($done ? ' is-done' : '') . ($current ? ' is-current' : '');
      ?>
      <li class="<?= $classes ?>" value="<?= $n ?>">
        <span class="trace-dot" aria-hidden="true"><?= $n ?></span>
        <span class="trace-text">
          <?php if ($linkLessons): ?>
            <a href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $lesson->id ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $v->e($lesson->title) ?></a>
          <?php else: ?>
            <?= $v->e($lesson->title) ?>
          <?php endif; ?>
          <span class="trace-meta"><?= $v->e($lesson->typeLabel()) ?><?= $done ? ', done' : '' ?></span>
        </span>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>
