<?php
/**
 * A learner's achievements (ADR 0023).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, string> $earned code => earned_at
 * @var array<string, array{0: string, 1: string, 2: string}> $badges
 */
?>
<header class="page-head">
  <h1>Achievements</h1>
  <p class="lead"><?= count($earned) === 0
      ? 'Earn these by learning: finish lessons, pass quizzes and labs, help classmates.'
      : 'You have earned ' . count($earned) . ' of ' . count($badges) . '. Each one comes from something you really did.' ?></p>
</header>
<progress class="meter badge-meter" max="<?= count($badges) ?>" value="<?= count($earned) ?>" aria-label="Achievements earned"></progress>
<ul class="badge-grid">
  <?php foreach ($badges as $code => [$name, $how, $icon]): $at = $earned[$code] ?? null; ?>
  <li class="badge-card<?= $at !== null ? ' is-earned' : '' ?>">
    <span class="badge-medal" aria-hidden="true"><?= $v->icon($icon) ?></span>
    <span class="badge-text">
      <strong><?= $v->e($name) ?></strong>
      <span><?= $v->e($how) ?></span>
      <span class="badge-when"><?= $at !== null ? 'Earned ' . $v->datetime($at, 'j M Y') : 'Not yet' ?></span>
    </span>
  </li>
  <?php endforeach; ?>
</ul>
