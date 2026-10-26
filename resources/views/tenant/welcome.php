<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array<string, mixed>> $cards */
/** @var list<\App\Modules\Site\SitePage> $sitePages */
/** @var array{courses: int, lessons: int, labs: int, learners: int} $figures */
/** @var list<\App\Modules\Paths\LearningPath> $paths */
/** @var list<array<string, mixed>> $openCards */
$tenant = $v->shared('tenant');
$sitePages ??= [];
$figures ??= ['courses' => 0, 'lessons' => 0, 'labs' => 0, 'learners' => 0];
$paths ??= [];
$openCards ??= [];
$signUpOpen ??= false;
$catalogueVisible = $tenant->publicCatalogue || $cards !== [];
$aboutPage = null;
foreach ($sitePages as $sp) {
    $aboutPage = $sp->slug === 'about' ? $sp : $aboutPage;
}
$admissionsPage = array_values(array_filter($sitePages, static fn ($sp): bool => $sp->slug === 'admissions'))[0] ?? null;
?>
<section class="welcome" aria-labelledby="welcome-h">
  <div class="welcome-text">
    <?php $pixelClass = 'pixels-hero'; include __DIR__ . '/../partials/pixels.php'; ?>
    <h1 id="welcome-h"><?= $v->e($tenant->name) ?></h1>
    <?php if ($tenant->tagline !== ''): ?><p class="welcome-tagline"><?= $v->e($tenant->tagline) ?></p><?php endif; ?>
    <p class="lead">Practical courses you can follow on a phone, on a slow connection, at your own pace. Your teachers see your progress and review your work.</p>
    <?php if ($catalogueVisible): ?>
    <form class="hero-search" method="get" action="/courses" role="search">
      <label for="hero-q" class="visually-hidden">What do you want to learn?</label>
      <?= $v->icon('search') ?>
      <input type="search" id="hero-q" name="q" placeholder="What do you want to learn? Try networking, Python, physics" maxlength="80" autocomplete="off">
      <button type="submit" class="button">Find a course</button>
    </form>
    <?php endif; ?>
    <p class="actions">
      <?php if ($tenant->signUpOpen()): ?><a class="button button-accent" href="/join">Create an account</a> <a class="button button-on-dark" href="/login">Sign in</a>
      <?php else: ?><a class="button button-accent" href="/login">Sign in</a><?php endif; ?>
      <?php if ($tenant->publicCatalogue || $cards !== []): ?><a class="button button-on-dark" href="/courses"><?= $tenant->publicCatalogue ? 'See the courses' : 'See the free courses' ?></a><?php endif; ?>
    </p>
  </div>
  <ol class="promise" aria-label="How each course works">
    <li class="promise-step">
      <span class="promise-node" aria-hidden="true"></span>
      <h2>Learn</h2>
      <p>Short readings and videos, with the commands and examples you need. Videos load only when you press play.</p>
    </li>
    <li class="promise-step">
      <span class="promise-node" aria-hidden="true"></span>
      <h2>Build</h2>
      <p>Hands-on labs, quizzes that explain every answer, and assignments a teacher reviews.</p>
    </li>
    <li class="promise-step">
      <span class="promise-node" aria-hidden="true"></span>
      <h2>Grow</h2>
      <p><?= $tenant->certificatesEnabled ? 'Finish a course and get a certificate anyone can check online.' : 'Finish each course with a final check that shows what you can do.' ?></p>
    </li>
  </ol>
</section>

<?php $figureItems = array_filter([
    'courses' => [$figures['courses'], 'course', 'courses', 'courses'],
    'lessons' => [$figures['lessons'], 'lesson', 'lessons', 'lesson'],
    'labs' => [$figures['labs'], 'hands-on lab', 'hands-on labs', 'lab'],
    'paths' => [count($paths), 'learning path', 'learning paths', 'link'],
], static fn (array $f): bool => $f[0] > 0); ?>
<?php if ($catalogueVisible && count($figureItems) > 1): ?>
<dl class="figures" aria-label="What you can learn here">
  <?php foreach ($figureItems as [$n, $one, $many, $icon]): ?>
  <div class="figure"><dt><?= $v->icon($icon) ?><?= $v->e($n === 1 ? $one : $many) ?></dt><dd><?= number_format($n) ?></dd></div>
  <?php endforeach; ?>
</dl>
<?php endif; ?>

<?php if ($paths !== []): ?>
<section class="section path-strip" aria-labelledby="paths-h">
  <div class="section-head">
    <h2 id="paths-h">Learning paths</h2>
    <p class="muted section-sub">Courses in the right order for a goal.</p>
  </div>
  <ul class="path-cards">
    <?php foreach ($paths as $p): ?>
    <li class="path-card">
      <span class="path-card-steps" aria-hidden="true"><?php for ($i = 0; $i < min($p->courseCount, 8); $i++): ?><span></span><?php endfor; ?></span>
      <h3><a href="/paths/<?= $v->e($p->slug) ?>"><?= $v->e($p->title) ?></a></h3>
      <p class="small muted"><?= $v->e($v->plural($p->courseCount, 'course')) ?> in order<?= $p->levelLabel() !== null ? ', ' . $v->e(strtolower($p->levelLabel())) : '' ?></p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($aboutPage !== null): ?>
<section class="section about-strip" aria-labelledby="about-h">
  <h2 id="about-h">About <?= $v->e($tenant->name) ?></h2>
  <p><?= $v->e($aboutPage->summary) ?></p>
  <p class="about-links"><a href="/about">More about the school</a><?php if ($admissionsPage !== null): ?><a href="/admissions">How to join</a><?php endif; ?></p>
</section>
<?php endif; ?>

<?php if ($cards !== []): ?>
<section class="section" aria-labelledby="latest">
  <div class="section-head">
    <h2 id="latest"><?= $tenant->publicCatalogue ? 'Popular courses' : 'Free courses anyone can read' ?></h2>
    <a href="/courses">All courses</a>
  </div>
  <?php include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>

<?php if ($openCards !== []): ?>
<section class="section open-strip" aria-labelledby="open-h">
  <div class="section-head">
    <h2 id="open-h">Start reading now, free</h2>
    <a href="/courses?show=open">All open courses</a>
  </div>
  <p class="muted section-sub">No account needed for the lessons. Join when you want to save progress and take the quizzes.</p>
  <?php $cards = $openCards; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>

<?php $everydayCards ??= []; if ($everydayCards !== []): ?>
<section class="section everyday-strip" aria-labelledby="everyday-h">
  <div class="section-head">
    <h2 id="everyday-h">Learn for everyday life</h2>
    <a href="/courses?track=everyday">All everyday courses</a>
  </div>
  <p class="muted section-sub">Short, practical courses for your phone, your money and your business. No school needed: start on your phone today and go at your own pace.</p>
  <?php $cards = $everydayCards; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>

<section class="section audience" aria-labelledby="audience-h">
  <h2 id="audience-h">Who learns here</h2>
  <ul class="audience-list">
    <li><?= $v->icon('code') ?><h3>Programmers</h3><p>Skip what you know, practise in the code playground and labs, and ask under any lesson when you are stuck.</p></li>
    <li><?= $v->icon('school') ?><h3>Students</h3><p>Lessons that work on a phone, quizzes that explain every answer, and teachers who see your progress.</p></li>
    <?php if ($everydayCards !== []): ?><li><?= $v->icon('people') ?><h3>Traders, artisans and parents</h3><p>Use your phone for money, selling and learning, safely. Short lessons you can save and read offline.</p></li><?php endif; ?>
    <li><?= $v->icon('briefcase') ?><h3>Adults changing career</h3><p>Practical skills in a sensible order<?= $tenant->certificatesEnabled ? ', with certificates employers can check online' : '' ?>. Learn around work, at your own pace.</p></li>
  </ul>
  <?php if ($signUpOpen): ?><p class="audience-cta"><a class="button button-accent" href="/join">Create your free account</a><span class="muted small">Paid courses show their price before you join them.</span></p><?php endif; ?>
</section>

<?php if ($tenant->certificatesEnabled): ?>
<section class="section verify-strip" aria-labelledby="verify-h">
  <div>
    <h2 id="verify-h">Checking a certificate?</h2>
    <p class="muted">Enter the number printed on it.</p>
  </div>
  <form method="get" action="/verify" class="verify-inline">
    <label class="visually-hidden" for="verify-code">Certificate number</label>
    <input id="verify-code" name="code" placeholder="ABCD-EFGH-JKLM" autocomplete="off" maxlength="20">
    <button type="submit" class="button button-quiet">Check</button>
  </form>
</section>
<?php endif; ?>
