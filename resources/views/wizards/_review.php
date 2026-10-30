<?php
/**
 * Generic review step: every answer in plain words, with a Change link,
 * then anything missing. Included by wizards/frame.php.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{sections: list<array{step: \App\Modules\Wizards\WizardStep, skipped: bool, lines: list<array{0: string, 1: string}>}>, problems: list<array{step: string, message: string, blocking: bool}>, finishLabel: string} $review
 * @var string $base
 */
$titles = [];
foreach ($review['sections'] as $section) {
    $titles[$section['step']->key()] = $section['step']->title();
}
$blocking = array_values(array_filter($review['problems'], static fn (array $p): bool => $p['blocking']));
$notes = array_values(array_filter($review['problems'], static fn (array $p): bool => !$p['blocking']));
?>
<?php if ($blocking !== []): ?>
<div class="notice notice-err" role="alert">
  <p><strong>Before you can finish</strong></p>
  <ul>
    <?php foreach ($blocking as $p): ?>
      <li><?= $v->e($p['message']) ?> <a href="<?= $base . '/' . $v->e($p['step']) ?>?return=review">Go to <?= $v->e($titles[$p['step']] ?? 'that step') ?></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($notes !== []): ?>
<div class="notice review-notes">
  <p><strong>Worth a second look</strong> (you can still finish)</p>
  <ul>
    <?php foreach ($notes as $p): ?>
      <li><?= $v->e($p['message']) ?> <a href="<?= $base . '/' . $v->e($p['step']) ?>?return=review">Change</a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="review-sections">
  <?php foreach ($review['sections'] as $section): $s = $section['step']; ?>
  <section class="review-section" aria-labelledby="rv-<?= $v->e($s->key()) ?>">
    <div class="review-head">
      <h2 id="rv-<?= $v->e($s->key()) ?>"><?= $v->e($s->title()) ?></h2>
      <a href="<?= $base . '/' . $v->e($s->key()) ?>?return=review">Change<span class="visually-hidden"> <?= $v->e($s->title()) ?></span></a>
    </div>
    <?php if ($section['skipped']): ?>
      <p class="muted">Skipped. You can fill this in later from your school's settings.</p>
    <?php else: ?>
      <dl class="facts">
        <?php foreach ($section['lines'] as [$label, $value]): ?>
          <dt><?= $v->e($label) ?></dt><dd data-own-words><?= $v->e($value) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>
