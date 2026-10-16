<?php
/**
 * A lesson read without joining: a free preview (CRS-003), or any reading or
 * video in an open course (ADR 0021). Nothing is recorded as progress here.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var \App\Modules\Courses\Lesson $lesson
 * @var int $position
 * @var int $total
 * @var bool $openCourse
 * @var ?\App\Modules\Courses\Lesson $previous
 * @var ?\App\Modules\Courses\Lesson $next
 * @var int $readableCount
 * @var int $needsJoining
 * @var string $lockedKinds
 * @var list<\App\Modules\Courses\Lesson> $otherPreviews
 * @var bool $signedIn
 * @var bool $signUpOpen
 * @var bool $canLearn
 * @var bool $mustPay
 * @var bool $payOnline
 */
$base = '/courses/' . $v->e($course->slug);
$certificates = (bool) ($v->shared('tenant')?->certificatesEnabled ?? false);
?>
<?= $v->breadcrumbs([$course->title => '/courses/' . $course->slug, ($openCourse ? $lesson->title : 'Free preview') => null]) ?>

<div class="lesson-layout">
<article class="lesson">
  <header class="lesson-head">
    <p class="lesson-kind"><span class="tag <?= $openCourse ? 'tag-open' : 'tag-preview' ?>"><?= $openCourse ? 'Open to everyone' : 'Free preview' ?></span> <?= $v->e($lesson->typeLabel()) ?>, lesson <?= $position ?> of <?= $total ?><?php if ($lesson->type === 'text'): ?>, <?= $lesson->readingMinutes() ?> min read<?php endif; ?></p>
    <h1><?= $v->e($lesson->title) ?></h1>
  </header>

  <?php if ($lesson->isVideo() && ($video = $lesson->video()) !== null): ?>
  <figure class="video" data-embed="<?= $v->e($video->embedUrl()) ?>" data-title="<?= $v->e($lesson->title) ?>">
    <div class="video-frame">
      <div class="video-placeholder">
        <p class="video-note">This video plays from <?= $v->e($video->providerLabel()) ?> and uses mobile data. It loads only when you choose.</p>
        <a class="button video-load" href="<?= $v->e($video->watchUrl()) ?>" rel="noopener noreferrer">▶ Play video</a>
      </div>
    </div>
  </figure>
  <?php endif; ?>
  <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->markdown($lesson->body) ?></div><?php endif; ?>

  <?php if ($openCourse): ?>
  <nav class="lesson-nav" aria-label="Lessons">
    <?php if ($previous !== null): ?>
      <a class="pager pager-prev" href="<?= $base ?>/preview/<?= $previous->id ?>" rel="prev"><span class="pager-dir">Previous lesson</span><span class="pager-title"><?= $v->e($previous->title) ?></span></a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if ($next !== null): ?>
      <a class="pager pager-next" href="<?= $base ?>/preview/<?= $next->id ?>" rel="next"><span class="pager-dir">Next lesson</span><span class="pager-title"><?= $v->e($next->title) ?></span></a>
    <?php else: ?>
      <a class="pager pager-next" href="<?= $base ?>"><span class="pager-dir">That was the last open lesson</span><span class="pager-title">Back to the course page</span></a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</article>

<aside class="lesson-side preview-side" aria-labelledby="join-h">
  <section class="join-card">
    <?php if ($openCourse): ?>
      <h2 id="join-h" class="side-title">Keep your progress</h2>
      <p>You are reading <?= $v->e($course->title) ?> as a guest: <?= $v->e($v->plural($readableCount, 'lesson')) ?> open to everyone<?= $needsJoining > 0 ? ', plus ' . $v->e($lockedKinds) . ' for people who join' : '' ?>. Joining is free<?= $certificates ? ' and leads to a certificate' : '' ?>.</p>
    <?php else: ?>
      <h2 id="join-h" class="side-title">Liked this lesson?</h2>
      <p>This is one of <?= $v->e($v->plural($total, 'lesson')) ?> in <?= $v->e($course->title) ?>. Join to keep your progress and do the rest of the course.</p>
    <?php endif; ?>
    <?php if (!$signedIn): ?>
      <?php if ($course->isPaid()): ?><p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p><?php endif; ?>
      <?php if ($signUpOpen): ?>
        <p><a class="button" href="/join?course=<?= $v->e($course->slug) ?>"><?= $v->icon('user-plus') ?><?= $course->isPaid() ? 'Create an account' : 'Create a free account' ?></a></p>
        <p class="small">Already have one? <a href="/login?next=<?= $v->e(rawurlencode('/courses/' . $course->slug . '/preview/' . $lesson->id)) ?>">Sign in</a></p>
      <?php else: ?>
        <p><a class="button" href="/login?next=<?= $v->e(rawurlencode('/courses/' . $course->slug . '/preview/' . $lesson->id)) ?>">Sign in to join</a></p>
      <?php endif; ?>
    <?php elseif ($canLearn && $mustPay): ?>
      <p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p>
      <p><a class="button" href="<?= $base ?>#pay"><?= $payOnline ? 'Pay and start' : 'How to pay' ?></a></p>
    <?php elseif ($canLearn): ?>
      <form method="post" action="<?= $base ?>/enrol">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Enrol and continue</button>
      </form>
    <?php endif; ?>
    <p class="small"><a href="<?= $base ?>">See the whole outline</a></p>
  </section>
  <?php if ($otherPreviews !== []): ?>
  <section aria-labelledby="more-h">
    <h2 id="more-h" class="small-heading"><?= $openCourse ? 'Lessons you can read' : 'More free lessons' ?></h2>
    <ol class="plain-list open-lessons">
      <?php foreach (array_slice($otherPreviews, 0, 12) as $p): ?><li><a href="<?= $base ?>/preview/<?= $p->id ?>"><?= $v->e($p->title) ?></a></li><?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>
</aside>
</div>
