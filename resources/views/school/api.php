<?php
/**
 * School → Settings → API keys (ADR 0036).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Api\ApiKey> $keys
 * @var ?string $newKey shown once, straight after it is made
 * @var string $baseUrl
 * @var array<string, string> $errors
 * @var string $name
 */
$schoolTab = 'overview';
$active = array_filter($keys, static fn ($k): bool => $k->isActive());
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Settings' => '/school', 'API keys' => null]) ?>
  <h1>API keys</h1>
  <p class="lead">Let your school’s own systems, such as PrimeERP or a finance spreadsheet, read courses, learners, progress, payments and certificates automatically.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if ($newKey !== null): ?>
<section class="notice notice-done api-new" role="status" aria-labelledby="new-key-h">
  <h2 id="new-key-h">Copy the key now</h2>
  <p>This is the only time it is shown. Put it in the other system’s settings; if you lose it, revoke it and make a new one.</p>
  <div class="code-block code-wrap"><pre><code><?= $v->e($newKey) ?></code></pre></div>
</section>
<?php endif; ?>

<section aria-labelledby="keys-h" class="api-keys">
    <h2 id="keys-h"><?= $v->e($v->plural(count($active), 'active key')) ?></h2>
    <?php if ($keys === []): ?>
      <p class="muted">No keys yet. Make one with the form; give it the name of the system that will use it.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th scope="col">Name</th><th scope="col">Key</th><th scope="col">Made</th><th scope="col">Last used</th><th scope="col"><span class="visually-hidden">Status and action</span></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
          <tr<?= $k->isActive() ? '' : ' class="is-muted"' ?>>
            <th scope="row"><?= $v->e($k->name) ?></th>
            <td data-label="Key" class="mono"><?= $v->e($k->hint()) ?></td>
            <td data-label="Made"><?= $v->datetime($k->createdAt, 'j M Y') ?><span class="small muted">by <?= $v->e($k->createdByName) ?></span></td>
            <td data-label="Last used"><?= $k->lastUsedAt === null ? '<span class="muted">Never</span>' : $v->datetime($k->lastUsedAt) ?></td>
            <td class="table-stack-action">
              <?php if ($k->isActive()): ?>
              <form method="post" action="/school/api/<?= $k->id ?>/revoke" class="inline">
                <?= $v->csrfField() ?>
                <button type="submit" class="linklike small danger-link">Revoke<span class="visually-hidden"> <?= $v->e($k->name) ?></span></button>
              </form>
              <?php else: ?>
                <?= $v->badge('Revoked', 'neutral') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

</section>

<section class="panel api-make" aria-labelledby="make-h">
      <h2 id="make-h">Make a key</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/school/api" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Which system is it for?', ['value' => $name, 'error' => $errors['name'] ?? null, 'maxlength' => 80, 'autocomplete' => 'off', 'hint' => 'For example PrimeERP. You will see this name in the list and in the activity log.']) ?>
        <button type="submit" class="button button-small">Make the key</button>
      </form>
      <p class="small api-warning">Anyone who has a key can read your learners’ names, emails, progress and payments. Give it only to systems your school controls, never paste it into a chat or email, and revoke it when the system is no longer used.</p>
</section>

<section class="api-how" aria-labelledby="how-h">
    <h2 id="how-h">How the other system uses it</h2>
    <p>Send the key with every request, over HTTPS, as a header. The system can only read, only this school’s data, and up to 120 requests a minute.</p>
    <div class="code-block code-wrap"><pre><code>curl -H "Authorization: Bearer dmlk_…" <?= $v->e($baseUrl) ?>/api/v1/learners</code></pre></div>
    <ul class="small">
      <li><code>/api/v1/courses</code>: every course, with its price and number of lessons</li>
      <li><code>/api/v1/learners</code>: learners’ names, emails and when they joined</li>
      <li><code>/api/v1/enrolments</code>: who is on which course and how far they are (filter with <code>?course=</code>, <code>?learner=</code>, <code>?status=</code>)</li>
      <li><code>/api/v1/payments</code>: payments and their status (filter with <code>?status=paid</code>, <code>?since=2026-10-01</code>)</li>
      <li><code>/api/v1/certificates</code>: certificates issued, with their check links</li>
    </ul>
    <p class="small muted">Lists come in pages of 50 (up to 100 with <code>?per_page=100</code>); each answer says how many there are and gives the address of the next page.</p>

</section>
