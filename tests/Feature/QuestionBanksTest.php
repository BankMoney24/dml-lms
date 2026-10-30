<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0042: question banks — writing, importing, passages, the sample banks and who may see them. */
final class QuestionBanksTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'teacher@other.test', Role::Educator);
    }

    private function as(string $email, string $host = self::HOST): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login($host, $email, 'correct-horse-battery'));
    }

    private function bank(string $name = 'SS 2 Physics'): int
    {
        $made = $this->post(self::HOST, '/teach/banks', ['name' => $name, 'description' => 'Second term', 'practice' => '1']);
        $this->assertSame(1, preg_match('#^/teach/banks/(\d+)$#', (string) $made->header('Location'), $m), $made->body);

        return (int) ($m[1] ?? 0);
    }

    public function testTeachersWriteQuestionsAndLearnersCannotSeeTheBanks(): void
    {
        $this->as('teacher@school.test');
        $bank = $this->bank();
        $this->assertSame(422, $this->post(self::HOST, '/teach/banks', ['name' => 'SS 2 Physics'])->status, 'bank names are unique');

        $bad = $this->post(self::HOST, '/teach/banks/' . $bank . '/questions', ['prompt' => 'Speed is', 'options' => ['distance ÷ time', ''], 'correct' => 'C']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Give at least two options', $bad->body);

        $this->assertRedirectTo('/teach/banks/' . $bank . '/questions/new', $this->post(self::HOST, '/teach/banks/' . $bank . '/questions', [
            'prompt' => 'Which of these is a **vector** quantity?', 'options' => ['mass', 'speed', 'velocity', 'time', ''], 'correct' => 'C',
            'explanation' => 'Velocity has a direction.', 'topic' => 'Motion', 'difficulty' => '1', 'another' => '1',
        ]));
        $row = $this->db()->fetchOne('SELECT * FROM bank_questions WHERE bank_id = ?', [$bank]);
        $this->assertNotNull($row);
        $this->assertSame(['mass', 'speed', 'velocity', 'time'], json_decode((string) $row['options'], true), 'empty options are dropped');
        $this->assertSame(2, (int) $row['correct']);

        $page = $this->get(self::HOST, '/teach/banks/' . $bank);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Motion', $page->body);

        $this->as('amaka@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/teach/banks')->status);
        $this->assertSame(403, $this->get(self::HOST, '/teach/banks/' . $bank)->status);

        $this->as('teacher@other.test', 'other.test');
        $this->assertSame(404, $this->get('other.test', '/teach/banks/' . $bank)->status, 'another school cannot open the bank');
    }

    public function testImportIsAllOrNothingAndExportRoundTrips(): void
    {
        $this->as('teacher@school.test');
        $bank = $this->bank();
        $broken = $this->post(self::HOST, '/teach/banks/' . $bank . '/import', ['questions' => "Good one?\nA. yes\nB. no\nANSWER: A\n\nBad one?\nA. yes\nB. no\nANSWER: F"]);
        $this->assertSame(422, $broken->status);
        $this->assertStringContainsString('Line 9', $broken->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM bank_questions WHERE bank_id = ?', [$bank]), 'nothing is half imported');

        $this->assertRedirectTo('/teach/banks/' . $bank, $this->post(self::HOST, '/teach/banks/' . $bank . '/import', [
            'questions' => "Good one?\nA. yes\nB. no\nANSWER: A\n\nSecond?\nA. up\nB. down\nC. across\nANSWER: C\nTOPIC: Directions",
        ]));
        $csv = $this->upload(self::HOST, '/teach/banks/' . $bank . '/import', [], ['file' => [$this->fakeUpload('more.csv', "question,a,b,answer\n\"=cmd, one\",x,y,B\n")]]);
        $this->assertRedirectTo('/teach/banks/' . $bank, $csv);
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM bank_questions WHERE bank_id = ?', [$bank]));

        $export = $this->get(self::HOST, '/teach/banks/' . $bank . '/export.csv');
        $this->assertSame(200, $export->status);
        $this->assertStringContainsString("'=cmd, one", $export->body, 'formula-like cells are made safe for spreadsheets');
        $this->assertStringContainsString('Directions', $export->body);
    }

    public function testPassagesSampleBanksAndRetiring(): void
    {
        $this->as('teacher@school.test');
        $this->assertRedirectTo('/teach/banks', $this->post(self::HOST, '/teach/banks/samples'));
        $this->assertRedirectTo('/teach/banks', $this->post(self::HOST, '/teach/banks/samples'), 'adding again changes nothing');
        $banks = $this->db()->fetchAll('SELECT id, name, practice FROM question_banks WHERE tenant_id = ? ORDER BY id', [$this->tenant->id]);
        $this->assertCount(count(\App\Modules\Exams\Bank\SampleBanks::all()), $banks);
        $this->assertSame(1, (int) $banks[0]['practice']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM bank_passages WHERE tenant_id = ?', [$this->tenant->id]));
        $list = $this->get(self::HOST, '/teach/banks');
        $this->assertStringContainsString('not past', $list->body, 'the sample banks say they are not past exam questions');
        $this->assertStringContainsString('Everything in the kit is in your lists.', $list->body);
        $this->assertStringNotContainsString('Add the starter kit', $list->body);

        $bank = $this->bank('Comprehension');
        $this->assertSame(303, $this->post(self::HOST, '/teach/banks/' . $bank . '/passages', ['title' => 'The market', 'body' => 'Mama Nkechi opens her stall at six.'])->status);
        $passage = (int) $this->db()->fetchValue('SELECT id FROM bank_passages WHERE bank_id = ?', [$bank]);
        $this->post(self::HOST, '/teach/banks/' . $bank . '/questions', ['prompt' => 'When does the stall open?', 'options' => ['six', 'nine'], 'correct' => 'A', 'passage_id' => (string) $passage]);
        $q = (int) $this->db()->fetchValue('SELECT id FROM bank_questions WHERE bank_id = ?', [$bank]);
        $this->assertSame($passage, (int) $this->db()->fetchValue('SELECT passage_id FROM bank_questions WHERE id = ?', [$q]));

        $this->assertRedirectTo('/teach/banks/' . $bank, $this->post(self::HOST, '/teach/banks/' . $bank . '/questions/' . $q . '/retire'));
        $this->assertNotNull($this->db()->fetchValue('SELECT retired_at FROM bank_questions WHERE id = ?', [$q]));
    }
}
