<?php
/**
 * File picker for help messages. Same checks as every other upload.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, string> $errors
 */
?>
<div class="field">
  <label for="attachments">Attach screenshots or files <span class="optional">(optional, up to 3)</span></label>
  <p class="hint" id="attachments-hint">A screenshot of the problem helps the school answer faster.</p>
  <input type="file" id="attachments" name="attachments[]" multiple accept="<?= $v->e(\App\Modules\Files\UploadPolicy::acceptAttribute()) ?>" aria-describedby="attachments-hint<?= isset($errors['attachments']) ? ' attachments-error' : '' ?>"<?= isset($errors['attachments']) ? ' aria-invalid="true"' : '' ?> data-max-files="3">
  <?php if (isset($errors['attachments'])): ?><p class="field-error" id="attachments-error"><?= $v->e($errors['attachments']) ?></p><?php endif; ?>
</div>
