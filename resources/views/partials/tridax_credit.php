<?php
/**
 * "Developed and powered by TridaxLabs". The name opens a panel of what
 * TridaxLabs builds (on hover or focus, or a tap); without JavaScript it is a
 * plain link to tridax.org. $creditSize: 16 (default) or 24.
 *
 * @var \App\Core\View\ViewHelpers $v
 */
$creditSize = (int) ($creditSize ?? 16);
?>
<img class="tridax-mark" src="/assets/brand/tridaxlabs-32.png" srcset="/assets/brand/tridaxlabs-64.png 2x" width="<?= $creditSize ?>" height="<?= $creditSize ?>" alt=""><span>Developed and powered by <a href="https://tridax.org" class="tridax-link" data-tridax aria-haspopup="dialog" aria-controls="tridax-pop" aria-expanded="false">TridaxLabs</a></span>
<?php unset($creditSize); ?>
