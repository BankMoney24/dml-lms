<?php
/**
 * The school's activity log (ADM-002), filtered by kind and person, grouped by day (update 36).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{who: string, what: string, warning: bool, at: string}> $rows
 * @var ?int $nextBefore
 * @var bool $isFirstPage
 * @var string $kind
 * @var string $who
 * @var string $todayKey
 * @var string $yesterdayKey
 */
use App\Modules\Audit\ActivityDescriber;

$keep = array_filter(['kind' => $kind, 'who' => $who]);
$link = static fn (array $q): string => '/activity' . ($q === [] ? '' : '?' . http_build_query($q));
$days = [];
foreach ($rows as $r) {
    $days[$v->datetime($r['at'], 'Y-m-d')][] = $r;
}
?>
<header class="page-head">
  <h1>Activity</h1>
  <p class="lead">Sign-ins, changes to people and courses, payments and learner milestones, newest first. Times are in <?= $v->e($v->shared('tenant')?->timezone ?? 'UTC') ?>.</p>
</header>
<?php $schoolTab = 'activity'; include __DIR__ . '/../school/_tabs.php'; ?>

<form method="get" action="/activity" class="list-tools" role="search" aria-label="Filter activity" data-autosubmit>
  <div class="list-tool">
    <label for="act-kind">Show</label>
    <select id="act-kind" name="kind">
      <option value="">Everything</option>
      <?php foreach (ActivityDescriber::KINDS as $key => [$label]): ?><option value="<?= $key ?>"<?= $kind === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="list-tool list-tool-find">
    <label for="act-who">Person</label>
    <input id="act-who" name="who" type="search" value="<?= $v->e($who) ?>" maxlength="80" placeholder="Name or email of who did it" autocomplete="off">
  </div>
  <div class="list-tool-actions">
    <button type="submit" class="button button-small button-quiet">Show</button>
    <?php if ($keep !== []): ?><a class="small" href="/activity">Clear</a><?php endif; ?>
  </div>
</form>

<?php if ($rows === []): ?>
  <?= $keep === []
      ? $v->emptyState('Nothing yet', 'Activity appears here as people sign in, build courses and learn.')
      : $v->emptyState('Nothing matches', $isFirstPage ? 'Try another kind of activity, or check the spelling of the name.' : 'There is nothing older than this.') ?>
<?php else: ?>
<div class="activity-days">
  <?php foreach ($days as $day => $list):
      $label = $day === $todayKey ? 'Today' : ($day === $yesterdayKey ? 'Yesterday' : $v->datetime($list[0]['at'], 'l j F Y')); ?>
  <section class="activity-day" aria-label="<?= $v->e($label) ?>">
    <h2 class="activity-day-h"><?= $v->e($label) ?> <span class="tab-count">(<?= count($list) ?>)</span></h2>
    <ol class="activity">
      <?php foreach ($list as $r): ?>
      <li<?= $r['warning'] ? ' class="is-warning"' : '' ?>>
        <time class="activity-time" datetime="<?= $v->datetime($r['at'], 'Y-m-d\TH:i') ?>"><?= $v->datetime($r['at'], 'H:i') ?></time>
        <span class="activity-text"><?php if ($r['warning']): ?><span class="visually-hidden">Warning: </span><?php endif; ?><strong><?= $v->e($r['who']) ?></strong> <?= $v->e($r['what']) ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endforeach; ?>
</div>
<?php if (!$isFirstPage || $nextBefore !== null): ?>
<nav class="pager" aria-label="Pages">
  <?php if (!$isFirstPage): ?><a class="pager-step" href="<?= $v->e($link($keep)) ?>">Newest</a><?php endif; ?>
  <?php if ($nextBefore !== null): ?><a class="pager-step" href="<?= $v->e($link($keep + ['before' => (string) $nextBefore])) ?>" rel="next">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
