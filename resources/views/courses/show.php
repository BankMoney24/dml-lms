<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var ?\App\Modules\Learning\Enrolment $enrolment */
/** @var ?\App\Modules\Certificates\Certificate $certificate */
/** @var bool $mustPay this learner has to pay before enrolling */
/** @var bool $payOnline the school takes payments through Paystack */
/** @var ?\App\Modules\Payments\Payment $waiting a checkout started recently and not yet confirmed */
$user = $v->shared('user');
$lessonIds = [];
$counts = [];
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        $lessonIds[] = $lesson->id;
        $counts[$lesson->typeLabel()] = ($counts[$lesson->typeLabel()] ?? 0) + 1;
    }
}
$firstOpen = null;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        if ($firstOpen === null && !in_array($lesson->id, $completedIds, true)) {
            $firstOpen = $lesson;
        }
    }
}
$lessonCount = count($lessonIds);
$firstPreview = null;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        $firstPreview ??= $lesson->isOpenPreview() ? $lesson : null;
    }
}
$done = count(array_intersect($lessonIds, $completedIds));
$signUpOpen ??= false;
$staffView ??= false;
$firstReadable = null;
$firstLesson = null;
$readableCount = 0;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        $firstLesson ??= $lesson;
        $readable = $course->lessonIsReadable($lesson);
        $firstReadable ??= $readable ? $lesson : null;
        $readableCount += $readable ? 1 : 0;
    }
}
$slug = $v->e($course->slug);
?>
<div class="course-layout">
  <section class="course-intro">
    <h1><?= $v->e($course->title) ?></h1>
    <p class="page-head-meta">
      <?php if ($course->levelLabel() !== null): ?><?= $v->badge($course->levelLabel(), 'level') ?><?php endif; ?>
      <?php if ($course->durationHours !== null): ?><?= $v->badge('About ' . $v->plural($course->durationHours, 'hour')) ?><?php endif; ?>
      <?= $v->badge($v->plural(count($outline), 'module')) ?>
      <?php if ($course->isOpen()): ?><?= $v->badge('Open to everyone', 'open') ?><?php elseif (!$course->isPaid()): ?><?= $v->badge('Free', 'free') ?><?php endif; ?>
      <?php if ($v->can('course.manage')): ?><a href="/teach/courses/<?= $course->id ?>">Edit this course</a><?php endif; ?>
    </p>
    <?= $v->paragraphs($course->summary) ?>
    <?php if ($counts !== []): ?>
    <?php
      $parts = array_map(static fn ($label, $n) => $n . ' ' . strtolower($label) . ($n === 1 ? '' : (str_ends_with(strtolower($label), 'z') ? 'zes' : 's')), array_keys($counts), $counts);
      $last = array_pop($parts);
    ?>
    <p class="small muted course-counts"><?= $v->e($parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last) ?></p>
    <?php endif; ?>

    <div class="start-box<?= $enrolment !== null && $enrolment->isCompleted() ? ' is-done' : '' ?><?= $mustPay ? ' is-paid' : '' ?>" id="start">
    <?php if ($certificate !== null): ?>
      <p class="start-title">You completed this course</p>
      <p>Your certificate is ready. Anyone can check it online with its number.</p>
      <p><a class="button" href="/certificates/<?= $v->e($certificate->displayCode()) ?>">View and print your certificate</a></p>
    <?php elseif ($enrolment !== null && $enrolment->isCompleted()): ?>
      <p class="start-title">You completed this course</p>
      <p class="muted">Open any lesson below to go over it again.</p>
    <?php elseif ($enrolment !== null && $firstOpen !== null): ?>
      <p class="start-progress"><strong><?= $done ?> of <?= $lessonCount ?></strong> lessons done</p>
      <progress class="meter" max="<?= max(1, $lessonCount) ?>" value="<?= $done ?>" aria-label="Your progress"></progress>
      <p class="start-action"><a class="button" href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $firstOpen->id ?>"><?= $done === 0 ? 'Start' : 'Continue' ?>: <?= $v->e($firstOpen->title) ?></a></p>
    <?php elseif ($user === null && $course->isOpen() && $firstReadable !== null): ?>
      <p class="start-title">Free and open to everyone</p>
      <p>Read the lessons and watch the videos without an account. <?= $signUpOpen ? 'Create a free account' : 'Sign in' ?> when you want to save your progress<?= $lessonCount > $readableCount ? ', take the quizzes and labs' : '' ?><?= $v->shared('tenant')?->certificatesEnabled ? ' and earn the certificate' : '' ?>.</p>
      <p class="start-action"><a class="button" href="/courses/<?= $slug ?>/preview/<?= $firstReadable->id ?>"><?= $v->icon('lesson') ?>Start reading: <?= $v->e($firstReadable->title) ?></a></p>
      <p class="start-alt"><a href="<?= $signUpOpen ? '/join' : '/login' ?>"><?= $signUpOpen ? 'Create a free account' : 'Sign in' ?></a><?= $signUpOpen ? ' or <a href="/login">sign in</a>' : '' ?> to keep your progress.</p>
    <?php elseif ($user === null): ?>
      <?php if ($course->isPaid()): ?><p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p><?php endif; ?>
      <p class="start-action"><a class="button" href="<?= $signUpOpen ? '/join' : '/login' ?>"><?= $signUpOpen ? 'Create an account to enrol' : 'Sign in to enrol' ?></a></p>
      <?php if ($signUpOpen): ?><p class="start-alt">Already have an account? <a href="/login">Sign in</a></p><?php endif; ?>
    <?php elseif ($v->can('course.learn') && $mustPay): ?>
      <div id="pay">
        <p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p>
        <?php if ($waiting !== null): ?>
          <p class="notice">You started a payment of <?= $v->e($waiting->amount()) ?> on <?= $v->e($v->datetime($waiting->createdAt)) ?>.
            If money left your account, the course opens as soon as Paystack confirms it.
            <a href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($waiting->reference)) ?>">Check now</a></p>
        <?php endif; ?>
        <?php if ($payOnline): ?>
          <form method="post" action="/courses/<?= $v->e($course->slug) ?>/pay">
            <?= $v->csrfField() ?>
            <button type="submit" class="button">Pay <?= $v->e((string) $course->priceLabel()) ?> and start</button>
          </form>
          <p class="small muted">You pay on Paystack's secure page by card, bank transfer or USSD, then come straight back here.</p>
        <?php else: ?>
          <p>This school does not take payments online yet. Ask the school how to pay. Once they record your payment, the course opens for you here.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($v->can('course.learn')): ?>
      <form method="post" action="/courses/<?= $v->e($course->slug) ?>/enrol" class="start-action">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Enrol and start</button>
      </form>
      <p class="small muted"><?= $course->isPaid() ? 'You can open it without paying because you build courses here.' : 'Free. Your progress is saved as you go.' ?></p>
      <?php if ($course->isOpen() && $firstReadable !== null): ?><p class="start-alt">Or just <a href="/courses/<?= $slug ?>/preview/<?= $firstReadable->id ?>">start reading</a> without enrolling.</p><?php endif; ?>
    <?php elseif ($staffView && $firstLesson !== null): ?>
      <p class="start-title">You teach here</p>
      <p>Open any lesson in the outline to see it as learners do, quizzes and labs included. Nothing you do is recorded as progress.</p>
      <p class="start-action"><a class="button" href="/teach/courses/<?= $course->id ?>/lessons/<?= $firstLesson->id ?>/preview">Preview the first lesson</a></p>
    <?php else: ?>
      <p class="muted">You can look through the outline. Learners enrol from this page.</p>
    <?php endif; ?>
    <?php if ($enrolment === null && $firstPreview !== null && !$course->isOpen()): ?>
      <p class="start-preview"><?= $v->icon('lesson') ?><span>Not sure yet? <a href="/courses/<?= $v->e($course->slug) ?>/preview/<?= $firstPreview->id ?>">Read “<?= $v->e($firstPreview->title) ?>” free</a>, no sign-up needed.</span></p>
    <?php endif; ?>
    </div>

    <?php if ($course->outcomes !== []): ?>
    <section class="outcomes" aria-labelledby="outcomes-h">
      <h2 id="outcomes-h">What you will be able to do</h2>
      <ul class="checklist">
        <?php foreach ($course->outcomes as $outcome): ?><li><?= $v->e($outcome) ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($course->prerequisites !== ''): ?>
    <section aria-labelledby="pre-h">
      <h2 id="pre-h">Before you start</h2>
      <div class="prose"><?= $v->markdown($course->prerequisites) ?></div>
    </section>
    <?php endif; ?>
  </section>

  <section class="course-outline" aria-labelledby="outline-title">
    <h2 id="outline-title">Course outline</h2>
    <?php $linkLessons = $enrolment !== null; include __DIR__ . '/_trace.php'; ?>
    <?php if ($enrolment === null && $course->isOpen()): ?><p class="small muted trace-note">Readings and videos open for everyone. Join to do the quizzes, labs and assignments and keep your progress.</p><?php endif; ?>
  </section>
</div>
