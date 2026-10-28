<?php
/**
 * A payment plan reminder (ADR 0037).
 *
 * @var \App\Core\View\ViewHelpers $v
 */
?>
<p style="margin-top:0;">Hello <?= $v->e($name) ?>,</p>
<p><?= $v->e($body) ?></p>
<table role="presentation" style="border-collapse:collapse;margin:0 0 16px;">
  <tr><td style="padding:2px 16px 2px 0;color:#555;">Course</td><td style="padding:2px 0;"><?= $v->e($courseTitle) ?></td></tr>
  <tr><td style="padding:2px 16px 2px 0;color:#555;">Part</td><td style="padding:2px 0;"><?= (int) $part ?> of <?= (int) $parts ?></td></tr>
  <tr><td style="padding:2px 16px 2px 0;color:#555;">Amount</td><td style="padding:2px 0;font-weight:600;"><?= $v->e($amount) ?></td></tr>
  <tr><td style="padding:2px 16px 2px 0;color:#555;">Due</td><td style="padding:2px 0;"><?= $v->e($due) ?></td></tr>
</table>
<?php $href = $link; $label = 'Pay part ' . (int) $part; include __DIR__ . '/_button.php'; ?>
<p style="color:#555;font-size:14px;">If you have already paid the school directly, tell them on the same page so they can find it. Questions about your plan go to <?= $v->e($schoolName) ?>.</p>
