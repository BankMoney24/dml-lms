<?php
/**
 * Results of one live quiz game (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Live\LiveSession $game
 * @var array{players: list<array{player: \App\Modules\Exams\Live\LivePlayer, rank: int, answers: array<int, array{choice: int, correct: bool, points: int, ms: int}>}>,
 *            questions: list<array{prompt: string, options: list<string>, correct: int, counts: list<int>, answered: int, right: int, avg_ms: int}>} $results
 */
$testsTab = 'live';
$letters = ['A', 'B', 'C', 'D'];
$players = $results['players'];
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['Live quizzes' => '/teach/live', $game->title => '/teach/live/' . $game->quizId, 'Results' => null]) ?>
    <h1>Results</h1>
    <p class="lead"><?= $v->e($game->title) ?>, played <?= $v->datetime($game->createdAt) ?> by <?= $v->e($v->plural(count($players), 'player')) ?>.<?= $game->status !== 'finished' ? ' The game is still open.' : '' ?></p>
  </div>
  <p class="page-actions">
    <?php if ($game->status !== 'finished'): ?><a class="button" href="/teach/live/games/<?= $game->id ?>">Back to the game</a><?php endif; ?>
    <?php if ($players !== []): ?><a class="button button-quiet" href="/teach/live/games/<?= $game->id ?>/results.csv" download><?= $v->icon('download') ?>Download CSV</a><?php endif; ?>
  </p>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<?php if ($players === []): ?>
  <?= $v->emptyState('Nobody played', 'No one joined this game.') ?>
<?php else: ?>
<section class="section" aria-labelledby="players-h">
  <h2 id="players-h">Players</h2>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th scope="col">Player</th><th scope="col" class="num">Place</th><th scope="col" class="num">Score</th><th scope="col" class="num">Right</th></tr></thead>
      <tbody>
      <?php foreach ($players as $row): $p = $row['player']; ?>
        <tr>
          <th scope="row"><?= $v->e($p->nickname) ?><?= $p->userId === null ? ' ' . $v->badge('Guest', 'neutral') : '' ?></th>
          <td data-label="Place" class="num"><?= $row['rank'] ?></td>
          <td data-label="Score" class="num"><?= number_format($p->score) ?></td>
          <td data-label="Right" class="num"><?= $p->correctCount ?> of <?= count($game->questions) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="section" aria-labelledby="lq-h">
  <h2 id="lq-h">Questions</h2>
  <ol class="live-questions live-results">
    <?php foreach ($results['questions'] as $i => $q): $share = $q['answered'] > 0 ? (int) round($q['right'] / $q['answered'] * 100) : 0; ?>
    <li class="lq">
      <p class="lq-prompt"><?= $v->e($q['prompt']) ?></p>
      <ul class="lq-options">
        <?php foreach ($q['options'] as $j => $o): ?>
        <li class="lq-opt lq-<?= $j ?><?= $j === $q['correct'] ? ' is-key' : '' ?>"><span class="lq-letter" aria-hidden="true"><?= $letters[$j] ?></span><?= $v->e($o) ?><span class="lq-count"><?= (int) ($q['counts'][$j] ?? 0) ?></span><?= $j === $q['correct'] ? '<span class="visually-hidden"> (right answer)</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="lq-meta small"><?= $share ?>% right, <?= $q['answered'] ?> answered<?= $q['answered'] > 0 ? ', ' . number_format($q['avg_ms'] / 1000, 1) . ' seconds on average' : '' ?></p>
    </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
