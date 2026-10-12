<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<p>Someone asked to reset the password for your <?= $v->e($schoolName) ?> account. To choose a new password, use this link within <?= (int) $minutes ?> minutes:</p>
<?php $href = $link; $label = 'Choose a new password'; include __DIR__ . '/_button.php'; ?>
<p>If you did not ask for this, you can ignore this email. Your password stays the same.</p>
