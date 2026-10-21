<?php
/**
 * An uploaded video or recording, played in the page (ADR 0029). Nothing is
 * fetched until the learner presses play (preload="none"), the size is shown
 * so they know what it costs in data, and it can be downloaded to watch or
 * listen offline, for example on a better connection.
 *
 * Expects $mediaFile (StoredFile) and $mediaSrc (where it streams from).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Files\StoredFile $mediaFile
 * @var string $mediaSrc
 */
$kind = $mediaFile->mediaKind() ?? 'video';
$download = $mediaSrc . (str_contains($mediaSrc, '?') ? '&' : '?') . 'download=1';
?>
<figure class="media-player media-<?= $kind ?>">
  <?php if ($kind === 'video'): ?>
  <video controls preload="none" playsinline>
    <source src="<?= $v->e($mediaSrc) ?>" type="<?= $v->e($mediaFile->mimeType) ?>">
    <p>This browser cannot play the video here. <a href="<?= $v->e($download) ?>" download>Download it</a> instead.</p>
  </video>
  <?php else: ?>
  <div class="audio-card">
    <span class="audio-icon" aria-hidden="true"><?= $v->icon('headphones') ?></span>
    <audio controls preload="none">
      <source src="<?= $v->e($mediaSrc) ?>" type="<?= $v->e($mediaFile->mimeType) ?>">
      <p>This browser cannot play the recording here. <a href="<?= $v->e($download) ?>" download>Download it</a> instead.</p>
    </audio>
  </div>
  <?php endif; ?>
  <figcaption class="media-meta small">
    <span><?= $kind === 'video' ? 'Video' : 'Audio' ?>, <?= $v->e($mediaFile->humanSize()) ?>. Nothing downloads until you press play.</span>
    <a href="<?= $v->e($download) ?>" download><?= $v->icon('download') ?>Download to <?= $kind === 'video' ? 'watch' : 'listen' ?> offline</a>
  </figcaption>
</figure>
