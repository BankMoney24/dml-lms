<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array<string, mixed>> $cards */
?>
<div class="course-grid<?= !empty($listScroll) ? ' course-row' : '' ?>"<?= !empty($listScroll) ? ' role="region" aria-label="' . $v->e((string) ($listLabel ?? 'Courses')) . '" tabindex="0"' : '' ?>>
  <?php foreach ($cards as $card): ?>
    <?php include __DIR__ . '/_card.php'; ?>
  <?php endforeach; ?>
</div>
