<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<p><?= $v->e($inviter) ?> has added you to <strong><?= $v->e($schoolName) ?></strong> as <?= $v->e($role) ?>. To start, choose your password with this link within <?= (int) $days ?> days:</p>
<?php $href = $link; $label = 'Set up my account'; include __DIR__ . '/_button.php'; ?>
<p>You will sign in with this email address: <?= $v->e($email) ?></p>
