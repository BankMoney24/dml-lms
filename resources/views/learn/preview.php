<?php
/**
 * A free preview lesson, open to anyone who can see the course page (CRS-003).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var \App\Modules\Courses\Lesson $lesson
 * @var int $position
 * @var int $total
 * @var list<\App\Modules\Courses\Lesson> $otherPreviews
 * @var bool $signedIn
 * @var bool $canLearn
 * @var bool $mustPay
 * @var bool $payOnline
 */
$base = '/courses/' . $v->e($course->slug);
?>
<?= $v->breadcrumbs([$course->title => '/courses/' . $course->slug, 'Free preview' => null]) ?>

<div class="lesson-layout">
<article class="lesson">
  <header class="lesson-head">
    <p class="lesson-kind"><span class="tag tag-preview">Free preview</span> <?= $v->e($lesson->typeLabel()) ?>, lesson <?= $position ?> of <?= $total ?><?php if ($lesson->type === 'text'): ?>, <?= $lesson->readingMinutes() ?> min read<?php endif; ?></p>
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
</article>

<aside class="lesson-side preview-side" aria-labelledby="join-h">
  <section class="join-card">
    <h2 id="join-h" class="side-title">Liked this lesson?</h2>
    <p>This is one of <?= $v->e($v->plural($total, 'lesson')) ?> in <?= $v->e($course->title) ?>. Join to keep your progress and do the rest of the course.</p>
    <?php if (!$signedIn): ?>
      <?php if ($course->isPaid()): ?><p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p><?php endif; ?>
      <p><a class="button" href="/login">Sign in to join</a></p>
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
    <h2 id="more-h" class="small-heading">More free lessons</h2>
    <ul class="plain-list">
      <?php foreach ($otherPreviews as $p): ?><li><a href="<?= $base ?>/preview/<?= $p->id ?>"><?= $v->e($p->title) ?></a></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</aside>
</div>
