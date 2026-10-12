<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{user_id: int, name: string, email: string, role: \App\Modules\Identity\Role, joined_at: string}> $members */
/** @var array<string, string> $roles */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
$counts = [];
foreach ($members as $m) {
    $counts[$m['role']->label()] = ($counts[$m['role']->label()] ?? 0) + 1;
}
?>
<header class="page-head">
  <h1>People</h1>
  <p class="lead">Who can sign in to <?= $v->e($v->shared('tenant')?->name) ?>, and what they can do.</p>
</header>

<?php if ($newMember !== null): ?>
<div class="notice notice-done" role="status">
  <?php if ($newMember['one_time_password'] !== null): ?>
    <p><strong><?= $v->e($newMember['name']) ?></strong> can now sign in as <strong><?= $v->e($newMember['email']) ?></strong> with this one-time password:</p>
    <p><span class="secret"><?= $v->e($newMember['one_time_password']) ?></span></p>
    <p class="small">Give it to them in person or by phone. It is shown only once, and they will choose their own password when they first sign in.</p>
  <?php else: ?>
    <p><strong><?= $v->e($newMember['name']) ?></strong> already had a DML account, so they can sign in here with their existing password.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="teach-layout">
  <section aria-labelledby="members-h">
    <h2 id="members-h"><?= $v->e($v->plural(count($members), 'member')) ?></h2>
    <p class="muted small"><?php $parts = []; foreach ($counts as $label => $c) { $parts[] = $c . ' ' . strtolower($label) . ($c === 1 ? '' : 's'); } echo $v->e(implode(' · ', $parts)); ?></p>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($members as $m):
        $isMe = $m['user_id'] === $meId;
        $locked = $isMe || ($m['role']->value === 'owner' && !$iAmOwner); ?>
        <tr>
          <td><?= $v->e($m['name']) ?><?= $isMe ? ' ' . $v->badge('You') : '' ?><span class="small muted"><?= $v->e($m['email']) ?></span></td>
          <td>
            <?php if ($locked): ?>
              <?= $v->e($m['role']->label()) ?>
            <?php else: ?>
            <form method="post" action="/people/<?= $m['user_id'] ?>/role" class="row-actions">
              <?= $v->csrfField() ?>
              <label class="visually-hidden" for="role-<?= $m['user_id'] ?>">Role for <?= $v->e($m['name']) ?></label>
              <select id="role-<?= $m['user_id'] ?>" name="role">
                <?php foreach ($roles as $value => $label): ?>
                  <option value="<?= $v->e($value) ?>"<?= $value === $m['role']->value ? ' selected' : '' ?>><?= $v->e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="button button-small button-quiet">Change<span class="visually-hidden"> role for <?= $v->e($m['name']) ?></span></button>
            </form>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if (!$locked): ?>
            <details class="confirm">
              <summary>Remove<span class="visually-hidden"> <?= $v->e($m['name']) ?></span></summary>
              <form method="post" action="/people/<?= $m['user_id'] ?>/remove">
                <?= $v->csrfField() ?>
                <p class="small">They lose access straight away. Their learning history is kept.</p>
                <button type="submit" class="button button-danger button-small">Remove <?= $v->e($m['name']) ?></button>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="small muted">Learners study courses. Educators build courses and see progress. Admins also manage people and see activity. Owners can do everything, including managing other owners.</p>
  </section>

  <aside>
    <section class="panel" aria-labelledby="add-h">
      <h2 id="add-h">Add a person</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/people" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Full name', ['value' => $old['name'], 'error' => $errors['name'] ?? null, 'autocomplete' => 'off', 'maxlength' => 120]) ?>
        <?= $v->field('email', 'Email address', ['type' => 'email', 'value' => $old['email'], 'error' => $errors['email'] ?? null, 'autocomplete' => 'off', 'maxlength' => 254,
            'hint' => 'They sign in with this. For young learners, a parent’s or the school’s address is fine.']) ?>
        <?= $v->field('role', 'Role', ['type' => 'select', 'options' => $roles, 'value' => $old['role'], 'error' => $errors['role'] ?? null]) ?>
        <button type="submit" class="button button-small">Add person</button>
      </form>
    </section>
  </aside>
</div>
