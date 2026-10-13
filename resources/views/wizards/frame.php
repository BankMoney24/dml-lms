<?php
/**
 * The wizard engine's page: progress trace, the current step's form, and
 * the Back / Continue / Skip / Save and exit buttons.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Wizards\Wizard $wizard
 * @var list<\App\Modules\Wizards\WizardStep> $steps
 * @var \App\Modules\Wizards\WizardStep $step
 * @var int $position
 * @var \App\Modules\Wizards\WizardRun $run
 * @var callable(string): bool $reachable
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var ?array{sections: list<array{step: \App\Modules\Wizards\WizardStep, skipped: bool, lines: list<array{0: string, 1: string}>}>, problems: list<array{step: string, message: string, blocking: bool}>, finishLabel: string} $review
 * @var bool $returnToReview
 * @var array<string, mixed> $extra
 */
$base = '/setup/' . $wizard->id();
$total = count($steps);
$next = $steps[$position + 1] ?? null;
$continueLabel = $returnToReview ? 'Save and go back to the review' : ($next !== null && $next->isReview() ? 'Continue to the review' : 'Continue');
?>
<div class="wizard">
  <nav class="wizard-rail" aria-label="<?= $v->e($wizard->title()) ?> steps">
    <p class="wizard-name"><?= $v->e($wizard->title()) ?></p>
    <ol class="wizard-steps">
      <?php foreach ($steps as $i => $s):
        $key = $s->key();
        $state = $key === $step->key() ? 'current' : (in_array($key, $run->skipped, true) ? 'skipped' : (in_array($key, $run->done, true) ? 'done' : 'todo'));
        $stateText = ['current' => 'you are here', 'skipped' => 'skipped', 'done' => 'done', 'todo' => 'not started'][$state];
      ?>
      <li class="wstep is-<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="wstep-dot" aria-hidden="true"><?= $i + 1 ?></span>
        <span class="wstep-text">
          <?php if ($state !== 'current' && $reachable($key)): ?>
            <a href="<?= $base . '/' . $v->e($key) ?>"><?= $v->e($s->title()) ?></a>
          <?php else: ?>
            <span><?= $v->e($s->title()) ?></span>
          <?php endif; ?>
          <span class="wstep-note"><?= $state === 'skipped' ? 'Skipped' : ($s->optional() ? 'Optional' : '') ?></span>
          <span class="visually-hidden">(<?= $stateText ?>)</span>
        </span>
      </li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <section class="wizard-main" aria-labelledby="wizard-title">
    <p class="wizard-count">Step <?= $position + 1 ?> of <?= $total ?><?= $step->optional() ? ', optional' : '' ?></p>
    <h1 id="wizard-title"><?= $v->e($step->title()) ?></h1>
    <?php if ($step->why() !== ''): ?><p class="lead wizard-why"><?= $v->e($step->why()) ?></p><?php endif; ?>

    <?= $v->errorSummary(array_diff_key($errors, ['finish' => true])) ?>
    <?php if (isset($errors['finish'])): ?><p class="notice notice-err" role="alert"><?= $v->e($errors['finish']) ?></p><?php endif; ?>

    <form method="post" action="<?= $base . '/' . $v->e($step->key()) ?>" enctype="multipart/form-data" class="form wizard-form" novalidate>
      <?= $v->csrfField() ?>
      <?php if ($returnToReview): ?><input type="hidden" name="return" value="review"><?php endif; ?>

      <?php include __DIR__ . '/' . $step->view() . '.php'; ?>

      <div class="wizard-actions">
        <?php if ($step->isReview()): ?>
          <button type="submit" name="action" value="finish" class="button button-lamp"><?= $v->e($review['finishLabel'] ?? 'Finish') ?></button>
        <?php else: ?>
          <button type="submit" name="action" value="continue" class="button"><?= $v->e($continueLabel) ?></button>
        <?php endif; ?>
        <?php if ($position > 0): ?>
          <button type="submit" name="action" value="back" class="button button-quiet">Back</button>
        <?php endif; ?>
        <span class="wizard-actions-end">
          <?php if ($step->optional()): ?>
            <button type="submit" name="action" value="skip" class="linklike">Skip this step</button>
          <?php endif; ?>
          <?php if (!$step->isReview()): ?>
            <button type="submit" name="action" value="save" class="linklike">Save and exit</button>
          <?php endif; ?>
        </span>
      </div>
    </form>

    <footer class="wizard-foot">
      <p class="small muted">
        Your answers are saved on the server after every step, so you can stop and come back from any device.
        <?php if ($run->updatedByName !== null && $run->updatedAt !== null): ?>
          Last saved by <?= $v->e($run->updatedByName) ?>, <?= $v->datetime($run->updatedAt) ?>.
        <?php endif; ?>
      </p>
      <?php if ($run->data !== []): ?>
      <form method="post" action="<?= $base ?>/discard" class="inline">
        <?= $v->csrfField() ?>
        <button type="submit" class="linklike small">Discard these answers</button>
      </form>
      <?php endif; ?>
    </footer>
  </section>
</div>
