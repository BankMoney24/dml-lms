<?php
/**
 * Coding challenges (ADR 0027): practice, checked in the browser.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, array{title: string, topic: string, level: string, prompt: string, arg: string, js: string, py: string, cases: list<array{0: list<mixed>, 1: mixed}>}> $challenges
 * @var array<string, string> $solved key => language
 */
$levels = [];
foreach ($challenges as $key => $c) {
    $levels[$c['level']][$key] = $c;
}
$done = count(array_intersect_key($challenges, $solved));
$total = count($challenges);
?>
<?= $v->breadcrumbs(['Code studio' => '/playground', 'Coding challenges' => null]) ?>
<header class="page-head">
  <h1>Coding challenges</h1>
  <p class="lead">Short problems to practise on. Write one function in JavaScript or Python, then run the tests. They run in your browser, so this is practice for you, not a marked result.</p>
</header>

<div class="ch-progress" aria-label="Your progress">
  <p><strong><?= $done ?> of <?= $total ?></strong> solved</p>
  <progress class="meter" max="<?= $total ?>" value="<?= $done ?>" aria-label="Challenges solved"></progress>
</div>

<?php foreach ($levels as $level => $list): ?>
<section class="ch-level" aria-labelledby="lvl-<?= $v->e(md5($level)) ?>">
  <h2 id="lvl-<?= $v->e(md5($level)) ?>"><?= $v->e($level) ?></h2>
  <ul class="ch-list">
    <?php foreach ($list as $key => $c): $isDone = isset($solved[$key]); ?>
      <li class="ch-item<?= $isDone ? ' is-done' : '' ?>">
        <span class="ch-mark" aria-hidden="true"><?= $isDone ? $v->icon('check') : '' ?></span>
        <div class="ch-text">
          <a href="/playground/challenges/<?= $v->e($key) ?><?= isset($solved[$key]) ? '?lang=' . $v->e($solved[$key]) : '' ?>"><?= $v->e($c['title']) ?></a>
          <span class="small muted"><?= $v->e($c['topic']) ?>, <?= $v->e($v->plural(count($c['cases']), 'test')) ?><?= $isDone ? ', solved in ' . $v->e(\App\Modules\Playground\Challenges::LANGUAGES[$solved[$key]] ?? '') : '' ?></span>
        </div>
        <span class="ch-langs small"><a href="/playground/challenges/<?= $v->e($key) ?>?lang=javascript">JS</a> <a href="/playground/challenges/<?= $v->e($key) ?>?lang=python">Python</a></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
