<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var array<string, string> $errors */
/** @var string $field */
if (isset($errors[$field])): ?>
<p class="field-error" id="err-<?= $v->e($field) ?>"><?= $v->e($errors[$field]) ?></p>
<?php endif;
