<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<?php if ($approved): ?>
<p><?= $v->e($reviewer) ?> approved your work for <strong><?= $v->e($lessonTitle) ?></strong> in <?= $v->e($courseTitle) ?>. That lesson is now complete.</p>
<?php else: ?>
<p><?= $v->e($reviewer) ?> reviewed your work for <strong><?= $v->e($lessonTitle) ?></strong> in <?= $v->e($courseTitle) ?> and asked for some changes before approving it.</p>
<?php endif; ?>
<?php if ($feedback !== ''): ?>
<p style="margin:0 0 4px;font-weight:600;">Feedback</p>
<div style="border-left:3px solid #0B7A6B;padding:4px 12px;margin:0 0 16px;white-space:pre-wrap;"><?= $v->e($feedback) ?></div>
<?php endif; ?>
<?php $href = $link; $label = $approved ? 'Continue the course' : 'See the feedback and resubmit'; include __DIR__ . '/_button.php'; ?>
