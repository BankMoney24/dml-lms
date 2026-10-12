<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var string $href */
/** @var string $label */
?>
<p style="margin:24px 0;"><a href="<?= $v->e($href) ?>" style="display:inline-block;background:#0b7a6b;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:999px;"><?= $v->e($label) ?></a></p>
<p style="font-size:13px;color:#4a5873;">If the button does not work, copy this address into your browser:<br><span style="word-break:break-all;"><?= $v->e($href) ?></span></p>
