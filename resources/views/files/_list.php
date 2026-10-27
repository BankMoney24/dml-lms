<?php
/**
 * A list of stored files with their size, so learners on mobile data know
 * what a download costs. Expects $fileList (list of StoredFile); optional
 * $removeUrl (callable StoredFile => string) shows a remove button for teachers.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Files\StoredFile> $fileList
 */
$removeUrl ??= null;
?>
<ul class="file-list">
  <?php foreach ($fileList as $f): $opens = \App\Modules\Files\UploadPolicy::opensInBrowser($f->extension); ?>
  <li>
    <span class="file-icon" aria-hidden="true"><?= $v->e($f->iconLabel()) ?></span>
    <span class="file-main">
      <a href="/files/<?= $f->id ?>"<?= $opens ? '' : ' download' ?>><?= $v->e($f->originalName) ?></a>
      <span class="small muted"><?= $v->e($f->kindLabel()) ?>, <?= $v->e($f->humanSize()) ?><?php if ($opens): ?>. <a href="/files/<?= $f->id ?>?download=1" download>Download<span class="visually-hidden"> <?= $v->e($f->originalName) ?></span></a><?php endif; ?></span>
      <?php if (\App\Modules\Files\UploadPolicy::isPacketTracer($f->extension)): ?>
        <span class="small file-note">Opens in Cisco Packet Tracer, a free program for Windows, macOS and Ubuntu from <a href="https://www.netacad.com" rel="noopener">Cisco Networking Academy</a> (free account needed). It does not run on Chromebooks.</span>
      <?php endif; ?>
    </span>
    <?php if ($removeUrl !== null): ?>
    <form method="post" action="<?= $v->e($removeUrl($f)) ?>" class="inline">
      <?= $v->csrfField() ?>
      <button type="submit" class="linklike small">Remove<span class="visually-hidden"> <?= $v->e($f->originalName) ?></span></button>
    </form>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
