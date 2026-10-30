<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Console\ConsoleApplication;
use App\Modules\Exams\Bank\SampleBanks;
use App\Modules\Exams\Bank\SampleSubjects;
use App\Modules\Exams\StarterKit\SampleExams;
use App\Modules\Exams\StarterKit\SampleQuizzes;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0043: the tests starter kit — sample banks, live quizzes and draft exams, safe to add again. */
final class StarterKitTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function rows(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->db()->fetchValue("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND {$where}", [$this->tenant->id]);
    }

    public function testTheKitAddsBanksQuizzesAndDraftExamsOnce(): void
    {
        $questions = array_sum(array_map(static fn (array $b): int => count($b['questions']), SampleBanks::all()));
        $this->assertGreaterThanOrEqual(200, $questions);

        $this->as('teacher@school.test');
        $live = $this->get(self::HOST, '/teach/live');
        $this->assertStringContainsString('Add the starter kit', $live->body);
        $this->assertStringContainsString('not past JAMB, WAEC or NECO questions', $live->body);
        $this->assertRedirectTo('/teach/live', $this->post(self::HOST, '/teach/banks/samples', ['back' => '/teach/live']));
        $this->assertStringContainsString('Added 16 sample banks, ' . $questions . ' sample questions, 6 live quizzes and 5 draft exams.', $this->get(self::HOST, '/teach/live')->body);

        $this->assertSame(count(SampleBanks::all()), $this->rows('question_banks'));
        $this->assertSame($questions, $this->rows('bank_questions'));
        $this->assertSame(count(SampleQuizzes::all()), $this->rows('live_quizzes'));
        $this->assertSame(48, $this->rows('live_questions'));
        $this->assertSame(count(SampleExams::all()), $this->rows('cbt_exams', "status = 'draft'"), 'the exams wait for a teacher to publish them');
        $mock = $this->db()->fetchOne("SELECT * FROM cbt_exams WHERE tenant_id = ? AND title = 'Mock UTME: sciences (sample)'", [$this->tenant->id]);
        $this->assertNotNull($mock);
        $this->assertSame(3, (int) $mock['choose_count']);
        $this->assertSame([20, 15, 15, 15, 15], array_column(json_decode((string) $mock['sections'], true), 'count'));
        $this->assertNull($mock['opens_at']);

        $this->assertRedirectTo('/teach/exams', $this->post(self::HOST, '/teach/banks/samples', ['back' => '/teach/exams']));
        $this->assertStringContainsString('Everything in the starter kit is already here.', $this->get(self::HOST, '/teach/exams')->body);
        $this->assertSame(count(SampleQuizzes::all()), $this->rows('live_quizzes'), 'nothing twice');
        $this->assertStringContainsString('Everything in the kit is in your lists.', $this->get(self::HOST, '/teach/banks')->body);
        $this->assertRedirectTo('/teach/banks', $this->post(self::HOST, '/teach/banks/samples', ['back' => 'https://elsewhere.test/']), 'only our own pages');

        // A sample exam can be published as it is, and sat.
        $this->post(self::HOST, '/teach/exams/' . $mock['id'] . '/publish');
        $this->assertSame('published', $this->db()->fetchValue('SELECT status FROM cbt_exams WHERE id = ?', [$mock['id']]));
        $this->as('amaka@school.test');
        $started = $this->post(self::HOST, '/exams/' . $mock['id'] . '/start', ['subjects' => ['1', '2', '4']]);
        $this->assertMatchesRegularExpression('#^/exams/attempts/\d+$#', (string) $started->header('Location'));
        $this->assertSame(403, $this->post(self::HOST, '/teach/banks/samples')->status, 'learners cannot add the kit');
    }

    public function testOlderSampleBanksAreToppedUpAndRemovedThingsStayRemoved(): void
    {
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/banks/samples');
        // As if the school had added the samples before update 43: Physics without its newer questions.
        $physics = (int) $this->db()->fetchValue("SELECT id FROM question_banks WHERE tenant_id = ? AND name = 'Physics (sample)'", [$this->tenant->id]);
        $newer = array_column(SampleSubjects::topUps()['Physics (sample)'], 0);
        foreach ($newer as $prompt) {
            $this->db()->execute('DELETE FROM bank_questions WHERE bank_id = ? AND prompt = ?', [$physics, $prompt]);
        }
        $chemistry = (int) $this->db()->fetchValue("SELECT id FROM question_banks WHERE tenant_id = ? AND name = 'Chemistry (sample)'", [$this->tenant->id]);
        $this->post(self::HOST, '/teach/banks/' . $chemistry . '/archive');
        $this->db()->execute("DELETE FROM bank_questions WHERE bank_id = ? AND topic = 'Redox'", [$chemistry]);
        $quiz = (int) $this->db()->fetchValue("SELECT id FROM live_quizzes WHERE tenant_id = ? AND title = 'Python warm-up (sample)'", [$this->tenant->id]);
        $this->post(self::HOST, '/teach/live/' . $quiz . '/archive');
        $this->db()->execute("DELETE FROM cbt_exams WHERE tenant_id = ? AND title = 'Mathematics class test (sample)'", [$this->tenant->id]);

        $this->assertStringContainsString('Add what is missing from the kit', $this->get(self::HOST, '/teach/banks')->body, 'new sample questions show as missing');
        $this->post(self::HOST, '/teach/banks/samples');
        $this->assertStringContainsString('Added ' . count($newer) . ' sample questions and 1 draft exam.', $this->get(self::HOST, '/teach/banks')->body);
        $this->assertSame(count(SampleSubjects::topUps()['Physics (sample)']) + 10, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM bank_questions WHERE bank_id = ?', [$physics]));
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM bank_questions WHERE bank_id = ? AND topic = 'Redox'", [$chemistry]), 'an archived bank is left alone');
        $this->assertSame(1, $this->rows('live_quizzes', "title = 'Python warm-up (sample)'"), 'an archived quiz does not come back');
        $this->assertSame(1, $this->rows('cbt_exams', "title = 'Mathematics class test (sample)'"), 'a deleted exam does');
    }

    public function testTheConsoleCommandAddsTheKitForTheServer(): void
    {
        $console = new ConsoleApplication($this->app);
        ob_start();
        $code = $console->run(['console', 'exams:starter-kit', 'school']);
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('6 live quizzes and 5 draft exams', $out);
        $this->assertSame(count(SampleQuizzes::all()), $this->rows('live_quizzes'));
        $this->assertSame('owner@school.test', $this->db()->fetchValue('SELECT u.email FROM live_quizzes q JOIN users u ON u.id = q.created_by WHERE q.tenant_id = ? LIMIT 1', [$this->tenant->id]));
        ob_start();
        $console->run(['console', 'exams:starter-kit', 'school', '--author=teacher@school.test']);
        $this->assertStringContainsString('Everything in the starter kit is already here.', (string) ob_get_clean());
    }
}
