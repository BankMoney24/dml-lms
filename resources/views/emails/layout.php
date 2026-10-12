<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var string $content */
/** @var string $subject */
/** @var string $schoolName */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f6f8fb;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#14213d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #d5deea;border-radius:10px;">
      <tr><td style="padding:20px 28px;border-bottom:1px solid #d5deea;font-weight:800;font-size:18px;">
        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f2b705;margin-right:8px;"></span><?= $v->e($schoolName) ?>
      </td></tr>
      <tr><td style="padding:28px;font-size:16px;line-height:1.6;">
        <?= $content ?>
      </td></tr>
      <tr><td style="padding:16px 28px;border-top:1px solid #d5deea;font-size:13px;color:#4a5873;">
        Sent by <?= $v->e($schoolName) ?> on the DML Learning Platform. This is an automatic message; replies are not read.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
