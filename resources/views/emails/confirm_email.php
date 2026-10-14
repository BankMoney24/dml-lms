<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<p>Welcome to <?= $v->e($schoolName) ?>. Please confirm this is your email address, so you can get your account back if you forget your password:</p>
<?php $href = $link; $label = 'Confirm my email'; include __DIR__ . '/_button.php'; ?>
<p>The link works for <?= (int) $days ?> days. If you did not create an account, you can ignore this email.</p>
