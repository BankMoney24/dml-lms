<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Support\SupportRequest $help
 * @var list<array{id: int, author: string, author_id: ?int, from_staff: bool, body: string, created_at: string}> $messages
 * @var array<int, list<\App\Modules\Files\StoredFile>> $files
 * @var bool $isStaff
 * @var bool $mine
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
[$label, $tone] = $help->statusFor($isStaff);
$resolved = $help->status === \App\Modules\Support\SupportRequest::RESOLVED;
?>
<?= $v->breadcrumbs($isStaff ? ['Help desk' => '/school/support', $help->subject => null] : ['Get help' => '/help', $help->subject => null]) ?>
<header class="page-head">
  <h1><?= $v->e($help->subject) ?></h1>
  <p class="page-head-meta"><?= $v->badge($label, $tone) ?> <span><?= $v->e($help->categoryLabel()) ?></span><?php if ($isStaff): ?><span>From <?= $v->e($help->requesterName) ?>, <?= $v->e($help->requesterEmail) ?></span><?php endif; ?></p>
</header>

<ol class="thread">
  <?php foreach ($messages as $m): ?>
  <li class="thread-msg<?= $m['from_staff'] ? ' is-staff' : '' ?>">
    <div class="ann-meta"><strong><?= $v->e($m['author']) ?></strong><?php if ($m['from_staff']): ?> <?= $v->badge('School') ?><?php endif; ?> <span class="muted small"><?= $v->datetime($m['created_at']) ?></span></div>
    <?= $v->paragraphs($m['body']) ?>
    <?php if (($files[$m['id']] ?? []) !== []): $fileList = $files[$m['id']]; $removeUrl = null; include __DIR__ . '/../files/_list.php'; endif; ?>
  </li>
  <?php endforeach; ?>
</ol>

<section class="panel reply-box" aria-labelledby="reply-h">
  <h2 id="reply-h"><?= $resolved ? 'Still need help?' : ($isStaff ? 'Answer' : 'Add to your request') ?></h2>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="/help/<?= $help->id ?>/reply" class="form" enctype="multipart/form-data" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('body', $isStaff ? 'Your answer' : 'Your message', ['type' => 'textarea', 'rows' => 5, 'value' => $old['body'] ?? '', 'error' => $errors['body'] ?? null,
        'hint' => $resolved ? 'Replying reopens the request.' : ($isStaff ? 'They get a notification. Never ask for a password.' : 'The school gets a notification.')]) ?>
    <?php include __DIR__ . '/_attach.php'; ?>
    <div class="row-actions">
      <button type="submit" class="button button-small"><?= $isStaff ? 'Send answer' : 'Send' ?></button>
    </div>
  </form>
  <form method="post" action="/help/<?= $help->id ?>/status" class="form-inline-end">
    <?= $v->csrfField() ?>
    <input type="hidden" name="resolved" value="<?= $resolved ? '0' : '1' ?>">
    <button type="submit" class="linklike small"><?= $resolved ? 'Reopen this request' : ($mine ? 'My problem is solved: mark resolved' : 'Mark resolved') ?></button>
  </form>
</section>
