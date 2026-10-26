<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var array{min_average: ?int, average: ?int, quizzes: int} $standing */
$standing ??= ['min_average' => null, 'average' => null, 'quizzes' => 0];
if ($standing['min_average'] === null || $standing['quizzes'] === 0) {
    return;
}
$met = ($standing['average'] ?? 0) >= $standing['min_average'];
?>
<p class="standing<?= $met ? ' is-met' : '' ?>">
  To finish, you also need an average of <strong><?= (int) $standing['min_average'] ?>%</strong> across the <?= $v->e($v->plural($standing['quizzes'], 'required quiz', 'required quizzes')) ?>.
  Your average now: <strong><?= (int) $standing['average'] ?>%</strong><?= $met ? '.' : ', counting your best try at each. Retake a quiz to raise it.' ?>
</p>
