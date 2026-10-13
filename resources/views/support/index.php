<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var list<\App\Modules\Support\SupportRequest> $mine
 * @var bool $isStaff
 * @var array<string, string> $categories
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$o = static fn (string $k): string => $old[$k] ?? '';
?>
<header class="page-head page-head-row">
  <div>
    <h1>Get help</h1>
    <p class="lead">Ask <?= $v->e($school->name) ?> about your account, a course, a payment or anything that is not working. The answer comes back here.</p>
  </div>
  <?php if ($isStaff): ?><p class="page-head-actions"><a class="button button-small button-quiet" href="/school/support">Open the help desk</a></p><?php endif; ?>
</header>

<div class="teach-layout">
  <section aria-labelledby="mine-h">
    <h2 id="mine-h">Your requests</h2>
    <?php if ($mine === []): ?>
      <p class="muted">Nothing yet. When you ask for help, your request and the school's answers stay here.</p>
    <?php else: ?>
    <ul class="help-list">
      <?php foreach ($mine as $r): [$label, $tone] = $r->statusFor(false); ?>
      <li><a href="/help/<?= $r->id ?>"><span class="help-subject"><?= $v->e($r->subject) ?></span><span class="small muted"><?= $v->e($r->categoryLabel()) ?>, updated <?= $v->datetime($r->updatedAt) ?></span></a><?= $v->badge($label, $tone) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($school->contactEmail !== '' || $school->contactPhone !== ''): ?>
      <p class="small muted">In a hurry? You can also reach the school<?= $school->contactEmail !== '' ? ' at ' . $v->e($school->contactEmail) : '' ?><?= $school->contactPhone !== '' ? ' or on ' . $v->e($school->contactPhone) : '' ?>.</p>
    <?php endif; ?>
  </section>

  <aside>
    <section class="panel" aria-labelledby="ask-h">
      <h2 id="ask-h">Ask for help</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/help" class="form" enctype="multipart/form-data" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('category', 'What is it about?', ['type' => 'select', 'options' => ['' => 'Choose'] + $categories, 'value' => $o('category'), 'error' => $errors['category'] ?? null]) ?>
        <?= $v->field('subject', 'In a few words', ['value' => $o('subject'), 'maxlength' => 160, 'error' => $errors['subject'] ?? null]) ?>
        <?= $v->field('body', 'What happened, or what do you need?', ['type' => 'textarea', 'rows' => 6, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
            'hint' => 'Say which course or page, and what you expected. Never send your password.']) ?>
        <?php include __DIR__ . '/_attach.php'; ?>
        <button type="submit" class="button button-small">Send</button>
      </form>
    </section>
  </aside>
</div>
