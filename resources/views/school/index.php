<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var ?\App\Modules\Wizards\WizardRun $run
 * @var array{handled: int, total: int} $progress
 * @var ?array{first: bool, courses: list<array{title: string, id: ?int, error: ?string}>, people: list<array{name: string, email: string, role: string, one_time_password: ?string, existing_account: bool, emailed: bool, error: ?string}>} $result
 * @var list<array{host: string, primary: bool, verified: bool}> $domains
 * @var int $memberCount
 * @var int $courseCount
 * @var ?array{mode: string} $payments
 */
$schoolTab = 'overview';
$setup = '/setup/school';
$none = '<span class="muted">Not set</span>';
?>
<header class="page-head">
  <h1>Your school</h1>
  <p class="lead">How <?= $v->e($school->name) ?> looks to learners, how it is organised, and what it uses.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if ($result !== null): ?>
<section class="notice notice-done launch-result" role="status" aria-labelledby="result-h">
  <h2 id="result-h"><?= $result['first'] ? 'Your school is ready' : 'Settings saved' ?></h2>
  <?php if ($result['first']): ?><p>Learners can now sign in and find your published courses. You can change any of these settings below.</p><?php endif; ?>

  <?php foreach ($result['courses'] as $c): ?>
    <?php if ($c['id'] !== null): ?>
      <p><strong><?= $v->e($c['title']) ?></strong> was added as a draft. <a href="/teach/courses/<?= (int) $c['id'] ?>">Check it and publish it</a>.</p>
    <?php else: ?>
      <p class="field-error"><strong><?= $v->e($c['title']) ?></strong>: <?= $v->e((string) $c['error']) ?></p>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php
    $people = $result['people'];
    $emailed = array_filter($people, static fn ($p): bool => $p['error'] === null && $p['emailed']);
    $withPassword = array_filter($people, static fn ($p): bool => $p['error'] === null && $p['one_time_password'] !== null);
    $existing = array_filter($people, static fn ($p): bool => $p['error'] === null && !$p['emailed'] && $p['one_time_password'] === null);
    $failed = array_filter($people, static fn ($p): bool => $p['error'] !== null);
  ?>
  <?php if ($emailed !== []): ?>
    <p>Invitations emailed to <?= $v->e($v->plural(count($emailed), 'person', 'people')) ?>. Their links work for 7 days; you can resend from People.</p>
  <?php endif; ?>
  <?php if ($existing !== []): ?>
    <p><?= $v->e(implode(', ', array_map(static fn ($p): string => $p['name'], $existing))) ?> already had a DML account and can sign in here with their own password.</p>
  <?php endif; ?>
  <?php if ($withPassword !== []): ?>
    <p><strong>One-time passwords.</strong> Give each person theirs in person or by phone. They are shown only this once, and each person chooses their own password when they first sign in.</p>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Name</th><th scope="col">Sign in as</th><th scope="col">One-time password</th></tr></thead>
      <tbody>
      <?php foreach ($withPassword as $p): ?>
        <tr><td><?= $v->e($p['name']) ?> <span class="small muted">(<?= $v->e($p['role']) ?>)</span></td><td><?= $v->e($p['email']) ?></td><td><span class="secret"><?= $v->e((string) $p['one_time_password']) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <?php if ($failed !== []): ?>
    <p class="field-error">Not added:</p>
    <ul>
      <?php foreach ($failed as $p): ?><li><?= $v->e($p['name'] . ' (' . $p['email'] . '): ' . $p['error']) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$school->isSetUp()): ?>
<section class="continue setup-panel" aria-labelledby="setup-h">
  <p class="continue-kicker" id="setup-h"><?= $run === null ? 'One thing before learners arrive' : 'Setup in progress' ?></p>
  <h2 class="continue-title">Set up <?= $v->e($school->name) ?></h2>
  <ol class="board" aria-label="Setup progress">
    <?php for ($i = 0; $i < $progress['total']; $i++): ?>
      <li class="board-node <?= $i < $progress['handled'] ? 'is-lit' : 'is-off' ?>"><span class="visually-hidden">Step <?= $i + 1 ?><?= $i < $progress['handled'] ? ', done' : '' ?></span></li>
    <?php endfor; ?>
  </ol>
  <p class="continue-progress"><?= $progress['handled'] ?> of <?= $progress['total'] ?> steps done. Choose your school's type, name, colour and features, and invite your team.</p>
  <p class="continue-next"><a class="button" href="<?= $setup ?>"><?= $run === null ? 'Start setup' : 'Continue setup' ?></a></p>
</section>
<?php elseif ($run !== null): ?>
<div class="notice">
  <p><strong>You have unsaved changes to these settings</strong><?php if ($run->updatedByName !== null): ?>, last edited by <?= $v->e($run->updatedByName) ?> on <?= $v->datetime($run->updatedAt) ?><?php endif; ?>. They take effect only when someone confirms them on the review step.</p>
  <div class="row-actions">
    <a class="button button-small" href="<?= $setup ?>">Continue editing</a>
    <form method="post" action="<?= $setup ?>/discard" class="inline">
      <?= $v->csrfField() ?>
      <button type="submit" class="linklike">Discard the changes</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php $launch = $launch ?? []; $ready = count(array_filter($launch, static fn (array $i): bool => $i['done'])); ?>
<?php if ($school->isSetUp() && $launch !== []): ?>
<section class="launch" aria-labelledby="launch-h">
  <div class="launch-head">
    <h2 id="launch-h">Ready for learners?</h2>
    <p class="launch-count"><strong><?= $ready ?> of <?= count($launch) ?></strong> ready</p>
  </div>
  <?php if ($ready === count($launch)): ?>
    <p class="muted">Everything on this list is set up.</p>
  <?php else: ?>
  <ul class="launch-list">
    <?php foreach ($launch as $item): ?>
    <li class="launch-item<?= $item['done'] ? ' is-done' : '' ?>">
      <span class="launch-mark" aria-hidden="true"></span>
      <span class="launch-text"><span class="launch-title"><?= $v->e($item['title']) ?><span class="visually-hidden"><?= $item['done'] ? ': done' : ': not yet' ?></span></span>
        <span class="launch-state"><?= $v->e($item['state']) ?></span></span>
      <?php if (!$item['done'] && $item['action'] !== ''): ?><a class="launch-action" href="<?= $v->e($item['href']) ?>"><?= $v->e($item['action']) ?></a><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="settings">
  <section class="settings-group" aria-labelledby="s-look">
    <div class="settings-head">
      <h2 id="s-look">Name and look</h2>
      <a href="<?= $setup ?>/brand">Change<span class="visually-hidden"> name and look</span></a>
    </div>
    <div class="settings-look">
      <div class="settings-logo">
        <?php if ($school->hasLogo()): ?>
          <img src="/logo?v=<?= $v->e($school->logoVersion()) ?>" alt="<?= $v->e($school->name) ?> logo" height="56">
        <?php else: ?>
          <span class="muted small">No logo: the name is shown instead.</span>
        <?php endif; ?>
      </div>
      <dl class="facts">
        <dt>Name</dt><dd><?= $v->e($school->name) ?></dd>
        <dt>Tagline</dt><dd><?= $school->tagline !== '' ? $v->e($school->tagline) : $none ?></dd>
        <dt>Colour</dt><dd><span class="swatch swatch-inline theme-<?= $v->e($school->theme) ?>" aria-hidden="true"></span> <?= $v->e(\App\Modules\Tenancy\SchoolTheme::label($school->theme)) ?></dd>
        <dt>Email</dt><dd><?= $school->contactEmail !== '' ? $v->e($school->contactEmail) : $none ?></dd>
        <dt>Phone</dt><dd><?= $school->contactPhone !== '' ? $v->e($school->contactPhone) : $none ?></dd>
        <dt>Website</dt><dd><?= $school->website !== '' ? $v->e($school->website) : $none ?></dd>
      </dl>
    </div>
  </section>

  <section class="settings-group" aria-labelledby="s-org">
    <div class="settings-head">
      <h2 id="s-org">Type and academic year</h2>
      <a href="<?= $setup ?>/type">Change<span class="visually-hidden"> type and academic year</span></a>
    </div>
    <dl class="facts">
      <dt>Type</dt><dd><?= $school->kindLabel() !== null ? $v->e($school->kindLabel()) : $none ?></dd>
      <dt>Year organised in</dt><dd><?= $school->calendarLabel() !== null ? $v->e($school->calendarLabel()) : $none ?></dd>
      <dt>Classes or levels</dt><dd><?= $school->levels !== [] ? $v->e(implode(', ', $school->levels)) : $none ?></dd>
      <dt>Groups of learners</dt><dd><a href="/groups"><?= $v->e($school->groupWord(true, true)) ?></a></dd>
    </dl>
  </section>

  <section class="settings-group" aria-labelledby="s-features">
    <div class="settings-head">
      <h2 id="s-features">Learning features</h2>
      <a href="<?= $setup ?>/features">Change<span class="visually-hidden"> learning features</span></a>
    </div>
    <dl class="facts">
      <dt>Certificates</dt><dd><?= $school->certificatesEnabled ? 'Issued when a learner completes a course' : 'Off' ?></dd>
      <dt>Course list</dt><dd><?= $school->publicCatalogue ? 'Visitors can browse it' : 'Members only' ?></dd>
      <dt>Courses</dt><dd><a href="/teach"><?= $v->e($v->plural($courseCount, 'course')) ?></a></dd>
      <dt>Payments</dt><dd><a href="/school/payments"><?= $payments === null ? 'Not set up' : ($payments['mode'] === 'live' ? 'Paystack, live' : 'Paystack, test mode') ?></a></dd>
      <dt>Online classroom</dt><dd><a href="/school/classroom">Set up or change</a></dd>
    </dl>
  </section>

  <section class="settings-group" aria-labelledby="s-access">
    <div class="settings-head">
      <h2 id="s-access">Addresses and people</h2>
    </div>
    <dl class="facts">
      <dt>Web address<?= count($domains) === 1 ? '' : 'es' ?></dt>
      <dd>
        <?php foreach ($domains as $d): ?>
          <code><?= $v->e($d['host']) ?></code><?= $d['verified'] ? '' : ' ' . $v->badge('Waiting', 'draft') ?><br>
        <?php endforeach; ?>
        <span class="small muted">Your platform administrator adds new addresses.</span>
      </dd>
      <dt>Members</dt><dd><?php if ($v->can('members.manage')): ?><a href="/people"><?= $v->e($v->plural($memberCount, 'person', 'people')) ?></a><?php else: ?><?= $v->e($v->plural($memberCount, 'person', 'people')) ?><?php endif; ?></dd>
      <?php if ($school->setupCompletedAt !== null): ?><dt>Set up</dt><dd><?= $v->datetime($school->setupCompletedAt, 'j M Y') ?></dd><?php endif; ?>
      <?php if ($v->can('school.manage')): ?><dt>Other systems</dt><dd><a href="/school/api">API keys</a> <span class="small muted">let your school's own systems, such as PrimeERP, read courses, learners, progress and payments.</span></dd><?php endif; ?>
    </dl>
  </section>
</div>
