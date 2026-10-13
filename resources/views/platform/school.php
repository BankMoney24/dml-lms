<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var ?array{tenant: \App\Modules\Tenancy\Tenant, host: ?string, members: int, courses: int, learners: int} $stats
 * @var list<array{host: string, primary: bool, verified: bool}> $domains
 * @var bool $isCurrent
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
?>
<?= $v->breadcrumbs(['Schools' => '/system/schools', $school->name => null]) ?>
<header class="page-head">
  <h1><?= $v->e($school->name) ?></h1>
  <p class="page-head-meta">
    <?php if (!$school->isActive()): ?><?= $v->badge('Suspended', 'danger') ?>
    <?php elseif (!$school->isSetUp()): ?><?= $v->badge('Setting up', 'draft') ?>
    <?php else: ?><?= $v->badge('Open', 'ok') ?><?php endif; ?>
    <span>Short name <code><?= $v->e($school->slug) ?></code></span>
  </p>
</header>

<div class="settings">
  <section class="settings-group" aria-labelledby="sc-numbers">
    <h2 id="sc-numbers">At a glance</h2>
    <dl class="facts">
      <dt>Type</dt><dd><?= $v->e($school->kindLabel() ?? 'Not chosen yet') ?></dd>
      <dt>Learners</dt><dd><?= (int) ($stats['learners'] ?? 0) ?></dd>
      <dt>All members</dt><dd><?= (int) ($stats['members'] ?? 0) ?></dd>
      <dt>Courses</dt><dd><?= (int) ($stats['courses'] ?? 0) ?></dd>
      <dt>Setup</dt><dd><?= $school->isSetUp() ? 'Finished ' . $v->datetime($school->setupCompletedAt, 'j M Y') : 'Not finished: the owner is guided through it when they sign in' ?></dd>
    </dl>
  </section>

  <section class="settings-group" aria-labelledby="sc-addresses">
    <h2 id="sc-addresses">Web addresses</h2>
    <ul class="plain address-list">
      <?php foreach ($domains as $d): ?>
        <li><code><?= $v->e($d['host']) ?></code><?= $d['primary'] ? ' <span class="small muted">(main)</span>' : '' ?></li>
      <?php endforeach; ?>
      <?php if ($domains === []): ?><li class="muted">None yet.</li><?php endif; ?>
    </ul>
    <?= $v->errorSummary($errors) ?>
    <form method="post" action="/system/schools/<?= $school->id ?>/addresses" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('host', 'Add an address', [
          'value' => $old['host'] ?? '', 'error' => $errors['host'] ?? null, 'maxlength' => 253, 'inputmode' => 'url',
          'hint' => 'Point the name at this server (an A record to its IP address) and add it to the web server; docs/deployment.md → "A new school address" has the steps.',
      ]) ?>
      <div><button type="submit" class="button button-small">Add the address</button></div>
    </form>
  </section>

  <section class="settings-group" aria-labelledby="sc-status">
    <h2 id="sc-status">Open or suspended</h2>
    <?php if ($school->isActive()): ?>
      <p>Suspending a school keeps all its data but stops everyone, including its owners, from using it. Its pages say it is temporarily unavailable.</p>
      <?php if ($isCurrent): ?>
        <p class="muted">You are signed in through this school, so you cannot suspend it from here.</p>
      <?php else: ?>
      <form method="post" action="/system/schools/<?= $school->id ?>/status">
        <?= $v->csrfField() ?>
        <input type="hidden" name="status" value="suspended">
        <button type="submit" class="button button-danger button-small">Suspend <?= $v->e($school->name) ?></button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p>This school is suspended. Its data is kept.</p>
      <form method="post" action="/system/schools/<?= $school->id ?>/status">
        <?= $v->csrfField() ?>
        <input type="hidden" name="status" value="active">
        <button type="submit" class="button button-small">Open it again</button>
      </form>
    <?php endif; ?>
  </section>
</div>
