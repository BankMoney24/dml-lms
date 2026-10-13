<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var string $q
 * @var bool $tooShort
 * @var list<array{label: string, items: list<array{title: string, detail: string, url: string, icon: string}>}> $groups
 */
// Shows the matched part of a title, escaped piece by piece.
$mark = static function (string $text) use ($v, $q): string {
    if ($q === '') {
        return $v->e($text);
    }
    $at = mb_stripos($text, $q);
    if ($at === false) {
        return $v->e($text);
    }
    $len = mb_strlen($q);

    return $v->e(mb_substr($text, 0, $at)) . '<mark>' . $v->e(mb_substr($text, $at, $len)) . '</mark>' . $v->e(mb_substr($text, $at + $len));
};
$total = array_sum(array_map(static fn (array $g): int => count($g['items']), $groups));
?>
<header class="page-head">
  <h1>Search</h1>
</header>
<form class="search-page" role="search" method="get" action="/search">
  <label for="search-page-q" class="visually-hidden">Search courses, lessons and pages</label>
  <?= $v->icon('search', 'search-icon') ?>
  <input id="search-page-q" type="search" name="q" value="<?= $v->e($q) ?>" placeholder="Search courses, lessons and pages" maxlength="80" autocomplete="off"<?= $q === '' ? ' autofocus' : '' ?>>
  <button type="submit" class="button">Search</button>
</form>

<?php if ($q === ''): ?>
  <p class="muted">Type part of a course or lesson title. Press <kbd>/</kbd> on any page to jump to the search box.</p>
<?php elseif ($tooShort): ?>
  <p class="muted">Type at least two letters.</p>
<?php elseif ($groups === []): ?>
  <?= $v->emptyState('Nothing matches “' . $q . '”', 'Check the spelling, or try one word from the title, such as “subnet” or “phishing”.', '/courses', 'Browse all courses') ?>
<?php else: ?>
  <p class="muted small" role="status"><?= $v->e($v->plural($total, 'result')) ?> for “<?= $v->e($q) ?>”</p>
  <?php foreach ($groups as $i => $g): ?>
  <section class="search-section" aria-labelledby="sg-<?= $i ?>">
    <h2 id="sg-<?= $i ?>"><?= $v->e($g['label']) ?></h2>
    <ul class="result-list">
      <?php foreach ($g['items'] as $item): ?>
      <li><a href="<?= $v->e($item['url']) ?>"><?= $v->icon($item['icon']) ?><span class="search-option-text"><span class="result-title"><?= $mark($item['title']) ?></span><span class="search-option-detail"><?= $v->e($item['detail']) ?></span></span></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
