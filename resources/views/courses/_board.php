<?php
/**
 * A course drawn as a strip of circuit board: one node per module, lit when
 * the learner has finished that module. The same idea as the lesson trace,
 * at card size. Expects $card (from CourseCards).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{course: \App\Modules\Courses\Course, modules: list<array{title: string, lessons: int, done: int}>, lessons: int, done: int, enrolled: bool, completed: bool} $card
 */
$modules = $card['modules'];
$count = count($modules);
?>
<?php if ($count > 0): ?>
<ol class="board<?= $count > 10 ? ' board-dense' : '' ?>" aria-label="<?= $v->e($v->plural($count, 'module')) ?>">
  <?php foreach ($modules as $i => $m):
    $state = $m['lessons'] > 0 && $m['done'] >= $m['lessons'] ? 'lit' : ($m['done'] > 0 ? 'part' : 'off'); ?>
  <li class="board-node is-<?= $state ?>">
    <span class="visually-hidden"><?= $v->e(sprintf('Module %d: %s, %s', $i + 1, $m['title'], $card['enrolled'] ? $m['done'] . ' of ' . $v->plural($m['lessons'], 'lesson') . ' done' : $v->plural($m['lessons'], 'lesson'))) ?></span>
  </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>
