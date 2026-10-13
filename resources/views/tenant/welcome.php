<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var list<array<string, mixed>> $cards */
$tenant = $v->shared('tenant');
?>
<section class="welcome" aria-labelledby="welcome-h">
  <div class="welcome-text">
    <?php $pixelClass = 'pixels-hero'; include __DIR__ . '/../partials/pixels.php'; ?>
    <h1 id="welcome-h"><?= $v->e($tenant->name) ?></h1>
    <?php if ($tenant->tagline !== ''): ?><p class="welcome-tagline"><?= $v->e($tenant->tagline) ?></p><?php endif; ?>
    <p class="lead">Practical courses you can follow on a phone, on a slow connection, at your own pace. Your teachers see your progress and review your work.</p>
    <p class="actions"><a class="button button-accent" href="/login">Sign in</a> <?php if ($tenant->publicCatalogue): ?><a class="button button-on-dark" href="/courses">See the courses</a><?php endif; ?></p>
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

<?php if ($cards !== []): ?>
<section class="section" aria-labelledby="latest">
  <div class="section-head">
    <h2 id="latest">Courses</h2>
    <a href="/courses">All courses</a>
  </div>
  <?php include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>

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
