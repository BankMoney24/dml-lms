<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var array<string, mixed> $report */
/** @var string $overall */
$label = static fn (string $state): string => match ($state) {
    'ok' => '✓ Good',
    'warn' => '! Needs attention',
    default => '✕ Problem',
};
$tone = static fn (string $state): string => match ($state) {
    'ok' => 'ok',
    'warn' => 'draft',
    default => 'danger',
};
$bytes = static fn (?int $b): string => $b === null ? '–' : \App\Modules\Files\StoredFile::formatBytes($b);
['app' => $app, 'backup' => $backup, 'restore' => $restore, 'mail' => $mail, 'storage' => $storage, 'scanner' => $scanner, 'labs' => $labs] = $report;
?>
<header class="page-head">
  <h1>System</h1>
  <p class="page-head-meta"><?= $v->badge($label($overall), $tone($overall)) ?> <span>Release <?= $v->e($app['release']) ?></span></p>
  <p class="lead">Backups, the restore test, email, storage and scanning for the whole platform. Visible to platform administrators only.</p>
</header>
<?php $platformTab = 'health'; include __DIR__ . '/_tabs.php'; ?>

<div class="status-grid">
  <section class="status-card status-<?= $v->e($backup['state']) ?>" aria-labelledby="st-backup">
    <h2 id="st-backup">Backups <?= $v->badge($label($backup['state']), $tone($backup['state'])) ?></h2>
    <p><?= $v->e($backup['note']) ?></p>
    <?php if ($backup['data'] !== null): $d = $backup['data']; ?>
    <dl class="facts">
      <dt>Last good backup</dt><dd><?= $d['last_success_at'] !== '' ? $v->datetime((string) $d['last_success_at']) . ' (' . $v->e($v->plural((int) $backup['age_hours'], 'hour')) . ' ago)' : 'none' ?></dd>
      <dt>File</dt><dd><code><?= $v->e((string) ($d['last_success_file'] ?? '')) ?></code>, <?= $v->e($bytes((int) ($d['last_success_bytes'] ?? 0))) ?></dd>
      <dt>Off the server</dt><dd><?= $v->e((string) ($d['last_success_offsite'] ?? '')) ?></dd>
      <dt>Last run</dt><dd><?= $v->datetime((string) ($d['last_run_at'] ?? '')) ?>: <?= $v->e((string) ($d['last_result'] ?? '')) ?></dd>
      <dt>Kept for</dt><dd><?= (int) ($d['keep_days'] ?? 0) ?> days on this server</dd>
    </dl>
    <?php endif; ?>
  </section>

  <section class="status-card status-<?= $v->e($restore['state']) ?>" aria-labelledby="st-restore">
    <h2 id="st-restore">Restore test <?= $v->badge($label($restore['state']), $tone($restore['state'])) ?></h2>
    <p><?= $v->e($restore['note']) ?></p>
    <?php if ($restore['data'] !== null): $d = $restore['data']; ?>
    <dl class="facts">
      <dt>Checked</dt><dd><?= $v->datetime((string) ($d['checked_at'] ?? '')) ?></dd>
      <dt>Backup</dt><dd><code><?= $v->e((string) ($d['file'] ?? '')) ?></code></dd>
      <dt>Took</dt><dd><?= (int) ($d['duration_seconds'] ?? 0) ?> s</dd>
    </dl>
    <?php endif; ?>
  </section>

  <section class="status-card status-<?= $v->e($app['state']) ?>" aria-labelledby="st-app">
    <h2 id="st-app">Application <?= $v->badge($label($app['state']), $tone($app['state'])) ?></h2>
    <p><?= $v->e($app['note']) ?></p>
    <dl class="facts">
      <dt>Release</dt><dd><code><?= $v->e($app['release']) ?></code></dd>
      <dt>PHP</dt><dd><?= $v->e($app['php']) ?></dd>
      <dt>Database</dt><dd><?= $v->e($app['database']) ?></dd>
      <dt>Migrations</dt><dd><?= (int) $app['applied'] ?> applied<?= $app['pending'] !== [] ? ', ' . count($app['pending']) . ' waiting' : '' ?></dd>
    </dl>
  </section>

  <section class="status-card status-<?= $v->e($mail['state']) ?>" aria-labelledby="st-mail">
    <h2 id="st-mail">Email <?= $v->badge($label($mail['state']), $tone($mail['state'])) ?></h2>
    <p><?= $v->e($mail['note']) ?></p>
    <dl class="facts">
      <dt>Driver</dt><dd><?= $v->e($mail['driver']) ?></dd>
      <dt>Last 7 days</dt><dd><?= (int) ($mail['counts']['sent'] ?? 0) ?> sent, <?= (int) ($mail['counts']['queued'] ?? 0) ?> waiting, <?= (int) ($mail['counts']['failed'] ?? 0) ?> failed</dd>
    </dl>
  </section>

  <section class="status-card status-<?= $v->e($storage['state']) ?>" aria-labelledby="st-storage">
    <h2 id="st-storage">Storage <?= $v->badge($label($storage['state']), $tone($storage['state'])) ?></h2>
    <p><?= $v->e($storage['note']) ?></p>
    <dl class="facts">
      <dt>Disk free</dt><dd><?= $v->e($bytes($storage['free'])) ?> of <?= $v->e($bytes($storage['total'])) ?></dd>
      <dt>Quota per school</dt><dd><?= $v->e($bytes((int) $storage['quota'])) ?></dd>
    </dl>
    <?php if ($storage['schools'] !== []): ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">School</th><th scope="col" class="num">Files</th><th scope="col" class="num">Used</th></tr></thead>
      <tbody>
      <?php foreach ($storage['schools'] as $school): ?>
        <tr><td><?= $v->e($school['name']) ?></td><td class="num"><?= (int) $school['files'] ?></td><td class="num"><?= $v->e($bytes($school['bytes'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="status-card status-<?= $v->e($labs['state']) ?>" aria-labelledby="st-labs">
    <h2 id="st-labs">Virtual labs <?= $v->badge($label($labs['state']), $tone($labs['state'])) ?></h2>
    <p><?= $v->e($labs['note']) ?></p>
    <dl class="facts">
      <dt>Open now</dt><dd><?= (int) $labs['open'] ?></dd>
      <dt>Last 7 days</dt><dd><?= (int) $labs['week'] ?> started, <?= (int) $labs['passed_week'] ?> passed</dd>
    </dl>
  </section>

  <section class="status-card status-<?= $v->e($scanner['state']) ?>" aria-labelledby="st-scan">
    <h2 id="st-scan">Virus scanning <?= $v->badge($label($scanner['state']), $tone($scanner['state'])) ?></h2>
    <p><?= $v->e($scanner['note']) ?></p>
  </section>
</div>
