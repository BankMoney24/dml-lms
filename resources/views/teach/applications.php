<?php
/**
 * Teach → Applications (ADR 0025): people waiting for a place, oldest first.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $show
 * @var list<\App\Modules\Applications\Application> $applications
 * @var array<int, int> $positions
 * @var array{waiting: int, offered: int} $counts
 * @var bool $mayCreateAccounts
 * @var string $q
 * @var ?int $courseFilter
 * @var array<int, string> $appCourses
 */
$q ??= '';
$courseFilter ??= null;
$appCourses ??= [];
$keep = array_filter(['q' => $q, 'course' => $courseFilter === null ? '' : (string) $courseFilter]);
$tabUrl = static fn (string $show) => '/teach/applications' . (($qs = http_build_query($keep + ($show === 'waiting' ? [] : ['show' => $show]))) === '' ? '' : '?' . $qs);
?>
<header class="page-head">
  <h1>Applications</h1>
  <p class="lead">People asking for a place on a course. Offer places in the order they applied: a free course starts at once, a paid one waits for payment.</p>
</header>
<?php if ($appCourses !== []): ?>
<form method="get" action="/teach/applications" class="list-tools" role="search" aria-label="Find applications" data-autosubmit>
  <?php if ($show !== 'waiting'): ?><input type="hidden" name="show" value="<?= $v->e($show) ?>"><?php endif; ?>
  <div class="list-tool list-tool-find">
    <label for="app-q">Find</label>
    <input id="app-q" name="q" type="search" value="<?= $v->e($q) ?>" maxlength="80" placeholder="Name, email or phone" autocomplete="off">
  </div>
  <?php if (count($appCourses) > 1): ?>
  <div class="list-tool">
    <label for="app-course">Course</label>
    <select id="app-course" name="course">
      <option value="">Every course</option>
      <?php foreach ($appCourses as $cid => $ctitle): ?><option value="<?= $cid ?>"<?= $courseFilter === $cid ? ' selected' : '' ?>><?= $v->e($ctitle) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="list-tool-actions">
    <button type="submit" class="button button-small button-quiet">Show</button>
    <?php if ($keep !== []): ?><a class="small" href="/teach/applications<?= $show === 'waiting' ? '' : '?show=' . $v->e($show) ?>">Clear</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>
<nav class="tabs" aria-label="Which applications">
  <a href="<?= $v->e($tabUrl('waiting')) ?>"<?= $show === 'waiting' ? ' aria-current="page"' : '' ?>>Waiting <span class="tab-count">(<?= $counts['waiting'] ?>)</span></a>
  <a href="<?= $v->e($tabUrl('offered')) ?>"<?= $show === 'offered' ? ' aria-current="page"' : '' ?>>Offered, not yet started <span class="tab-count">(<?= $counts['offered'] ?>)</span></a>
  <a href="<?= $v->e($tabUrl('decided')) ?>"<?= $show === 'decided' ? ' aria-current="page"' : '' ?>>Decided</a>
</nav>
<?php if ($applications === [] && $keep !== []): ?>
  <p class="muted">Nothing matches. <a href="/teach/applications<?= $show === 'waiting' ? '' : '?show=' . $v->e($show) ?>">Show every application</a>.</p>
<?php elseif ($applications === []): ?>
  <?= $show === 'waiting'
      ? $v->emptyState('Nobody is waiting', 'When a course takes applications or is full, people who apply appear here, oldest first. Set this up in a course under "How people join".')
      : $v->emptyState('Nothing here yet', 'Applications move here once you decide on them.') ?>
<?php else: ?>
<ol class="app-list">
  <?php foreach ($applications as $a): ?>
  <li class="app-card is-<?= $v->e($a->status) ?>">
    <?php if ($show === 'waiting'): ?><span class="app-pos" title="Place in line for this course"><?= (int) ($positions[$a->id] ?? 0) ?></span><?php endif; ?>
    <div class="app-main">
      <p class="app-course"><a href="/courses/<?= $v->e($a->courseSlug) ?>"><?= $v->e($a->courseTitle) ?></a></p>
      <p class="app-who"><strong><?= $v->e($a->name) ?></strong> <a href="mailto:<?= $v->e($a->email) ?>"><?= $v->e($a->email) ?></a><?php if ($a->phone !== ''): ?> <a href="tel:<?= $v->e((string) preg_replace('/[^0-9+]/', '', $a->phone)) ?>"><?= $v->e($a->phone) ?></a><?php endif; ?>
        <?= $a->userId === null ? $v->badge('No account yet') : '' ?></p>
      <?php if ($a->message !== ''): ?><div class="app-message"><?= $v->paragraphs($a->message) ?></div><?php endif; ?>
      <p class="small muted">Applied <?= $v->datetime($a->createdAt, 'j M Y, H:i') ?><?= $a->decidedAt !== null ? '. ' . $v->e($a->statusLabel()) . ' ' . $v->datetime($a->decidedAt, 'j M') . ($a->decidedBy !== null ? ' by ' . $v->e($a->decidedBy) : '') : '' ?></p>
    </div>
    <div class="app-actions">
      <?php if ($a->status === 'waiting'): ?>
        <form method="post" action="/teach/applications/<?= $a->id ?>/offer"><?= $v->csrfField() ?><button type="submit" class="button button-small"<?= $a->userId === null && !$mayCreateAccounts ? ' disabled title="An administrator offers places to people without an account"' : '' ?>>Offer a place</button></form>
        <form method="post" action="/teach/applications/<?= $a->id ?>/decline"><?= $v->csrfField() ?><button type="submit" class="linklike small">Decline</button></form>
      <?php elseif ($a->status === 'offered'): ?>
        <?= $v->badge('Waiting for payment', 'draft') ?>
        <form method="post" action="/teach/applications/<?= $a->id ?>/decline"><?= $v->csrfField() ?><button type="submit" class="linklike small">Withdraw the offer</button></form>
      <?php else: ?>
        <?= $v->badge($a->statusLabel(), $a->status === 'enrolled' ? 'ok' : 'neutral') ?>
      <?php endif; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ol>
<?php if ($show === 'waiting' && !$mayCreateAccounts): ?><p class="small muted">People without an account here are offered a place by an administrator, who can create their account.</p><?php endif; ?>
<?php endif; ?>
