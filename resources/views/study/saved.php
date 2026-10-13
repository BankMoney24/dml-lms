<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var string $show
 * @var array{all: int, saved: int, notes: int} $counts
 * @var array<int, array{title: string, slug: string, items: list<array{lesson_id: int, lesson_title: string, lesson_type: string, course_slug: string, saved: bool, body: string, updated_at: string}>}> $courses
 */
$tabs = ['all' => 'Everything', 'saved' => 'Saved', 'notes' => 'With notes'];
?>
<header class="page-head">
  <h1>Saved and notes</h1>
  <p class="lead">Lessons you saved for later and the notes you wrote. Only you can see this page.</p>
</header>

<?php if ($counts['all'] === 0): ?>
  <?= $v->emptyState('Nothing saved yet', 'On any lesson, choose Save to come back to it later, or write a note beside it. They all appear here.', '/', 'Go to your courses') ?>
<?php else: ?>
<nav class="tabs" aria-label="Show">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/saved<?= $key === 'all' ? '' : '?show=' . $key ?>"<?= $show === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?> <span class="tab-count"><?= $counts[$key] ?></span></a>
  <?php endforeach; ?>
</nav>
  <?php if ($courses === []): ?>
    <p class="muted"><?= $show === 'saved' ? 'You have notes but no saved lessons.' : 'You have saved lessons but no notes yet.' ?></p>
  <?php endif; ?>
  <?php foreach ($courses as $courseId => $c): ?>
  <section class="saved-course" aria-labelledby="sc-<?= (int) $courseId ?>">
    <h2 id="sc-<?= (int) $courseId ?>"><a href="/courses/<?= $v->e($c['slug']) ?>"><?= $v->e($c['title']) ?></a></h2>
    <ul class="saved-list">
      <?php foreach ($c['items'] as $item): ?>
      <li class="saved-item<?= $item['saved'] ? ' is-saved' : '' ?>">
        <div class="saved-head">
          <?= $v->icon($item['saved'] ? 'saved-on' : 'note', 'saved-icon') ?>
          <a href="/courses/<?= $v->e($item['course_slug']) ?>/lessons/<?= $item['lesson_id'] ?><?= $item['body'] !== '' ? '#notes' : '' ?>"><?= $v->e($item['lesson_title']) ?></a>
          <span class="small muted"><?= $v->e(\App\Modules\Courses\Lesson::TYPES[$item['lesson_type']][0] ?? 'Lesson') ?>, <?= $v->datetime($item['updated_at'], 'j M') ?></span>
        </div>
        <?php if ($item['body'] !== ''): ?><div class="saved-note"><?= $v->paragraphs($item['body']) ?></div><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
