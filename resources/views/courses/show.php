<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var ?\App\Modules\Learning\Enrolment $enrolment */
/** @var ?\App\Modules\Certificates\Certificate $certificate */
/** @var bool $mustPay this learner has to pay before enrolling */
/** @var bool $payOnline the school takes payments through Paystack */
/** @var ?\App\Modules\Payments\Payment $waiting a checkout started recently and not yet confirmed */
/** @var ?\App\Modules\Payments\PaymentClaim $claim the learner's latest note that they paid the school directly */
/** @var ?\App\Modules\Payments\Plans\PaymentPlan $planHold the learner's payment plan when a part is overdue and the course is paused */
$planHold ??= null;
$partsLine = $course->offersInstalments()
    ? '<p class="small course-parts">or ' . (int) $course->instalments . ' monthly parts of about '
      . $v->e(\App\Modules\Payments\Money::format(\App\Modules\Payments\Plans\PaymentPlan::split((int) $course->priceMinor, (int) $course->instalments, 2), (string) $course->currency)) . '</p>'
    : '';
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
$joinHref = '/join?course=' . $slug;
// What a paid course includes, from the course itself (no promises it cannot keep).
$included = [];
foreach ($counts as $label => $n) {
    $included[] = $n . ' ' . strtolower($label) . ($n === 1 ? '' : (str_ends_with(strtolower($label), 'z') ? 'zes' : 's'));
}
$includedList = '<ul class="included">'
    . ($included !== [] ? '<li>' . $v->e(implode(', ', $included)) . '</li>' : '')
    . ($course->durationHours !== null ? '<li>' . $v->e('About ' . $v->plural($course->durationHours, 'hour') . ' of learning, at your own pace') . '</li>' : '')
    . (($v->shared('tenant')?->certificatesEnabled ?? false) ? '<li>A certificate anyone can check online when you finish</li>' : '')
    . '<li>Ask questions under every lesson; teachers answer</li></ul>';
$signInHref = '/login?next=' . $v->e(rawurlencode('/courses/' . $course->slug));
// Subscribing (ADR 0026): one page from "I want this" to the first lesson.
$subscribeHref = '/courses/' . $slug . '/subscribe';
$subscribeSignIn = '/login?next=' . $v->e(rawurlencode('/courses/' . $course->slug . '/subscribe'));
$claim ??= null;
/** @var array{gated: bool, mustApply: bool, application: ?\App\Modules\Applications\Application, position: ?int, placesLeft: ?int, form: array{errors: array<string, string>, old: array<string, string>}} $admission */
$admission ??= ['gated' => false, 'mustApply' => false, 'application' => null, 'position' => null, 'placesLeft' => null, 'form' => ['errors' => [], 'old' => []]];
// Apply-and-wait (ADR 0025): who sees the form, and who sees where they stand.
$applyState = null;
if ($enrolment === null && !($staffView && !$v->can('course.learn'))) {
    $app = $admission['application'];
    if (($admission['received'] ?? null) !== null) {
        $applyState = 'received';
    } elseif ($app !== null && $app->status === 'waiting') {
        $applyState = 'waiting';
    } elseif ($admission['mustApply']) {
        $applyState = 'form';
    }
}
$chips = [];
if ($course->levelLabel() !== null) {
    $chips[] = $course->levelLabel();
}
if ($course->durationHours !== null) {
    $chips[] = 'About ' . $v->plural($course->durationHours, 'hour');
}
$chips[] = $v->plural(count($outline), 'module');
$chips[] = $v->plural($lessonCount, 'lesson');
?>
<header class="course-hero">
  <div class="course-hero-art" aria-hidden="true"><?php $c = $course; include __DIR__ . '/_cover.php'; ?></div>
  <div class="course-hero-text">
    <p class="course-kicker"><a href="/courses">Courses</a><?php if ($course->trackLabel() !== null): ?><span aria-hidden="true">/</span><span><?= $v->e($course->trackLabel()) ?></span><?php endif; ?></p>
    <h1><?= $v->e($course->title) ?></h1>
    <?php if ($course->summary !== ''): ?><div class="course-hero-summary"><?= $v->paragraphs($course->summary) ?></div><?php endif; ?>
    <ul class="course-chips">
      <?php foreach ($chips as $chip): ?><li><?= $v->e($chip) ?></li><?php endforeach; ?>
      <?php if (($counts['Lab'] ?? 0) > 0): ?><li class="chip-lab"><?= $v->icon('lab') ?><?= $v->e($v->plural((int) $counts['Lab'], 'hands-on lab')) ?></li><?php endif; ?>
      <?php if ($course->isOpen()): ?><li class="chip-open">Open to everyone</li><?php elseif ($course->isPaid()): ?><li class="chip-price"><?= $v->e((string) $course->priceLabel()) ?></li><?php else: ?><li class="chip-free">Free</li><?php endif; ?>
      <?php if ($course->takesApplications()): ?><li class="chip-apply">Apply for a place</li><?php elseif ($admission['placesLeft'] !== null): ?><li class="chip-apply"><?= $admission['placesLeft'] === 0 ? 'Full: waiting list' : $v->e($v->plural($admission['placesLeft'], 'place') . ' left') ?></li><?php endif; ?>
    </ul>
    <?php if ($v->can('course.manage')): ?><p class="course-hero-edit"><a href="/teach/courses/<?= $course->id ?>"><?= $v->icon('teach') ?>Edit this course</a></p><?php endif; ?>
  </div>
</header>

<div class="course-page">
  <aside class="course-aside" aria-label="Join this course">
    <div class="start-box<?= $enrolment !== null && $enrolment->isCompleted() ? ' is-done' : '' ?><?= $mustPay ? ' is-paid' : '' ?>" id="start">
  <?php if ($certificate !== null): ?>
    <p class="start-title">You completed this course</p>
    <p>Your certificate is ready. Anyone can check it online with its number.</p>
    <p><a class="button" href="/certificates/<?= $v->e($certificate->displayCode()) ?>">View and print your certificate</a></p>
  <?php elseif ($enrolment !== null && $enrolment->isCompleted()): ?>
    <p class="start-title">You completed this course</p>
    <p class="muted">Open any lesson below to go over it again.</p>
  <?php elseif ($enrolment !== null && $planHold !== null): $holdPart = (int) $planHold->nextPart(); ?>
    <p class="start-title">The course is paused</p>
    <p>Part <?= $holdPart ?> of your payment plan, <?= $v->e($planHold->partLabel($holdPart)) ?>, was due on <?= $v->e($v->datetime($planHold->dueAt($holdPart)?->format('Y-m-d H:i:s') ?? '', 'j M Y')) ?>. Pay it and the lessons open again at once. Your progress is kept.</p>
    <p class="start-action"><a class="button" href="/courses/<?= $v->e($course->slug) ?>/subscribe#plan">Pay part <?= $holdPart ?></a></p>
  <?php elseif ($enrolment !== null && $firstOpen !== null): ?>
    <p class="start-progress"><strong><?= $done ?> of <?= $lessonCount ?></strong> lessons done</p>
    <progress class="meter" max="<?= max(1, $lessonCount) ?>" value="<?= $done ?>" aria-label="Your progress"></progress>
    <p class="start-action"><a class="button" href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $firstOpen->id ?>"><?= $done === 0 ? 'Start' : 'Continue' ?>: <?= $v->e($firstOpen->title) ?></a></p>
    <?php include __DIR__ . '/_standing.php'; ?>
    <?php
    $offlineLessons = [];
    foreach ($outline as $module) {
        foreach ($module['lessons'] as $l) {
            $offlineLessons[] = ['u' => '/courses/' . $course->slug . '/lessons/' . $l->id, 't' => $l->title];
        }
    }
    ?>
    <div class="offline-bar" data-offline-all="<?= $v->e((string) json_encode($offlineLessons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>" data-course="<?= $v->e($course->title) ?>" hidden>
      <button type="button" class="button button-small button-quiet" data-offline-toggle><?= $v->icon('download') ?><span data-offline-word>Save the whole course for offline</span></button>
      <span class="small muted" data-offline-status role="status"></span>
    </div>
  <?php elseif ($enrolment !== null): ?>
    <p class="start-title">Every lesson is done</p>
    <?php include __DIR__ . '/_standing.php'; ?>
  <?php elseif ($applyState !== null): ?>
    <?php include __DIR__ . '/_apply.php'; ?>
  <?php elseif ($user === null && $course->isOpen() && $firstReadable !== null): ?>
    <p class="start-title">Free and open to everyone</p>
    <p>Read the lessons and watch the videos without an account. <?= $signUpOpen ? 'Create a free account' : 'Sign in' ?> when you want to save your progress<?= $lessonCount > $readableCount ? ', take the quizzes and labs' : '' ?><?= $v->shared('tenant')?->certificatesEnabled ? ' and earn the certificate' : '' ?>.</p>
    <p class="start-action"><a class="button" href="/courses/<?= $slug ?>/preview/<?= $firstReadable->id ?>"><?= $v->icon('lesson') ?>Start reading: <?= $v->e($firstReadable->title) ?></a></p>
    <p class="start-alt"><a href="<?= $signUpOpen ? $joinHref : $signInHref ?>"><?= $signUpOpen ? 'Create a free account' : 'Sign in' ?></a><?= $signUpOpen ? ' or <a href="' . $signInHref . '">sign in</a>' : '' ?> to keep your progress.</p>
  <?php elseif ($user === null): ?>
    <?php if ($course->isPaid()): ?><p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p><?= $partsLine ?><?= $includedList ?><?php else: ?><p class="start-title">Free to take</p><?php endif; ?>
    <p class="start-action"><a class="button" href="<?= $subscribeHref ?>"><?= $course->isPaid() ? 'Subscribe for ' . $v->e((string) $course->priceLabel()) : 'Subscribe for free' ?></a></p>
    <p class="start-alt"><?= $signUpOpen
        ? 'You will create an account' . ($course->isPaid() ? ', pay, then start.' : ', then start.') . ' Already have one? <a href="' . $subscribeSignIn . '">Sign in</a>'
        : 'This school adds its learners. <a href="' . $subscribeSignIn . '">Sign in to subscribe</a>' ?></p>
  <?php elseif ($v->can('course.learn') && $mustPay): ?>
    <div id="pay">
      <p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p>
      <?= $partsLine ?>
      <?= $includedList ?>
      <?php if ($waiting !== null): ?>
        <p class="notice">You started a payment of <?= $v->e($waiting->amount()) ?> on <?= $v->e($v->datetime($waiting->createdAt)) ?>.
          If money left your account, the course opens as soon as Paystack confirms it.
          <a href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($waiting->reference)) ?>">Check now</a></p>
      <?php endif; ?>
      <?php if ($claim !== null && $claim->isPending()): ?>
        <p class="notice">The school is checking the <?= $v->e($claim->amount()) ?> you told them you paid. The course opens when they confirm it.</p>
        <p class="start-alt"><a href="<?= $subscribeHref ?>">See your payment note</a></p>
      <?php else: ?>
        <p class="start-action"><a class="button" href="<?= $subscribeHref ?>">Subscribe for <?= $v->e((string) $course->priceLabel()) ?></a></p>
        <p class="small muted"><?= $payOnline
            ? 'Pay online by card, bank transfer or USSD, or pay the school directly. The course opens as soon as the payment is confirmed.'
            : 'Pay the school by transfer or at the office, then tell them on the next page. The course opens once they confirm it.' ?></p>
      <?php endif; ?>
    </div>
  <?php elseif ($v->can('course.learn')): ?>
    <form method="post" action="<?= $subscribeHref ?>" class="start-action">
      <?= $v->csrfField() ?>
      <button type="submit" class="button">Subscribe and start</button>
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
  </aside>

  <div class="course-main">
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

    <section class="course-outline" aria-labelledby="outline-title">
    <h2 id="outline-title">Course outline</h2>
    <?php $linkLessons = $enrolment !== null; include __DIR__ . '/_trace.php'; ?>
    <?php if ($enrolment === null && $course->isOpen()): ?><p class="small muted trace-note">Readings and videos open for everyone. Join to do the quizzes, labs and assignments and keep your progress.</p><?php endif; ?>
  </section>
  </div>
</div>
