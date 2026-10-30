<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleClock;
use App\Modules\ReportCards\Grading;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0046: term report cards worked out from CBT exams, with remarks, positions and sharing. */
final class ReportCardsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private SampleClock $clock;
    private Tenant $tenant;
    private int $group;
    private int $tobi;
    /** @var array<string, int> */
    private array $banks = [];
    private int $midterm;
    private int $exam;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->clock->at(new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('UTC')));
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'head@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other@school.test', Role::Educator);
        $this->tobi = $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner)->id;
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'kemi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'zara@school.test', Role::Learner);

        $this->as('head@school.test');
        $made = $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold', 'level' => 'SS 2', 'period' => '2026']);
        $this->group = (int) substr((string) $made->header('Location'), strlen('/groups/'));
        $this->post(self::HOST, '/groups/' . $this->group . '/teachers', ['teachers' => [(string) $teacher->id]]);
        $this->post(self::HOST, '/groups/' . $this->group . '/learners', ['emails' => 'tobi@school.test ada@school.test kemi@school.test']);

        $this->as('teacher@school.test');
        foreach (['English', 'Physics', 'Chemistry'] as $subject) {
            $made = $this->post(self::HOST, '/teach/banks', ['name' => $subject, 'description' => '', 'practice' => '']);
            $this->banks[$subject] = (int) substr((string) $made->header('Location'), strlen('/teach/banks/'));
            $this->post(self::HOST, '/teach/banks/' . $this->banks[$subject] . '/import', ['questions' => "{$subject} one?\nA. yes\nB. no\nANSWER: A\n\n{$subject} two?\nA. yes\nB. no\nANSWER: B"]);
        }
        $this->midterm = $this->exam('Mid-term test', ['English', 'Physics'], 0);
        $this->exam = $this->exam('End of term exam', ['English', 'Physics', 'Chemistry'], 1);

        // Tobi: mid-term English 100, Physics 50; exam English 100, Physics 100.
        $this->sit('tobi@school.test', $this->midterm, [], ['English' => 2, 'Physics' => 1]);
        $this->sit('tobi@school.test', $this->exam, ['1'], ['English' => 2, 'Physics' => 2]);
        // Ada: mid-term English 50, Physics 100; exam English 50, chose Chemistry 100.
        $this->sit('ada@school.test', $this->midterm, [], ['English' => 1, 'Physics' => 2]);
        $this->sit('ada@school.test', $this->exam, ['2'], ['English' => 1, 'Chemistry' => 2]);
        // Kemi missed the mid-term; exam English 100, Physics 0.
        $this->sit('kemi@school.test', $this->exam, ['1'], ['English' => 2, 'Physics' => 0]);
        $this->as('teacher@school.test');
    }

    private function as(string $email, string $password = 'correct-horse-battery'): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, $password));
    }

    /** @param list<string> $subjects the first is compulsory, the rest are chosen from when $choose > 0 */
    private function exam(string $title, array $subjects, int $choose): int
    {
        $made = $this->post(self::HOST, '/teach/exams', [
            'title' => $title, 'section_bank' => array_map(fn (string $s): string => (string) $this->banks[$s], $subjects), 'section_count' => array_fill(0, count($subjects), '2'),
            'section_label' => $subjects, 'section_compulsory' => $choose > 0 ? ['0'] : array_map('strval', array_keys($subjects)),
            'choose_count' => (string) $choose, 'duration_minutes' => '30', 'audience' => 'groups', 'groups' => [(string) $this->group],
            'max_attempts' => '1', 'results' => 'hidden', 'review' => '0', 'shuffle_questions' => '1', 'shuffle_options' => '1',
        ]);
        $id = (int) substr((string) $made->header('Location'), strlen('/teach/exams/'));
        $this->post(self::HOST, '/teach/exams/' . $id . '/publish');
        $this->assertSame('published', $this->db()->fetchValue('SELECT status FROM cbt_exams WHERE id = ?', [$id]), $title);

        return $id;
    }

    /**
     * @param list<string> $chosen
     * @param array<string, int> $right how many questions to get right in each subject
     */
    private function sit(string $email, int $exam, array $chosen, array $right): void
    {
        $this->as($email);
        $started = $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => $chosen]);
        $attempt = (int) substr((string) $started->header('Location'), strlen('/exams/attempts/'));
        $this->assertGreaterThan(0, $attempt, $started->body);
        $paper = json_decode((string) $this->db()->fetchValue('SELECT paper FROM cbt_attempts WHERE id = ?', [$attempt]), true);
        $answers = [];
        foreach ($paper['sections'] as $s) {
            $want = $right[$s['label']] ?? 0;
            foreach ($s['questions'] as $i => $q) {
                $key = (int) $this->db()->fetchValue('SELECT correct FROM bank_questions WHERE id = ?', [$q['id']]);
                $answers[$q['id']] = array_search($i < $want ? $key : 1 - $key, $q['perm'], true);
            }
        }
        $service = $this->app->get(CbtExams::class);
        $userId = (int) $this->db()->fetchValue('SELECT user_id FROM cbt_attempts WHERE id = ?', [$attempt]);
        $service->saveAnswers($this->tenant, $attempt, $userId, 1, $answers, []);
        $service->submit($this->tenant, $attempt, 'candidate');
    }

    private function card(): int
    {
        $made = $this->post(self::HOST, '/groups/' . $this->group . '/report-cards', [
            'title' => 'First term 2026/2027', 'grading' => 'waec', 'next_term' => 'Next term begins on Monday 11 January 2027.',
            'exam' => [(string) $this->midterm, (string) $this->exam, '', ''], 'label' => ['Mid-term', '', '', ''], 'weight' => ['30', '70', '', ''],
        ]);
        $this->assertSame(1, preg_match('#/report-cards/(\d+)$#', (string) $made->header('Location'), $m), $made->body);

        return (int) ($m[1] ?? 0);
    }

    public function testTheBroadsheetWorksOutScoresAveragesAndPositions(): void
    {
        $base = '/groups/' . $this->group . '/report-cards';
        $bad = $this->post(self::HOST, $base, ['title' => 'T', 'grading' => 'waec', 'exam' => [(string) $this->exam], 'label' => [''], 'weight' => ['0']]);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Give each exam a weight from 1 to 100', $bad->body);
        $this->assertStringContainsString('title of 3 to 120 characters', $bad->body);
        $none = $this->post(self::HOST, $base, ['title' => 'Term', 'grading' => 'waec', 'exam' => ['', ''], 'label' => [], 'weight' => []]);
        $this->assertStringContainsString('Choose at least one exam', $none->body);

        $card = $this->card();
        $page = $this->get(self::HOST, $base . '/' . $card)->body;
        $this->assertStringContainsString('Mid-term 30%, End of term exam 70%', $page);
        $this->assertStringContainsString('92.5', $page, 'Tobi: English 100, Physics 85');
        $this->assertStringContainsString('83.3', $page, 'Ada: English 50, Physics 100, Chemistry 100');
        $this->assertStringContainsString('35.0', $page, 'Kemi: English 70 (absent from the mid-term), Physics 0');
        $this->assertStringContainsString('Absent: Mid-term', $page);

        $csv = $this->get(self::HOST, $base . '/' . $card . '/broadsheet.csv')->body;
        $this->assertStringContainsString('"English","English grade","English position","Physics","Physics grade","Physics position","Chemistry"', $csv);
        $this->assertStringContainsString('"tobi@school.test",100,"A1","1st",85,"A1","2nd",,,,"92.5","1st",""', $csv);
        $this->assertStringContainsString('"ada@school.test",50,"C6","3rd",100,"A1","1st",100,"A1","1st","83.3","2nd",""', $csv);
        $this->assertStringContainsString('"kemi@school.test",70,"B2","2nd",0,"F9","3rd",,,,"35.0","3rd","Mid-term"', $csv);

        $one = $this->get(self::HOST, $base . '/' . $card . '/learners/' . $this->tobi)->body;
        $this->assertStringContainsString('1st of 3', $one);
        $this->assertStringContainsString('Draft: not shared yet', $one);
        $this->assertStringContainsString('Next term begins on Monday 11 January 2027.', $one);
        $this->assertSame(3, substr_count($this->get(self::HOST, $base . '/' . $card . '/print')->body, 'class="receipt report-sheet report-card"'));

        $this->as('other@school.test');
        $this->assertSame(404, $this->get(self::HOST, $base . '/' . $card)->status, 'teachers of other classes');
    }

    public function testRemarksAreWrittenByTheRightPeople(): void
    {
        $card = $this->card();
        $base = '/groups/' . $this->group . '/report-cards/' . $card . '/learners/';
        $ada = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'ada@school.test'");
        $this->assertRedirectTo($base . $ada, $this->post(self::HOST, $base . $this->tobi, [
            'teacher_remark' => 'A strong term. Show your working in Physics.', 'head_remark' => 'Written by the teacher', 'next' => (string) $ada,
        ]));
        $row = $this->db()->fetchOne('SELECT teacher_remark, head_remark FROM report_card_remarks WHERE user_id = ?', [$this->tobi]);
        $this->assertSame(['teacher_remark' => 'A strong term. Show your working in Physics.', 'head_remark' => null], $row, 'only the head writes the head\'s remark');
        $this->assertSame(422, $this->post(self::HOST, $base . $this->tobi, ['teacher_remark' => str_repeat('x', 601)])->status);
        $zara = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'zara@school.test'");
        $this->assertSame(404, $this->get(self::HOST, $base . $zara)->status, 'not in the class');

        $this->as('head@school.test');
        $this->post(self::HOST, $base . $this->tobi, ['teacher_remark' => 'A strong term. Show your working in Physics.', 'head_remark' => 'Well done.']);
        $this->assertSame('Well done.', $this->db()->fetchValue('SELECT head_remark FROM report_card_remarks WHERE user_id = ?', [$this->tobi]));
    }

    public function testSharingShowsEachLearnerTheirOwnCardAndTellsParents(): void
    {
        // Mum may see results; Dad (for Ada) may not.
        $this->as('head@school.test');
        $this->post(self::HOST, '/people/' . $this->tobi . '/guardians', ['name' => 'Funke Bello', 'email' => 'mum@school.test', 'relationship' => 'mother', 'progress' => '1', 'results' => '1']);
        $page = $this->get(self::HOST, '/people/' . $this->tobi . '/guardians')->body;
        $this->assertSame(1, preg_match('#<span class="secret">([^<]+)</span>#', $page, $m));
        $password = html_entity_decode($m[1] ?? '');

        $this->as('teacher@school.test');
        $card = $this->card();
        $this->post(self::HOST, '/groups/' . $this->group . '/report-cards/' . $card . '/learners/' . $this->tobi, ['teacher_remark' => 'A strong term.']);
        $this->as('tobi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/report-cards/' . $card)->status, 'drafts are private');
        $this->assertStringContainsString('No report cards yet', $this->get(self::HOST, '/report-cards')->body);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/groups/' . $this->group . '/report-cards/' . $card . '/publish');
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'report_card.published' AND title = 'Report card ready: First term 2026/2027'"));
        $mumLink = '/children/' . $this->tobi . '/report-cards/' . $card;
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = 'mum@school.test' AND n.link = ?", [$mumLink]));

        $this->as('tobi@school.test');
        $this->assertStringContainsString('First term 2026/2027', $this->get(self::HOST, '/report-cards')->body);
        $mine = $this->get(self::HOST, '/report-cards/' . $card)->body;
        $this->assertStringContainsString('1st of 3', $mine);
        $this->assertStringContainsString('A strong term.', $mine);
        $this->assertStringNotContainsString('ada@school.test', $mine);
        $this->assertSame(1, substr_count($mine, 'class="receipt report-sheet report-card"'), 'only their own card');
        $this->as('zara@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/report-cards/' . $card)->status, 'not in the class');

        $this->as('mum@school.test', $password);
        $this->post(self::HOST, '/account/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $this->assertStringContainsString('/children/' . $this->tobi . '/report-cards/' . $card, $this->get(self::HOST, '/children/' . $this->tobi)->body);
        $this->assertStringContainsString('1st of 3', $this->get(self::HOST, '/children/' . $this->tobi . '/report-cards/' . $card)->body);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/groups/' . $this->group . '/report-cards/' . $card . '/unpublish');
        $this->as('tobi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/report-cards/' . $card)->status, 'taken back');
    }

    public function testGradesOrdinalsAndTiedPositions(): void
    {
        $this->assertSame(['A1', 'Excellent'], Grading::grade('waec', 75));
        $this->assertSame(['B2', 'Very good'], Grading::grade('waec', 74));
        $this->assertSame(['C6', 'Credit'], Grading::grade('waec', 50));
        $this->assertSame(['F9', 'Fail'], Grading::grade('waec', 39));
        $this->assertSame(['B', 'Very good'], Grading::grade('simple', 65));
        $this->assertSame(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '101st', '111th'], array_map(Grading::ordinal(...), [1, 2, 3, 4, 11, 12, 13, 21, 22, 101, 111]));
        $this->assertSame([7 => 1, 8 => 2, 9 => 2, 10 => 4], Grading::positions([10 => 70, 8 => 80, 7 => 90, 9 => 80]));
    }
}
