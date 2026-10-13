<?php
/**
 * The square "pixels" from the DML logo: a cascade that narrows towards the
 * bottom right. Decorative only. $pixelClass picks the colourway.
 *
 * @var string $pixelClass
 */
$cells = [
    [0, 0, 'px-dim'], [1, 0, ''], [2, 0, ''], [3, 0, 'px-red'], [4, 0, ''],
    [1, 1, ''], [2, 1, 'px-red'], [3, 1, ''],
    [2, 2, 'px-red'], [3, 2, ''],
    [3, 3, 'px-red'],
];
?>
<svg class="pixels <?= htmlspecialchars($pixelClass ?? '', ENT_QUOTES) ?>" viewBox="0 0 76 60" aria-hidden="true" focusable="false">
  <?php foreach ($cells as [$x, $y, $c]): ?><rect class="<?= $c ?>" x="<?= $x * 15 + ($y === 0 && $x > 0 ? 1 : 0) ?>" y="<?= $y * 15 ?>" width="11" height="11"/><?php endforeach; ?>
</svg>
