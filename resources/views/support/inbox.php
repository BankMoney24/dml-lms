<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Support\SupportRequest> $requests
 * @var array<string, int> $counts
 * @var string $filter
 * @var string $q words to find in the subject, name or email
 * @var ?string $kind a category key
 * @var ?array<string, int> $allCounts counts without the words and kind, when they are set
 */
$q ??= '';
$kind ??= null;
$allCounts ??= null;
$keep = array_filter(['q' => $q, 'kind' => (string) $kind]);
$total = array_sum($allCounts ?? $counts);
$schoolTab = 'support';
$statusTabs = ['open' => 'Needs an answer', 'answered' => 'Waiting for them', 'resolved' => 'Resolved'];
?>
<header class="page-head">
  <h1>Help desk</h1>
  <p class="lead">Questions from learners, teachers and parents. The oldest unanswered ones come first.</p>
</header>
<?php include __DIR__ . '/../school/_tabs.php'; ?>
<?php if ($total > 0): ?>
<form method="get" action="/school/support" class="list-tools" role="search" aria-label="Find requests" data-autosubmit>
  <?php if ($filter !== 'open'): ?><input type="hidden" name="status" value="<?= $v->e($filter) ?>"><?php endif; ?>
  <div class="list-tool list-tool-find">
    <label for="help-q">Find</label>
    <input id="help-q" name="q" type="search" value="<?= $v->e($q) ?>" maxlength="80" placeholder="Subject, name or email" autocomplete="off">
  </div>
  <div class="list-tool">
    <label for="help-kind">About</label>
    <select id="help-kind" name="kind">
      <option value="">Anything</option>
      <?php foreach (\App\Modules\Support\SupportRequest::CATEGORIES as $key => $label): ?><option value="<?= $v->e($key) ?>"<?= $kind === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="list-tool-actions">
    <button type="submit" class="button button-small button-quiet">Show</button>
    <?php if ($keep !== []): ?><a class="small" href="/school/support<?= $filter === 'open' ? '' : '?status=' . $v->e($filter) ?>">Clear</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>
<nav class="tabs tabs-quiet" aria-label="Show requests">
  <?php foreach ($statusTabs as $key => $label): $qs = http_build_query($keep + ($key === 'open' ? [] : ['status' => $key])); ?>
    <a href="/school/support<?= $qs === '' ? '' : '?' . $v->e($qs) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?> <span class="tab-count">(<?= (int) ($counts[$key] ?? 0) ?>)</span></a>
  <?php endforeach; ?>
</nav>
<?php if ($requests === [] && $keep !== []): ?>
  <p class="muted">Nothing matches. <a href="/school/support<?= $filter === 'open' ? '' : '?status=' . $v->e($filter) ?>">Show every request</a>.</p>
<?php elseif ($requests === []): ?>
  <p class="muted"><?= $filter === 'open' ? 'Nothing waiting. New requests show up here and in your notifications.' : 'Nothing here.' ?></p>
<?php else: ?>
<ul class="help-list">
  <?php foreach ($requests as $r): [$label, $tone] = $r->statusFor(true); ?>
  <li><a href="/help/<?= $r->id ?>"><span class="help-subject"><?= $v->e($r->subject) ?></span><span class="small muted"><?= $v->e($r->requesterName) ?>, <?= $v->e($r->categoryLabel()) ?>, <?= $v->datetime($r->updatedAt) ?></span></a><?= $v->badge($label, $tone) ?></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
