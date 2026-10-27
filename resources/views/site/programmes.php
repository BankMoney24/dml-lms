<?php
/**
 * Programmes (ADR 0034): formal and informal learning at the school, with every course in each.
 * Each fact here comes from the school's settings or its published courses.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var array<string, list<\App\Modules\Courses\Course>> $groups school | skills | everyday | other
 * @var bool $signedIn
 */
use App\Modules\Courses\Course;

$modes = [
    'school' => ['School subjects', 'Formal learning', 'Subjects from the school’s curriculum, taught in order and assessed.'],
    'skills' => ['Skills for work', 'Structured training', 'Practical courses for a job, a trade or a business, with hands-on practice.'],
    'everyday' => ['Everyday learning', 'Informal learning', 'Short, practical lessons for life and work, at your own pace and outside any curriculum.'],
    'other' => ['Other courses', '', 'More courses from the school.'],
];
$cost = static function (array $list): string {
    $paid = count(array_filter($list, static fn (Course $c): bool => $c->isPaid()));
    return $paid === 0 ? 'Free' : ($paid === count($list) ? 'Each course shows its fee' : 'Some free, some with a fee shown on the course');
};
$join = $signedIn ? null : ($school->signUpOpen() ? 'Make an account on this website' : 'The school adds learners; ask the school for an account');
$pace = match ($school->calendar) {
    'terms' => 'Follows the school’s terms',
    'semesters' => 'Follows the school’s semesters',
    default => 'Lessons in order, at the pace the teacher sets',
};
$facts = [
    'school' => array_filter([
        'Who it is for' => $school->levels !== [] ? 'Learners in ' . implode(', ', $school->levels) : 'Learners following the school’s curriculum',
        'Pace' => $pace,
        'Teachers' => 'Can mark work, run live lessons and answer questions',
        'Families' => 'Parents and guardians can follow progress and print a report sheet',
        'Joining' => $join,
    ]),
    'skills' => array_filter([
        'Who it is for' => 'Anyone building skills for a job or a business',
        'Pace' => 'Modules in order, on your own or with a ' . $school->groupWord(),
        'Teachers' => 'Can review assignments and answer questions',
        'Joining' => $join,
    ]),
    'everyday' => array_filter([
        'Who it is for' => 'Anyone, at any age',
        'Pace' => 'Short lessons, whenever you have a few minutes',
        'Teachers' => 'Not needed: quizzes check your understanding as you go',
        'Without data' => 'Signed-in learners can save lessons on their phone and read them offline',
        'Joining' => count(array_filter($groups['everyday'] ?? [], static fn (Course $c): bool => $c->isOpen())) > 0
            ? 'Open courses can be read without an account; ' . ($signedIn ? 'your progress is saved' : 'sign up to save your progress')
            : $join,
    ]),
];
$total = array_sum(array_map('count', $groups));
?>
<?= $v->breadcrumbs(['Home' => '/', 'Programmes' => null]) ?>
<header class="page-head">
  <h1>Programmes</h1>
  <p class="lead">How you can learn at <?= $v->e($school->name) ?>, from formal school subjects to informal everyday learning: <?= $v->e($v->plural($total, 'course')) ?>, grouped by how you learn them.</p>
</header>

<?php $scale = array_values(array_filter(['school', 'skills', 'everyday'], static fn (string $k): bool => isset($groups[$k]))); ?>
<?php if (count($scale) > 1): ?>
<nav class="mode-scale" aria-label="Ways to learn">
  <p class="mode-scale-ends" aria-hidden="true"><span>More structure</span><span>More freedom</span></p>
  <ol>
    <?php foreach ($scale as $key): ?>
    <li><a href="#<?= $key ?>"><span class="mode-name"><?= $v->e($modes[$key][0]) ?></span><span class="mode-kind"><?= $v->e($modes[$key][1]) ?></span><span class="mode-count"><?= $v->e($v->plural(count($groups[$key]), 'course')) ?></span></a></li>
    <?php endforeach; ?>
  </ol>
</nav>
<?php endif; ?>

<?php foreach ($groups as $key => $list): [$name, $kind, $about] = $modes[$key]; ?>
<section class="mode-section" id="<?= $key ?>" aria-labelledby="<?= $key ?>-h">
  <header class="mode-head">
    <h2 id="<?= $key ?>-h"><?= $v->e($name) ?><?php if ($kind !== ''): ?> <span class="mode-head-kind"><?= $v->e($kind) ?></span><?php endif; ?></h2>
    <p><?= $v->e($about) ?></p>
  </header>
  <div class="mode-body<?= isset($facts[$key]) ? '' : ' mode-body-wide' ?>">
    <?php if (isset($facts[$key])): ?>
    <dl class="mode-facts">
      <?php foreach ($facts[$key] + ['Cost' => $cost($list)] + ($school->certificatesEnabled ? ['Certificate' => 'When you complete a course'] : []) as $term => $value): ?>
        <div><dt><?= $v->e($term) ?></dt><dd><?= $v->e((string) $value) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
    <ul class="mode-courses">
      <?php foreach ($list as $c): ?>
      <li>
        <a href="/courses/<?= $v->e($c->slug) ?>"><?= $v->e($c->title) ?></a>
        <?php if ($c->summary !== ''): ?><p><?= $v->e(mb_strimwidth($c->summary, 0, 170, '…')) ?></p><?php endif; ?>
        <p class="mode-course-meta">
          <?php if ($c->levelLabel() !== null): ?><?= $v->badge($c->levelLabel(), 'level') ?><?php endif; ?>
          <?php if ($c->durationHours !== null): ?><span><?= $v->e($v->plural($c->durationHours, 'hour')) ?></span><?php endif; ?>
          <span><?= $c->isPaid() ? $v->e((string) $c->priceLabel()) : ($c->isOpen() ? 'Free, open to everyone' : 'Free') ?></span>
        </p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endforeach; ?>

<p class="mode-foot"><a class="button button-quiet" href="/courses">Search and filter every course</a><?php if (!$signedIn && $school->signUpOpen()): ?> <a class="button" href="/join">Make an account</a><?php endif; ?></p>
