<?php
/**
 * One question bank: its questions, passages, import and settings (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Bank\QuestionBank $bank
 * @var list<\App\Modules\Exams\Bank\BankQuestion> $questions
 * @var int $total
 * @var int $page
 * @var string $find
 * @var string $topic
 * @var string $state
 * @var list<string> $topics
 * @var list<\App\Modules\Exams\Bank\Passage> $passages
 * @var array<string, array<string, string>> $errors by form: settings, import, passage
 * @var array<string, array<string, mixed>> $old
 */
use App\Modules\Exams\Bank\BankQuestion;

$testsTab = 'banks';
$base = '/teach/banks/' . $bank->id;
$pager = new \App\Core\View\Pager($page, 25, $total, $base, ['q' => $find, 'topic' => $topic, 'status' => $state === 'active' ? '' : $state]);
$passageTitles = [];
foreach ($passages as $p) {
    $passageTitles[$p->id] = $p->title;
}
$settingsErrors = $errors['settings'] ?? [];
$importErrors = $errors['import'] ?? [];
$passageErrors = $errors['passage'] ?? [];
$sOld = $old['settings'] ?? ['name' => $bank->name, 'description' => $bank->description, 'practice' => $bank->practice ? '1' : ''];
$filtered = $find !== '' || $topic !== '' || $state !== 'active';
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['Question banks' => '/teach/banks', $bank->name => null]) ?>
    <h1><?= $v->e($bank->name) ?></h1>
    <p class="lead"><?= $v->e($v->plural($bank->activeCount, 'question')) ?> in use<?= $bank->retiredCount > 0 ? ', ' . $v->e((string) $bank->retiredCount) . ' retired' : '' ?>. <?= $bank->practice ? 'Open for practice.' : 'Used for exams only.' ?><?= $bank->archivedAt !== null ? ' Archived.' : '' ?></p>
  </div>
  <p class="page-actions">
    <a class="button" href="<?= $base ?>/questions/new"><?= $v->icon('review') ?>Add a question</a>
    <a class="button button-quiet" href="<?= $base ?>/export.csv" download><?= $v->icon('download') ?>Export CSV</a>
  </p>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<?php if ($bank->description !== ''): ?><p class="notice"><?= $v->e($bank->description) ?></p><?php endif; ?>

<div class="tests-layout">
  <section aria-labelledby="qs-h">
    <h2 id="qs-h" class="visually-hidden">Questions</h2>
    <form method="get" action="<?= $base ?>" class="filter-bar" role="search">
      <div class="field"><label for="q">Find</label><input id="q" name="q" type="search" value="<?= $v->e($find) ?>" placeholder="Words in the question" maxlength="80"></div>
      <?php if ($topics !== []): ?>
      <div class="field"><label for="topic">Topic</label><select id="topic" name="topic"><option value="">Every topic</option><?php foreach ($topics as $t): ?><option value="<?= $v->e($t) ?>"<?= $t === $topic ? ' selected' : '' ?>><?= $v->e($t) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><label for="status">Show</label><select id="status" name="status"><?php foreach (['active' => 'In use', 'retired' => 'Retired', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $state ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <button type="submit" class="button button-quiet">Show</button>
    </form>
    <p class="small muted list-count" role="status"><?php if ($total === 0): ?><?= $filtered ? 'No questions match.' : '' ?><?php else: ?>Showing <?= $pager->from() ?>–<?= $pager->to() ?> of <?= $total ?><?= $filtered ? ' matching' : '' ?>.<?php endif; ?></p>

    <?php if ($total === 0 && !$filtered): ?>
      <?= $v->emptyState('No questions yet', 'Add questions one at a time, or paste a whole set in the Aiken format beside this list.', $base . '/questions/new', 'Add a question') ?>
    <?php else: ?>
    <ol class="bank-questions" start="<?= $pager->from() ?>">
      <?php foreach ($questions as $q): ?>
      <li class="bq<?= $q->retiredAt !== null ? ' is-retired' : '' ?>">
        <div class="bq-head">
          <?php if ($q->passageId !== null): ?><p class="bq-passage small"><?= $v->icon('lesson') ?><?= $v->e($passageTitles[$q->passageId] ?? 'Passage') ?></p><?php endif; ?>
          <div class="bq-prompt prose prose-small"><?= $v->markdown($q->prompt) ?></div>
        </div>
        <ul class="bq-options">
          <?php foreach ($q->options as $i => $o): ?>
          <li<?= $i === $q->correct ? ' class="is-key"' : '' ?>><span class="bq-letter" aria-hidden="true"><?= BankQuestion::LETTERS[$i] ?></span><span><?= $v->e($o) ?></span><?php if ($i === $q->correct): ?><span class="visually-hidden"> (correct)</span><?= $v->icon('check', 'bq-tick') ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="bq-meta small">
          <?php if ($q->topic !== ''): ?><span class="bq-topic"><?= $v->e($q->topic) ?></span><?php endif; ?>
          <?php if ($q->difficulty !== null): ?><span><?= $v->e(BankQuestion::DIFFICULTY[$q->difficulty]) ?></span><?php endif; ?>
          <?php if ($q->facility() !== null): ?><span><?= $q->facility() ?>% right in <?= $q->timesSeen ?> attempts</span><?php elseif ($q->timesSeen > 0): ?><span>Seen in <?= $q->timesSeen ?> <?= $q->timesSeen === 1 ? 'attempt' : 'attempts' ?></span><?php endif; ?>
          <?php if ($q->retiredAt !== null): ?><?= $v->badge('Retired', 'neutral') ?><?php endif; ?>
        </p>
        <div class="bq-actions">
          <a class="button button-small button-quiet" href="<?= $base ?>/questions/<?= $q->id ?>">Edit<span class="visually-hidden"> question <?= $q->id ?></span></a>
          <form method="post" action="<?= $base ?>/questions/<?= $q->id ?>/retire" class="inline">
            <?= $v->csrfField() ?>
            <button type="submit" class="linklike small"><?= $q->retiredAt === null ? 'Retire' : 'Put back in use' ?><span class="visually-hidden"> question <?= $q->id ?></span></button>
          </form>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php $pagerLabel = 'Question pages'; include __DIR__ . '/../partials/pager.php'; ?>
    <?php endif; ?>
  </section>

  <aside class="tests-side">
    <section class="panel" id="import" aria-labelledby="import-h">
      <h2 id="import-h">Import many at once</h2>
      <?= $v->errorSummary($importErrors) ?>
      <form method="post" action="<?= $base ?>/import" enctype="multipart/form-data" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('questions', 'Paste questions', [
            'type' => 'textarea', 'rows' => 8, 'value' => (string) ($old['import']['questions'] ?? ''), 'error' => $importErrors['questions'] ?? null,
            'hint' => 'One question, its options as A. B. C. D. on their own lines, then ANSWER: and the letter. Leave a blank line between questions.',
            'class' => 'field-mono',
        ]) ?>
        <div class="field">
          <label for="file">Or choose a file <span class="optional">(optional)</span></label>
          <p class="hint" id="file-hint">Aiken text (.txt) or a spreadsheet saved as CSV with columns question, a, b, c, d, e, answer, explanation, topic, difficulty. Up to 500 questions.</p>
          <input id="file" name="file" type="file" accept=".txt,.csv,text/plain,text/csv" aria-describedby="file-hint">
        </div>
        <button type="submit" class="button button-small">Import</button>
      </form>
      <details class="small import-example">
        <summary>See an example</summary>
        <pre class="code-sample"><code>What is the SI unit of force?
A. joule
B. watt
C. newton
D. pascal
ANSWER: C
EXPLANATION: 1 N = 1 kg m/s².
TOPIC: Units
DIFFICULTY: easy</code></pre>
        <p>EXPLANATION, TOPIC and DIFFICULTY are optional. Nothing is imported unless every question reads cleanly; the message says which line to fix.</p>
      </details>
    </section>

    <section class="panel" id="passages" aria-labelledby="passages-h">
      <h2 id="passages-h">Passages</h2>
      <p class="small muted">A comprehension passage, a table or instructions that several questions share. Candidates see it beside each of those questions.</p>
      <?php if ($passages !== []): ?>
      <ul class="passage-list">
        <?php foreach ($passages as $p): ?>
        <li>
          <details<?= (string) ($old['passage']['id'] ?? '') === (string) $p->id ? ' open' : '' ?>>
            <summary><?= $v->e($p->title) ?> <span class="small muted"><?= $v->e($v->plural($p->questionCount, 'question')) ?></span></summary>
            <form method="post" action="<?= $base ?>/passages/<?= $p->id ?>" class="form" novalidate>
              <?= $v->csrfField() ?>
              <?= $v->field('title', 'Title', ['id' => 'pt-' . $p->id, 'value' => (string) (($old['passage']['id'] ?? '') === (string) $p->id ? $old['passage']['title'] : $p->title), 'maxlength' => 120]) ?>
              <?= $v->field('body', 'Passage', ['id' => 'pb-' . $p->id, 'type' => 'textarea', 'rows' => 6, 'value' => (string) (($old['passage']['id'] ?? '') === (string) $p->id ? $old['passage']['body'] : $p->body)]) ?>
              <div class="row-actions">
                <button type="submit" class="button button-small">Save</button>
                <button type="submit" name="delete" value="1" class="linklike small danger-link">Delete passage</button>
              </div>
            </form>
          </details>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <details<?= $passageErrors !== [] && !isset($old['passage']['id']) ? ' open' : '' ?>>
        <summary>Add a passage</summary>
        <?= $v->errorSummary(isset($old['passage']['id']) ? [] : $passageErrors) ?>
        <form method="post" action="<?= $base ?>/passages" class="form" novalidate>
          <?= $v->csrfField() ?>
          <?= $v->field('title', 'Title', ['id' => 'np-title', 'value' => isset($old['passage']['id']) ? '' : (string) ($old['passage']['title'] ?? ''), 'error' => isset($old['passage']['id']) ? null : ($passageErrors['title'] ?? null), 'maxlength' => 120, 'hint' => 'For example: Read the passage and answer questions 1 to 5.']) ?>
          <?= $v->field('body', 'Passage', ['id' => 'np-body', 'type' => 'textarea', 'rows' => 6, 'value' => isset($old['passage']['id']) ? '' : (string) ($old['passage']['body'] ?? ''), 'error' => isset($old['passage']['id']) ? null : ($passageErrors['body'] ?? null)]) ?>
          <button type="submit" class="button button-small">Save the passage</button>
        </form>
      </details>
    </section>

    <details class="panel"<?= $settingsErrors !== [] ? ' open' : '' ?>>
      <summary>Bank settings</summary>
      <?= $v->errorSummary($settingsErrors) ?>
      <form method="post" action="<?= $base ?>" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Subject', ['value' => (string) $sOld['name'], 'error' => $settingsErrors['name'] ?? null, 'maxlength' => 80]) ?>
        <?= $v->field('description', 'Notes for other teachers', ['type' => 'textarea', 'rows' => 2, 'value' => (string) $sOld['description'], 'error' => $settingsErrors['description'] ?? null, 'optional' => true]) ?>
        <label class="choice"><input type="checkbox" name="practice" value="1"<?= ($sOld['practice'] ?? '') === '1' ? ' checked' : '' ?>><span>Open for practice<span class="pick-hint">Learners can set themselves practice tests from this bank.</span></span></label>
        <button type="submit" class="button button-small">Save</button>
      </form>
      <form method="post" action="<?= $base ?>/archive" class="bank-archive">
        <?= $v->csrfField() ?>
        <button type="submit" class="linklike small"><?= $bank->archivedAt === null ? 'Archive this bank' : 'Put this bank back in use' ?></button>
      </form>
    </details>
  </aside>
</div>
