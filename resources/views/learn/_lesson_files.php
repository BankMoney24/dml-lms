<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<\App\Modules\Files\StoredFile> $lessonFiles */
?>
<?php if ($lessonFiles !== []): ?>
<section class="lesson-files" aria-labelledby="lesson-files-h">
  <h2 id="lesson-files-h">Lesson files</h2>
  <?php $fileList = $lessonFiles; $removeUrl = null; include __DIR__ . '/../files/_list.php'; ?>
</section>
<?php endif; ?>
