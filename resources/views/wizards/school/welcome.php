<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Wizards\WizardStep> $steps
 */
$asked = array_values(array_filter($steps, static fn ($s): bool => $s->inReview()));
$optional = count(array_filter($asked, static fn ($s): bool => $s->optional()));
$school = $v->shared('tenant');
?>
<div class="wizard-welcome">
  <p class="wizard-hello">Let's get <strong><?= $v->e($school?->name) ?></strong> ready for learners.</p>
  <p>There are <?= count($asked) ?> short steps after this one<?= $optional > 0 ? ', and ' . $optional . ' of them can be skipped' : '' ?>:</p>
  <ol class="wizard-plan">
    <?php foreach ($asked as $s): ?>
      <li><?= $v->e($s->title()) ?><?= $s->optional() ? ' <span class="muted">(optional)</span>' : '' ?></li>
    <?php endforeach; ?>
  </ol>
  <p>You can stop at any point and carry on later, from this computer or another one. Nothing changes for learners until you confirm on the last step.</p>
</div>
