<?php
/**
 * One learner's report card (ADR 0046): printable on one A4 page.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var array{subjects: list<string>, components: list<array{exam: ?\App\Modules\Exams\Cbt\CbtExam, label: string, weight: int}>, rows: array<int, array{subjects: array<string, array{parts: array<int, int|string>, score: ?int, grade: ?string, word: ?string, position: ?int}>, average: ?float, position: ?int, absent: list<string>, attendance: ?array{held: int, present: int, late: int, rate: ?int}}>, ranked: int, subjectCounts: array<string, int>} $sheet
 * @var array{user_id: int, name: string} $learner
 * @var array{teacher: string, head: string} $remarks
 * @var bool $draftNote
 */
use App\Modules\ReportCards\Grading;

$row = $sheet['rows'][$learner['user_id']] ?? ['subjects' => [], 'average' => null, 'position' => null, 'absent' => [], 'attendance' => null];
$cardId = 'card-' . $learner['user_id'];
$bands = Grading::bands($card->grading);
?>
<article class="receipt report-sheet report-card" aria-labelledby="<?= $cardId ?>-h">
  <header class="receipt-head">
    <div>
      <?php if ($school->hasLogo()): ?><img class="receipt-logo" src="/logo?v=<?= $v->e($school->logoVersion()) ?>" alt="" height="48"><?php endif; ?>
      <p class="receipt-school"><?= $v->e($school->name) ?></p>
      <?php if ($school->contactEmail !== ''): ?><p class="small muted"><?= $v->e($school->contactEmail) ?><?= $school->contactPhone !== '' ? ', ' . $v->e($school->contactPhone) : '' ?></p><?php endif; ?>
    </div>
    <div class="receipt-title">
      <h2 class="report-card-h" id="<?= $cardId ?>-h">Report card</h2>
      <p class="small muted"><?= $v->e($card->title) ?></p>
      <?php if (($draftNote ?? false) && !$card->isPublished()): ?><p class="small"><?= $v->badge('Draft: not shared yet', 'draft') ?></p><?php endif; ?>
    </div>
  </header>

  <dl class="receipt-facts">
    <dt>Learner</dt><dd><strong><?= $v->e($learner['name']) ?></strong></dd>
    <dt><?= $v->e($school->groupWord(false, true)) ?></dt><dd><?= $v->e($card->groupName) ?></dd>
    <dt>Average</dt><dd><?= $row['average'] === null ? '–' : $v->e(number_format($row['average'], 1)) ?></dd>
    <dt>Position in <?= $v->e(strtolower($school->groupWord(false))) ?></dt><dd><?= $row['position'] === null ? '–' : $v->e(Grading::ordinal($row['position']) . ' of ' . $sheet['ranked']) ?></dd>
    <?php if ($row['attendance'] !== null && $row['attendance']['held'] > 0): $att = $row['attendance']; ?>
    <dt>Attendance</dt><dd><?= $att['present'] + $att['late'] ?> of <?= $att['held'] ?> live classes<?= $att['rate'] !== null ? ' (' . $att['rate'] . '%)' : '' ?></dd>
    <?php endif; ?>
  </dl>

  <section class="report-part" aria-label="Scores">
    <?php if ($row['subjects'] === []): ?>
      <p class="muted">No exam results for this term yet.</p>
    <?php else: ?>
    <div class="report-scroll"><table class="table receipt-lines report-card-table">
      <thead><tr>
        <th scope="col">Subject</th>
        <?php foreach ($sheet['components'] as $c): ?><th scope="col" class="num"><?= $v->e($c['label']) ?><span class="small muted"><?= (int) $c['weight'] ?>%</span></th><?php endforeach; ?>
        <th scope="col" class="num">Score</th><th scope="col">Grade</th><th scope="col" class="num">Position</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sheet['subjects'] as $subject): $cell = $row['subjects'][$subject] ?? null; if ($cell === null) { continue; } ?>
        <tr>
          <th scope="row"><?= $v->e($subject) ?></th>
          <?php foreach ($sheet['components'] as $i => $c): $part = $cell['parts'][$i] ?? null; ?>
            <td class="num"><?= $part === null ? '<span class="muted">–</span>' : ($part === 'absent' ? '<span class="rc-absent">Absent</span>' : (int) $part) ?></td>
          <?php endforeach; ?>
          <td class="num"><strong><?= $cell['score'] ?? '–' ?></strong></td>
          <td><?= $cell['grade'] === null ? '–' : '<strong>' . $v->e($cell['grade']) . '</strong> <span class="small muted">' . $v->e((string) $cell['word']) . '</span>' ?></td>
          <td class="num"><?= $cell['position'] === null ? '–' : $v->e(Grading::ordinal($cell['position'])) . '<span class="small muted"> of ' . ($sheet['subjectCounts'][$subject] ?? 0) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($row['absent'] !== []): ?><p class="small">Absent from <?= $v->e(implode(', ', $row['absent'])) ?>: those parts count as 0.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="report-part rc-remarks" aria-label="Remarks">
    <div><h3>Class teacher's remark</h3><p><?= $remarks['teacher'] !== '' ? nl2br($v->e($remarks['teacher'])) : '<span class="muted">–</span>' ?></p></div>
    <div><h3>Head's remark</h3><p><?= $remarks['head'] !== '' ? nl2br($v->e($remarks['head'])) : '<span class="muted">–</span>' ?></p></div>
    <?php if ($card->nextTerm !== ''): ?><p class="small"><strong><?= $v->e($card->nextTerm) ?></strong></p><?php endif; ?>
  </section>

  <p class="small muted rc-key">Each subject is out of 100: <?= $v->e(implode(', ', array_map(static fn (array $c): string => $c['label'] . ' ' . $c['weight'] . '%', $sheet['components']))) ?>.
    Grades: <?= $v->e(implode(', ', array_map(static fn (array $b, int $i) => $b[1] . ' ' . $b[0] . ($i === 0 ? '–100' : '–' . ($bands[$i - 1][0] - 1)), $bands, array_keys($bands)))) ?>.</p>
</article>
