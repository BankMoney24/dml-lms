<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="narrow">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/teach">Your courses</a></nav>
  <h1>New course</h1>
  <p class="muted">Start with a title and a short summary. You will add modules and lessons next.</p>
  <form method="post" action="/teach/courses" class="form" novalidate>
    <?= $v->csrfField() ?>
    <div class="field">
      <label for="title">Course title</label>
      <input id="title" name="title" required maxlength="190" value="<?= $v->e($old['title']) ?>"
        <?= isset($errors['title']) ? 'aria-invalid="true" aria-describedby="err-title"' : '' ?>>
      <?php $field = 'title'; include __DIR__ . '/_field_error.php'; ?>
    </div>
    <div class="field">
      <label for="summary">Summary <span class="muted">(optional)</span></label>
      <p class="hint" id="summary-hint">What will learners be able to do afterwards? Two or three sentences is enough.</p>
      <textarea id="summary" name="summary" rows="4" maxlength="2000" aria-describedby="summary-hint<?= isset($errors['summary']) ? ' err-summary' : '' ?>"><?= $v->e($old['summary']) ?></textarea>
      <?php $field = 'summary'; include __DIR__ . '/_field_error.php'; ?>
    </div>
    <button type="submit" class="button">Create course</button>
  </form>
</section>
