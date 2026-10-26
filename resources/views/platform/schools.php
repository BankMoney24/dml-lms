<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{tenant: \App\Modules\Tenancy\Tenant, host: ?string, members: int, courses: int, learners: int}> $schools
 * @var ?array{name: string, id: int, address: string, owner_name: string, owner_email: string, one_time_password: ?string, emailed: bool, existing_account: bool} $created
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $emailing
 */
$platformTab = 'schools';
?>
<header class="page-head">
  <h1>Schools</h1>
  <p class="lead">Every school on this platform. Visible to platform administrators only.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if ($created !== null): ?>
<section class="notice notice-done" role="status" aria-labelledby="created-h">
  <h2 id="created-h"><?= $v->e($created['name']) ?> was created</h2>
  <p>Its address is <a href="<?= $v->e($created['address']) ?>"><?= $v->e($created['address']) ?></a>. It works once that name points at this server (see <a href="/system/schools/<?= (int) $created['id'] ?>">the school's page</a>).</p>
  <?php if ($created['emailed']): ?>
    <p><?= $v->e($created['owner_name']) ?> was emailed <?= $created['existing_account'] ? 'that they now own this school' : 'an invitation to choose a password (valid 7 days)' ?>. When they sign in, the school setup guides them through the rest.</p>
  <?php elseif ($created['one_time_password'] !== null): ?>
    <p><?= $v->e($created['owner_name']) ?> signs in at that address as <strong><?= $v->e($created['owner_email']) ?></strong> with this one-time password, shown only now:</p>
    <p><span class="secret"><?= $v->e($created['one_time_password']) ?></span></p>
    <p class="small">Give it to them in person or by phone. They choose their own password at first sign-in, then set up two-step sign-in and the school.</p>
  <?php else: ?>
    <p><?= $v->e($created['owner_name']) ?> already has a DML account and signs in there with their own password.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="table-wrap">
<table class="table" data-sortable>
  <caption class="visually-hidden">Schools</caption>
  <thead><tr>
    <th scope="col">School</th>
    <th scope="col">Address</th>
    <th scope="col">Status</th>
    <th scope="col" class="num">Learners</th>
    <th scope="col" class="num">All members</th>
    <th scope="col" class="num">Courses</th>
  </tr></thead>
  <tbody>
  <?php foreach ($schools as $row): $t = $row['tenant']; ?>
    <tr>
      <td><a href="/system/schools/<?= $t->id ?>"><?= $v->e($t->name) ?></a></td>
      <td><?= $row['host'] !== null ? '<code>' . $v->e($row['host']) . '</code>' : '<span class="muted">None</span>' ?></td>
      <td>
        <?php if (!$t->isActive()): ?><?= $v->badge('Suspended', 'danger') ?>
        <?php elseif (!$t->isSetUp()): ?><?= $v->badge('Setting up', 'draft') ?>
        <?php else: ?><?= $v->badge('Open', 'ok') ?><?php endif; ?>
      </td>
      <td class="num"><?= $row['learners'] ?></td>
      <td class="num"><?= $row['members'] ?></td>
      <td class="num"><?= $row['courses'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="section panel add-school" aria-labelledby="add-h">
  <h2 id="add-h">Add a school</h2>
  <p class="muted">Creates the school with its web address and its first owner. The owner then chooses the type, look and features in the school setup.</p>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="/system/schools" class="form form-grid" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('name', 'Name of the school', ['value' => $old['name'] ?? '', 'error' => $errors['name'] ?? null, 'maxlength' => 120]) ?>
    <?= $v->field('host', 'Web address', [
        'value' => $old['host'] ?? '', 'error' => $errors['host'] ?? null, 'maxlength' => 253, 'inputmode' => 'url',
        'hint' => 'For example learn.yourschool.ng. Point it at this server before the school starts using it.',
    ]) ?>
    <?= $v->field('owner_name', "Owner's name", ['value' => $old['owner_name'] ?? '', 'error' => $errors['owner_name'] ?? null, 'maxlength' => 120]) ?>
    <?= $v->field('owner_email', "Owner's email", [
        'type' => 'email', 'value' => $old['owner_email'] ?? '', 'error' => $errors['owner_email'] ?? null, 'maxlength' => 254,
        'hint' => $emailing ? 'They get an email invitation.' : 'Email is not set up, so you will see a one-time password to give them.',
    ]) ?>
    <div><button type="submit" class="button">Create the school</button></div>
  </form>
</section>
