<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var array{themes: array<string, string>, domains: list<array{host: string, primary: bool, verified: bool}>, logoPreview: ?string, maxLogoKb: int} $extra
 */
$theme = (string) ($values['theme'] ?? 'teal');
$name = (string) ($values['name'] ?? '');
$tagline = (string) ($values['tagline'] ?? '');
?>
<div class="brand-step">
  <div class="brand-fields">
    <?= $v->field('name', 'Name of your institution', ['value' => $name, 'error' => $errors['name'] ?? null, 'maxlength' => 120, 'autocomplete' => 'organization']) ?>
    <?= $v->field('tagline', 'Tagline', [
        'value' => $tagline, 'error' => $errors['tagline'] ?? null, 'maxlength' => 160, 'optional' => true,
        'hint' => 'A short line under your name on the welcome page, such as your motto.',
    ]) ?>

    <fieldset class="field"<?= isset($errors['theme']) ? ' aria-describedby="theme-error"' : '' ?>>
      <legend>Colour</legend>
      <p class="hint">Used for buttons and links. Every colour here is readable in light and dark mode.</p>
      <?php if (isset($errors['theme'])): ?><p class="field-error" id="theme-error"><?= $v->e($errors['theme']) ?></p><?php endif; ?>
      <div class="swatches">
        <?php foreach ($extra['themes'] as $key => $label): ?>
        <label class="swatch-choice">
          <input type="radio" name="theme" value="<?= $v->e($key) ?>"<?= $theme === $key ? ' checked' : '' ?><?= $key === 'teal' ? ' id="theme"' : '' ?> data-theme-pick>
          <span class="swatch theme-<?= $v->e($key) ?>" aria-hidden="true"></span>
          <span><?= $v->e($label) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="field">
      <label for="logo">Logo <span class="optional">(optional)</span></label>
      <p class="hint" id="logo-hint">PNG, JPEG or WebP, up to <?= $extra['maxLogoKb'] ?> KB. A wide logo on a transparent background looks best.</p>
      <?php if ($extra['logoPreview'] !== null): ?>
        <p class="logo-current"><img src="<?= $v->e($extra['logoPreview']) ?>" alt="Current logo" height="48"></p>
        <label class="choice choice-inline"><input type="checkbox" name="remove_logo" value="1"> <span>Remove the logo and show the name only</span></label>
      <?php endif; ?>
      <input type="file" id="logo" name="logo" data-max-bytes="<?= $extra['maxLogoKb'] * 1024 ?>" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" aria-describedby="logo-hint<?= isset($errors['logo']) ? ' logo-error' : '' ?>"<?= isset($errors['logo']) ? ' aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['logo'])): ?><p class="field-error" id="logo-error"><?= $v->e($errors['logo']) ?></p><?php endif; ?>
      <?php if ($errors !== [] && !isset($errors['logo'])): ?><p class="hint">If you chose a logo, choose it again: files are not kept when something else on the page needs fixing.</p><?php endif; ?>
    </div>

    <fieldset class="field">
      <legend>How people can reach you</legend>
      <p class="hint">Shown at the foot of every page. Leave out anything you do not want public.</p>
    </fieldset>
    <?= $v->field('contact_email', 'Email', ['type' => 'email', 'value' => (string) ($values['contact_email'] ?? ''), 'error' => $errors['contact_email'] ?? null, 'optional' => true, 'autocomplete' => 'email', 'maxlength' => 254]) ?>
    <?= $v->field('contact_phone', 'Phone', ['type' => 'tel', 'value' => (string) ($values['contact_phone'] ?? ''), 'error' => $errors['contact_phone'] ?? null, 'optional' => true, 'autocomplete' => 'tel', 'maxlength' => 40]) ?>
    <?= $v->field('website', 'Website', ['value' => (string) ($values['website'] ?? ''), 'error' => $errors['website'] ?? null, 'optional' => true, 'inputmode' => 'url', 'maxlength' => 255]) ?>
  </div>

  <aside class="brand-aside">
    <p class="brand-aside-label">How it will look</p>
    <div class="brand-preview theme-<?= $v->e($theme) ?>" data-brand-preview>
      <div class="bp-bar">
        <?php if ($extra['logoPreview'] !== null): ?>
          <img src="<?= $v->e($extra['logoPreview']) ?>" alt="" height="28">
        <?php else: ?>
          <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="3"/><path d="M8 12h5l3-5h3"/><circle cx="20" cy="7" r="2"/><path d="M13 12l3 5h3"/><circle cx="20" cy="17" r="2"/></svg>
        <?php endif; ?>
        <span data-preview-name><?= $v->e($name !== '' ? $name : 'Your school') ?></span>
      </div>
      <div class="bp-body">
        <p class="bp-tagline" data-preview-tagline><?= $v->e($tagline) ?></p>
        <span class="bp-button">Continue: Lesson 1</span>
        <span class="bp-link">See all courses</span>
      </div>
    </div>

    <div class="brand-address">
      <p class="brand-aside-label">Your site's address</p>
      <?php if ($extra['domains'] === []): ?>
        <p class="small muted">No address yet.</p>
      <?php else: ?>
      <ul class="plain">
        <?php foreach ($extra['domains'] as $d): ?>
          <li><code><?= $v->e($d['host']) ?></code> <?= $d['verified'] ? $v->badge('Connected', 'ok') : $v->badge('Waiting', 'draft') ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p class="small muted">To use your own domain name, ask your platform administrator to add it.</p>
    </div>
  </aside>
</div>
