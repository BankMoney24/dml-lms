<?php
/**
 * People → a learner → Parents and guardians.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{id: int, name: string, email: string} $learner
 * @var list<\App\Modules\Guardians\GuardianLink> $links
 * @var array<string, string> $relationships
 * @var ?array{name: string, added: bool, emailed: bool, email: string, one_time_password: ?string} $justLinked
 * @var bool $emailing
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$base = '/people/' . $learner['id'] . '/guardians';
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$fresh = $old === [];
$checked = static fn (string $k) => ($fresh || ($old[$k] ?? '') === '1') ? ' checked' : '';
$active = array_values(array_filter($links, static fn ($l): bool => $l->isActive()));
?>
<?= $v->breadcrumbs(['People' => '/people', $learner['name'] => null]) ?>
<header class="page-head">
  <h1>Parents and guardians</h1>
  <p class="lead">Who can follow <?= $v->e($learner['name']) ?>'s learning, and what each of them can see.</p>
</header>

<?php if ($justLinked !== null): ?>
<div class="notice notice-done" role="status">
  <p><strong><?= $v->e($justLinked['name']) ?></strong> is linked to <?= $v->e($learner['name']) ?>.</p>
  <?php if ($justLinked['added'] && $justLinked['emailed']): ?>
    <p>We emailed them an invitation to choose a password.</p>
  <?php elseif ($justLinked['added'] && $justLinked['one_time_password'] !== null): ?>
    <p>They sign in as <strong><?= $v->e($justLinked['email']) ?></strong> with this one-time password, shown only once:</p>
    <p><span class="secret"><?= $v->e($justLinked['one_time_password']) ?></span></p>
    <p class="small">Give it to them in person. They choose their own password when they first sign in.</p>
  <?php else: ?>
    <p>They already have an account here, so they see <?= $v->e($learner['name']) ?> next time they sign in.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="teach-layout">
  <section aria-labelledby="linked-h">
    <h2 id="linked-h"><?= $active === [] ? 'No one linked yet' : $v->e($v->plural(count($active), 'person', 'people')) . ' linked' ?></h2>
    <?php if ($active === []): ?>
      <p class="muted">Link a parent or guardian with the form beside this once you have checked who they are, for example in person at the school office.</p>
    <?php endif; ?>
    <?php foreach ($links as $l): ?>
    <article class="panel guardian-card<?= $l->isActive() ? '' : ' is-revoked' ?>">
      <h3><?= $v->e($l->guardianName) ?> <span class="muted small"><?= $v->e($l->relationshipLabel()) ?></span></h3>
      <p class="small muted"><?= $v->e($l->guardianEmail) ?>. <?= $l->isActive() ? 'Linked ' . $v->datetime($l->verifiedAt, 'j M Y') : 'Unlinked' ?></p>
      <?php if ($l->isActive()): ?>
      <form method="post" action="<?= $base ?>/<?= $l->id ?>" class="form-compact">
        <?= $v->csrfField() ?>
        <fieldset class="field">
          <legend class="small">They can see</legend>
          <label class="choice choice-tight"><input type="checkbox" name="progress" value="1"<?= $l->canProgress ? ' checked' : '' ?>><span>Courses and progress</span></label>
          <label class="choice choice-tight"><input type="checkbox" name="results" value="1"<?= $l->canResults ? ' checked' : '' ?>><span>Quiz scores and assignment results</span></label>
          <label class="choice choice-tight"><input type="checkbox" name="notices" value="1"<?= $l->canNotices ? ' checked' : '' ?>><span>Class announcements</span></label>
        </fieldset>
        <div class="row-actions">
          <button type="submit" class="button button-small button-quiet">Save<span class="visually-hidden"> for <?= $v->e($l->guardianName) ?></span></button>
        </div>
      </form>
      <form method="post" action="<?= $base ?>/<?= $l->id ?>/revoke" class="form-inline-end">
        <?= $v->csrfField() ?>
        <button type="submit" class="linklike small">Unlink <?= $v->e($l->guardianName) ?></button>
      </form>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </section>

  <aside>
    <section class="panel" aria-labelledby="link-h">
      <h2 id="link-h">Link a parent or guardian</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="<?= $base ?>" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Their full name', ['value' => $o('name'), 'error' => $errors['name'] ?? null, 'maxlength' => 120, 'autocomplete' => 'off']) ?>
        <?= $v->field('email', 'Their email address', ['type' => 'email', 'value' => $o('email'), 'error' => $errors['email'] ?? null, 'maxlength' => 254, 'autocomplete' => 'off',
            'hint' => 'If they already have an account here, they are linked to it. Otherwise they are added as a guardian' . ($emailing ? ' and invited by email.' : ' with a one-time password.')]) ?>
        <?= $v->field('relationship', 'Relationship to ' . $learner['name'], ['type' => 'select', 'options' => ['' => 'Choose'] + $relationships, 'value' => $o('relationship'), 'error' => $errors['relationship'] ?? null]) ?>
        <fieldset class="field">
          <legend>They can see</legend>
          <label class="choice choice-tight"><input type="checkbox" name="progress" value="1"<?= $checked('progress') ?>><span>Courses and progress</span></label>
          <label class="choice choice-tight"><input type="checkbox" name="results" value="1"<?= $checked('results') ?>><span>Quiz scores and assignment results</span></label>
          <label class="choice choice-tight"><input type="checkbox" name="notices" value="1"<?= $checked('notices') ?>><span>Class announcements</span></label>
        </fieldset>
        <p class="small muted">Check who they are before linking. Guardians can only look; they never see other learners or change anything.</p>
        <button type="submit" class="button button-small">Link</button>
      </form>
    </section>
  </aside>
</div>
