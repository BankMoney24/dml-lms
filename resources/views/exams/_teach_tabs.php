<?php
/**
 * Tabs for Teach → Quizzes and exams (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $testsTab 'live' | 'exams' | 'banks'
 */
?>
<nav class="tabs" aria-label="Quizzes and exams">
  <?php foreach (['live' => ['/teach/live', 'Live quizzes'], 'exams' => ['/teach/exams', 'CBT exams'], 'banks' => ['/teach/banks', 'Question banks']] as $key => [$href, $label]): ?>
    <a href="<?= $href ?>"<?= $testsTab === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?></a>
  <?php endforeach; ?>
</nav>
