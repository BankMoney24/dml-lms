<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array{user_id: int, name: string, email: string, role: \App\Modules\Identity\Role, joined_at: string}> $members */
/** @var array<string, string> $roles */
/** @var array<int, int> $guardianCounts learner id => linked guardians */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var \App\Core\View\Pager $pager */
/** @var array<string, int> $roleCounts */
/** @var ?\App\Modules\Identity\Role $roleFilter */
/** @var string $sort */
$everyone = array_sum($roleCounts);
$filtered = $roleFilter !== null || ($find ?? '') !== '';
?>
<header class="page-head">
  <h1>People</h1>
  <p class="lead">Who can sign in to <?= $v->e($v->shared('tenant')?->name) ?>, and what they can do.</p>
</header>
<?php $schoolTab = 'people'; include __DIR__ . '/../school/_tabs.php'; ?>

<?php if ($newMember !== null): ?>
<div class="notice notice-done" role="status">
  <?php if ($newMember['emailed'] ?? false): ?>
    <p><strong><?= $v->e($newMember['name']) ?></strong> was added. <?= ($newMember['existing_account'] ?? false)
        ? 'They already had a DML account, so we emailed them that they can now sign in here.'
        : 'We emailed an invitation to ' . $v->e($newMember['email']) . ' with a link to choose their password (valid 7 days).' ?></p>
  <?php elseif ($newMember['one_time_password'] !== null): ?>
    <p><strong><?= $v->e($newMember['name']) ?></strong> can now sign in as <strong><?= $v->e($newMember['email']) ?></strong> with this one-time password:</p>
    <p><span class="secret"><?= $v->e($newMember['one_time_password']) ?></span></p>
    <p class="small">Give it to them in person or by phone. It is shown only once, and they will choose their own password when they first sign in.</p>
  <?php else: ?>
    <p><strong><?= $v->e($newMember['name']) ?></strong> already had a DML account, so they can sign in here with their existing password.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="teach-layout people-layout">
  <section aria-labelledby="members-h">
    <h2 id="members-h"><?= $v->e($v->plural($everyone, 'member')) ?></h2>
    <form method="get" action="/people" class="list-tools" role="search" aria-label="Find people" data-autosubmit>
      <div class="list-tool list-tool-find">
        <label for="people-find">Find</label>
        <input type="search" id="people-find" name="find" placeholder="Name or email" autocomplete="off" maxlength="120" value="<?= $v->e($find ?? '') ?>">
      </div>
      <div class="list-tool">
        <label for="people-role">Role</label>
        <select id="people-role" name="role">
          <option value="">Everyone (<?= $everyone ?>)</option>
          <?php foreach (\App\Modules\Identity\Role::cases() as $r): if (($roleCounts[$r->value] ?? 0) === 0 && $roleFilter !== $r) { continue; } ?>
          <option value="<?= $v->e($r->value) ?>"<?= $roleFilter === $r ? ' selected' : '' ?>><?= $v->e($r->label()) ?>s (<?= (int) ($roleCounts[$r->value] ?? 0) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="list-tool">
        <label for="people-sort">Order</label>
        <select id="people-sort" name="sort">
          <?php foreach (['role' => 'By role', 'name' => 'By name', 'newest' => 'Newest first'] as $key => $label): ?>
          <option value="<?= $key === 'role' ? '' : $v->e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="list-tool-actions">
        <button type="submit" class="button button-small button-quiet">Show</button>
        <?php if ($filtered || $sort !== 'role'): ?><a class="small" href="/people">Clear</a><?php endif; ?>
      </div>
    </form>
    <p class="small muted list-count" role="status"><?php if ($pager->total === 0): ?>No one matches<?= ($find ?? '') !== '' ? ' “' . $v->e($find) . '”' : '' ?><?= $roleFilter !== null ? ' among ' . $v->e(strtolower($roleFilter->label())) . 's' : '' ?>.<?php elseif ($pager->pages > 1 || $filtered): ?>Showing <?= $pager->from() ?>–<?= $pager->to() ?> of <?= $pager->total ?><?= $filtered ? ' matching' : '' ?>.<?php endif; ?></p>
    <?php if ($members !== []): ?>
    <div class="table-wrap">
    <table class="table people-table">
      <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody id="people-rows">
      <?php foreach ($members as $m):
        $isMe = $m['user_id'] === $meId;
        $locked = $isMe || ($m['role']->value === 'owner' && !$iAmOwner); ?>
        <tr id="person-<?= $m['user_id'] ?>">
          <td><?= $v->e($m['name']) ?><?= $isMe ? ' ' . $v->badge('You') : '' ?>
            <?= $m['pending'] ? ' ' . $v->badge('Not signed in yet', 'draft') : '' ?>
            <?= $m['two_step'] ? ' ' . $v->badge('Two-step on', 'ok') : '' ?>
            <span class="small muted"><?= $v->e($m['email']) ?></span>
            <?php $pp = ($profiles ?? [])[$m['user_id']] ?? null; if ($pp !== null): ?>
              <span class="small people-profile"><?php if ($pp->joinedVia === 'self'): ?><?= $v->badge('Signed up online') ?><?php endif; ?><?php if ($pp->github !== null): ?> <a href="<?= $v->e((string) $pp->githubUrl()) ?>" rel="noopener noreferrer">GitHub: <?= $v->e($pp->github) ?></a><?php endif; ?></span>
            <?php endif; ?>
            <?php if ($m['role']->value === 'learner'): $gc = $guardianCounts[$m['user_id']] ?? 0; ?>
              <a class="small people-guardians" href="/people/<?= $m['user_id'] ?>/guardians"><?= $gc === 0 ? 'Link a parent or guardian' : $v->e($v->plural($gc, 'parent or guardian', 'parents or guardians')) ?><span class="visually-hidden"> for <?= $v->e($m['name']) ?></span></a>
              <a class="small people-guardians" href="/people/<?= $m['user_id'] ?>/report">Report<span class="visually-hidden"> for <?= $v->e($m['name']) ?></span></a>
            <?php endif; ?></td>
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
            <details class="confirm menu">
              <summary>Manage<span class="visually-hidden"> <?= $v->e($m['name']) ?></span></summary>
              <?php if ($emailing): ?>
              <form method="post" action="/people/<?= $m['user_id'] ?>/send-link">
                <?= $v->csrfField() ?>
                <button type="submit" class="linklike"><?= $m['pending'] ? 'Resend invitation' : 'Email a password reset link' ?></button>
              </form>
              <?php endif; ?>
              <?php if ($m['two_step'] && $iAmOwner): ?>
              <form method="post" action="/people/<?= $m['user_id'] ?>/reset-two-step">
                <?= $v->csrfField() ?>
                <button type="submit" class="linklike">Reset two-step sign-in (lost phone)</button>
              </form>
              <?php endif; ?>
              <form method="post" action="/people/<?= $m['user_id'] ?>/remove">
                <?= $v->csrfField() ?>
                <p class="small">Removing access takes effect straight away. Their learning history is kept.</p>
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
    <?php endif; ?>
    <?php $pagerLabel = 'People pages'; include __DIR__ . '/../partials/pager.php'; ?>
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
            'hint' => $emailing
                ? 'We email them a link to choose their password. For young learners, use a parent’s or the school’s address.'
                : 'They sign in with this. For young learners, a parent’s or the school’s address is fine.']) ?>
        <?= $v->field('role', 'Role', ['type' => 'select', 'options' => $roles, 'value' => $old['role'], 'error' => $errors['role'] ?? null]) ?>
        <button type="submit" class="button button-small">Add person</button>
      </form>
    </section>
  </aside>
</div>
