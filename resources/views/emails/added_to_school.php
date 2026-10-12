<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<p><?= $v->e($inviter) ?> has given you access to <strong><?= $v->e($schoolName) ?></strong> as <?= $v->e($role) ?>. Sign in with your existing DML password:</p>
<?php $href = $link; $label = 'Sign in'; include __DIR__ . '/_button.php'; ?>
