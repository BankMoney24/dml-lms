<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array<string, mixed>> $cards */
?>
<div class="course-grid">
  <?php foreach ($cards as $card): ?>
    <?php include __DIR__ . '/_card.php'; ?>
  <?php endforeach; ?>
</div>
