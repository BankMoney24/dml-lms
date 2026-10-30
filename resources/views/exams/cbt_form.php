<?php
/**
 * Set up (or change) a CBT exam (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var ?\App\Modules\Exams\Cbt\CbtExam $exam
 * @var list<\App\Modules\Exams\Bank\QuestionBank> $banks
 * @var list<array{id: int, name: string}> $groups
 * @var list<string> $problems
 * @var int $attemptCount
 * @var string $tz
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var string $now
 */
use App\Modules\Exams\Cbt\CbtExam;

$testsTab = 'exams';
$locked = $exam !== null && $exam->status !== 'draft';
$fromOld = $old !== [];
$local = static function (?string $utc) use ($tz): array {
    if ($utc === null) {
        return ['', ''];
    }
    $t = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));

    return [$t->format('Y-m-d'), $t->format('H:i')];
};
[$od, $ot] = $local($exam?->opensAt);
[$cd, $ct] = $local($exam?->closesAt);
$val = static function (string $k, string $fromExam, string $default = '') use ($old, $fromOld, $exam): string {
    if ($fromOld) {
        return (string) ($old[$k] ?? '');
    }

    return $exam !== null ? $fromExam : $default;
};
$flag = static function (string $k, bool $fromExam, bool $default) use ($old, $fromOld, $exam): bool {
    if ($fromOld) {
        return in_array((string) ($old[$k] ?? ''), ['1', 'on'], true);
    }

    return $exam !== null ? $fromExam : $default;
};
$sections = $fromOld ? (array) ($old['sections'] ?? []) : array_map(static fn (array $s): array => ['bank' => (string) $s['bank'], 'count' => (string) $s['count'], 'label' => $s['label'], 'compulsory' => $s['compulsory'] ? '1' : ''], $exam->sections ?? []);
$rows = max(4, min(8, count($sections) + 2));
$bankOptions = ['' => 'Choose a bank'];
foreach ($banks as $b) {
    $bankOptions[(string) $b->id] = $b->name . ' (' . $b->activeCount . ')';
}
$audience = $val('audience', $exam->audience ?? 'everyone', 'everyone');
$chosenGroups = array_map('intval', $fromOld ? (array) ($old['groups'] ?? []) : ($exam->groupIds ?? []));
$attempts = $val('max_attempts', $exam !== null ? (string) ($exam->maxAttempts ?? 0) : '1', '1');
$base = $exam === null ? '' : '/teach/exams/' . $exam->id;
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['CBT exams' => '/teach/exams', ($exam === null ? 'New exam' : $exam->title) => null]) ?>
    <h1><?= $exam === null ? 'New CBT exam' : $v->e($exam->title) ?></h1>
    <?php if ($exam !== null): ?><p class="lead"><?= $v->e($exam->questionsLabel()) ?> questions, <?= $exam->durationMinutes ?> minutes. <?= $attemptCount > 0 ? $v->e($v->plural($attemptCount, 'attempt')) . ' so far.' : 'No attempts yet.' ?></p><?php endif; ?>
  </div>
  <?php if ($exam !== null && $attemptCount > 0): ?><p class="page-actions"><a class="button button-quiet" href="<?= $base ?>/results"><?= $v->icon('chart') ?>Results</a></p><?php endif; ?>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<div class="tests-layout">
<div>
<?= $v->errorSummary($errors, ['sections' => 'section-bank-0', 'groups' => 'audience-groups', 'opens_date' => 'opens_date', 'closes_date' => 'closes_date']) ?>
<form method="post" action="<?= $exam === null ? '/teach/exams' : $base ?>" class="form form-wide exam-form" novalidate>
  <?= $v->csrfField() ?>
  <?= $v->field('title', 'Title', ['value' => $val('title', $exam->title ?? ''), 'error' => $errors['title'] ?? null, 'maxlength' => 120, 'hint' => 'Candidates see this, for example “SS 3 Mock UTME, March”.']) ?>
  <?= $v->field('instructions', 'Instructions', ['type' => 'textarea', 'rows' => 4, 'value' => $val('instructions', $exam->instructions ?? ''), 'error' => $errors['instructions'] ?? null, 'optional' => true, 'hint' => 'Shown before the candidate starts.']) ?>

  <fieldset class="field exam-sections"<?= isset($errors['sections']) ? ' aria-describedby="sections-error"' : '' ?>>
    <legend>Subjects (sections)</legend>
    <?php if ($locked): ?>
      <p class="hint">The paper is fixed once an exam is published, so every candidate sits the same kind of paper. To change it, make a new exam.</p>
      <ul class="plain-list">
        <?php foreach ($exam->sections as $s): ?><li><strong><?= $v->e($s['label']) ?></strong>: <?= $s['count'] ?> questions<?= $exam->chooseCount > 0 ? ($s['compulsory'] ? ', compulsory' : ', optional') : '' ?></li><?php endforeach; ?>
      </ul>
      <?php if ($exam->chooseCount > 0): ?><p class="small">Candidates choose <?= $exam->chooseCount ?> of the optional subjects.</p><?php endif; ?>
    <?php else: ?>
      <p class="hint">Each section draws its questions at random from a bank, so neighbours get different papers. Leave unused rows empty.</p>
      <?php if (isset($errors['sections'])): ?><p class="field-error" id="sections-error"><?= $v->e($errors['sections']) ?></p><?php endif; ?>
      <div class="section-rows">
        <div class="section-row section-row-head" aria-hidden="true"><span>Question bank</span><span>Questions</span><span>Name candidates see</span><span>Compulsory</span></div>
        <?php for ($i = 0; $i < $rows; $i++): $s = $sections[$i] ?? ['bank' => '', 'count' => '', 'label' => '', 'compulsory' => '']; ?>
        <div class="section-row">
          <label class="visually-hidden" for="section-bank-<?= $i ?>">Section <?= $i + 1 ?>: question bank</label>
          <select id="section-bank-<?= $i ?>" name="section_bank[<?= $i ?>]">
            <?php foreach ($bankOptions as $k => $l): ?><option value="<?= $v->e($k) ?>"<?= (string) $s['bank'] === (string) $k ? ' selected' : '' ?>><?= $v->e($l) ?></option><?php endforeach; ?>
          </select>
          <label class="visually-hidden" for="section-count-<?= $i ?>">Section <?= $i + 1 ?>: number of questions</label>
          <input id="section-count-<?= $i ?>" name="section_count[<?= $i ?>]" type="number" min="1" max="200" inputmode="numeric" value="<?= $v->e((string) $s['count']) ?>">
          <label class="visually-hidden" for="section-label-<?= $i ?>">Section <?= $i + 1 ?>: name candidates see</label>
          <input id="section-label-<?= $i ?>" name="section_label[<?= $i ?>]" maxlength="80" value="<?= $v->e((string) $s['label']) ?>" placeholder="The bank’s name">
          <label class="choice choice-tight"><input type="checkbox" name="section_compulsory[]" value="<?= $i ?>"<?= ($s['compulsory'] ?? '') === '1' ? ' checked' : '' ?>><span class="visually-hidden">Section <?= $i + 1 ?> is </span><span>Compulsory</span></label>
        </div>
        <?php endfor; ?>
      </div>
      <?= $v->field('choose_count', 'Optional subjects each candidate chooses', ['type' => 'select', 'options' => ['0' => 'None: everyone sits every section'] + array_combine(array_map('strval', range(1, 7)), array_map(static fn (int $n): string => $n . ' of the sections not marked compulsory', range(1, 7))), 'value' => $val('choose_count', (string) ($exam->chooseCount ?? 0), '0'), 'error' => $errors['choose_count'] ?? null, 'hint' => 'As in the UTME: mark Use of English compulsory and let candidates choose 3 others.']) ?>
    <?php endif; ?>
  </fieldset>

  <?= $v->field('duration_minutes', 'Time allowed, in minutes', ['type' => 'number', 'value' => $val('duration_minutes', (string) ($exam->durationMinutes ?? ''), '60'), 'error' => $errors['duration_minutes'] ?? null, 'inputmode' => 'numeric', 'class' => 'field-narrow', 'hint' => 'The clock runs on the server: closing the browser does not stop it. The UTME allows 120 minutes for 180 questions.']) ?>

  <fieldset class="field">
    <legend>When it can be sat <span class="optional">(optional)</span></legend>
    <p class="hint">Times are in the school’s time zone (<?= $v->e($tz) ?>). A candidate who starts late still has to finish by the closing time.</p>
    <div class="field-row">
      <?= $v->field('opens_date', 'Opens on', ['type' => 'date', 'value' => $val('opens_date', $od), 'error' => $errors['opens_date'] ?? null]) ?>
      <?= $v->field('opens_time', 'at', ['type' => 'time', 'value' => $val('opens_time', $ot)]) ?>
    </div>
    <div class="field-row">
      <?= $v->field('closes_date', 'Closes on', ['type' => 'date', 'value' => $val('closes_date', $cd), 'error' => $errors['closes_date'] ?? null]) ?>
      <?= $v->field('closes_time', 'at', ['type' => 'time', 'value' => $val('closes_time', $ct)]) ?>
    </div>
  </fieldset>

  <fieldset class="field pick-list"<?= isset($errors['groups']) ? ' aria-describedby="groups-error"' : '' ?>>
    <legend>Who sits it</legend>
    <label class="choice"><input type="radio" name="audience" value="everyone"<?= $audience !== 'groups' ? ' checked' : '' ?>><span>Every learner in the school</span></label>
    <label class="choice"><input type="radio" name="audience" value="groups" id="audience-groups"<?= $audience === 'groups' ? ' checked' : '' ?><?= $groups === [] ? ' disabled' : '' ?>><span>Only these <?= $v->e(strtolower($v->shared('tenant')?->groupWord(true) ?? 'classes')) ?></span></label>
    <?php if (isset($errors['groups'])): ?><p class="field-error" id="groups-error"><?= $v->e($errors['groups']) ?></p><?php endif; ?>
    <?php if ($groups !== []): ?>
    <div class="group-picks">
      <?php foreach ($groups as $g): ?><label class="choice choice-tight"><input type="checkbox" name="groups[]" value="<?= $g['id'] ?>"<?= in_array($g['id'], $chosenGroups, true) ? ' checked' : '' ?>><span><?= $v->e($g['name']) ?></span></label><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </fieldset>

  <div class="field-row">
    <?= $v->field('max_attempts', 'Attempts each', ['type' => 'select', 'options' => ['1' => 'One', '2' => 'Two', '3' => 'Three', '5' => 'Five', '10' => 'Ten', '0' => 'As many as they like'], 'value' => $attempts, 'error' => $errors['max_attempts'] ?? null]) ?>
    <?= $v->field('results', 'Candidates see their results', ['type' => 'select', 'options' => CbtExam::RESULTS, 'value' => $val('results', $exam->results ?? 'immediately', 'immediately'), 'error' => $errors['results'] ?? null]) ?>
  </div>
  <div class="pick-list">
    <label class="choice"><input type="checkbox" name="review" value="1"<?= $flag('review', $exam->review ?? true, true) ? ' checked' : '' ?>><span>Show corrections with the results<span class="pick-hint">Each question with their answer, the right one and the explanation.</span></span></label>
    <label class="choice"><input type="checkbox" name="shuffle_questions" value="1"<?= $flag('shuffle_questions', $exam->shuffleQuestions ?? true, true) ? ' checked' : '' ?>><span>Shuffle the questions<span class="pick-hint">Questions on the same passage stay together.</span></span></label>
    <label class="choice"><input type="checkbox" name="shuffle_options" value="1"<?= $flag('shuffle_options', $exam->shuffleOptions ?? true, true) ? ' checked' : '' ?>><span>Shuffle the options A to E</span></label>
  </div>
  <?= $v->field('access_code', 'Access code', ['value' => $val('access_code', $exam->accessCode ?? ''), 'error' => $errors['access_code'] ?? null, 'optional' => true, 'maxlength' => 12, 'autocomplete' => 'off', 'class' => 'field-narrow', 'hint' => 'For an exam hall: candidates need it to start, so only those in the room can. 4 to 12 letters and numbers.']) ?>

  <div class="row-actions">
    <button type="submit" class="button"><?= $exam === null ? 'Save as a draft' : 'Save' ?></button>
    <a href="/teach/exams">Cancel</a>
  </div>
</form>
</div>

<?php if ($exam !== null): ?>
<aside class="tests-side">
  <section class="panel" aria-labelledby="state-h">
    <h2 id="state-h"><?= $exam->status === 'draft' ? 'Draft' : ($exam->status === 'closed' ? 'Closed' : ($exam->isOpen($now) ? 'Open to candidates' : 'Published')) ?></h2>
    <?php if ($problems !== []): ?>
      <ul class="small problem-list"><?php foreach ($problems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($exam->status === 'draft'): ?>
      <p class="small">Candidates cannot see a draft. Try it first, then publish.</p>
      <form method="post" action="<?= $base ?>/publish"><?= $v->csrfField() ?><button type="submit" class="button"<?= $problems !== [] ? ' disabled' : '' ?>>Publish</button></form>
    <?php elseif ($exam->status === 'published'): ?>
      <p class="small"><?= $exam->opensAt !== null && $exam->opensAt > $now ? 'Opens ' . $v->datetime($exam->opensAt) . '.' : 'Candidates can start it now from Exams.' ?><?= $exam->closesAt !== null ? ' Closes ' . $v->datetime($exam->closesAt) . '.' : '' ?></p>
      <form method="post" action="<?= $base ?>/close"><?= $v->csrfField() ?><button type="submit" class="button button-quiet">Close now</button></form>
      <p class="small muted">Closing submits any attempt in progress with the answers saved so far.</p>
    <?php else: ?>
      <form method="post" action="<?= $base ?>/reopen"><?= $v->csrfField() ?><button type="submit" class="button button-quiet">Open it again</button></form>
    <?php endif; ?>
    <form method="post" action="<?= $base ?>/try" class="try-form"><?= $v->csrfField() ?><button type="submit" class="linklike">Try it as a candidate</button></form>
    <p class="small muted">Your try is not counted anywhere.</p>
  </section>
</aside>
<?php endif; ?>
</div>
