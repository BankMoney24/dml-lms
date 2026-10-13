<?php
/**
 * A course's cover: the logo's square pixels on navy, scattered thin on the
 * left and gathering on the right, in a pattern made from the course's
 * address so each course keeps its own. Decorative; the title carries the meaning.
 * Expects $c (Course).
 *
 * @var \App\Modules\Courses\Course $c
 */
$seed = crc32($c->slug) ?: 1;
$next = static function () use (&$seed): float {
    // xorshift32: the same course always draws the same cover.
    $seed ^= ($seed << 13) & 0xFFFFFFFF;
    $seed ^= $seed >> 17;
    $seed ^= ($seed << 5) & 0xFFFFFFFF;

    return ($seed & 0xFFFFFF) / 0x1000000;
};
$cols = 22;
$rows = 6;
$cells = [];
for ($x = 0; $x < $cols; $x++) {
    $density = 0.04 + 0.62 * (($x / ($cols - 1)) ** 1.8);
    for ($y = 0; $y < $rows; $y++) {
        if ($next() < $density) {
            $r = $next();
            $cells[] = [$x, $y, $r < 0.07 ? 'cv-r' : ($r < 0.45 ? 'cv-l' : ($r < 0.8 ? 'cv-b' : 'cv-d'))];
        }
    }
}
?>
<svg class="cover-px" viewBox="0 0 <?= $cols * 12 ?> <?= $rows * 12 ?>" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
  <?php foreach ($cells as [$x, $y, $cls]): ?><rect class="<?= $cls ?>" x="<?= $x * 12 + 1 ?>" y="<?= $y * 12 + 1 ?>" width="9" height="9"/><?php endforeach; ?>
</svg>
