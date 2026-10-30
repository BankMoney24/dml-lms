<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View\WordSwap;
use App\Modules\Tenancy\Tenant;
use PHPUnit\Framework\TestCase;

/** Update 41 (ADR 0041): a school's own word for "learner" on its pages. */
final class WordSwapTest extends TestCase
{
    private static function swap(string $html, string $term = 'student'): string
    {
        return (new WordSwap(['learner' => Tenant::LEARNER_TERMS[$term]]))->page($html);
    }

    public function testCaseNumberAndPossessivesFollowTheOriginal(): void
    {
        $this->assertSame('<p>A student, two students, STUDENTS, Students’ work, the student\'s answer. Ready for students?</p>', self::swap(
            '<p>A learner, two learners, LEARNERS, Learners’ work, the learner\'s answer. Ready for learners?</p>',
        ));
        $this->assertSame('<h1>Pupils</h1>', self::swap('<h1>Learners</h1>', 'pupil'));
        $this->assertSame('<p>Trainee</p>', self::swap('<p>Learner</p>', 'trainee'));
        $this->assertSame('<p>Learnership and e-learning stay.</p>', self::swap('<p>Learnership and e-learning stay.</p>'));
    }

    public function testAddressesFileNamesAndCodeAreLeftAlone(): void
    {
        $html = '<a href="/school/reports/learners.csv?learner=3">learners.csv</a> <code>/api/v1/learners</code> <pre>learner</pre>'
            . '<script>var learner = 1;</script><style>.learner{}</style><textarea name="learner">learner</textarea>'
            . '<input name="learner" value="learner"><option value="learner">Learner</option>';
        $this->assertSame(
            '<a href="/school/reports/learners.csv?learner=3">learners.csv</a> <code>/api/v1/learners</code> <pre>learner</pre>'
            . '<script>var learner = 1;</script><style>.learner{}</style><textarea name="learner">learner</textarea>'
            . '<input name="learner" value="learner"><option value="learner">Student</option>',
            self::swap($html),
        );
    }

    public function testWhatPeopleReadInAttributesIsSwapped(): void
    {
        $this->assertSame(
            '<input type="search" placeholder="Find a student" aria-label="Students"><td data-label="Student">'
            . '<img alt="A student at work" src="/learner.png"><input type="submit" value="Add students">'
            . '<meta name="description" content="For students"><meta property="og:url" content="https://x.test/learners">',
            self::swap(
                '<input type="search" placeholder="Find a learner" aria-label="Learners"><td data-label="Learner">'
                . '<img alt="A learner at work" src="/learner.png"><input type="submit" value="Add learners">'
                . '<meta name="description" content="For learners"><meta property="og:url" content="https://x.test/learners">',
            ),
        );
    }

    public function testWhatPeopleWroteKeepsItsWords(): void
    {
        $html = '<div class="lesson prose"><p>Dear learners</p><div><p>learner</p></div></div><p>After the learner</p>'
            . '<p data-own-words>My learners</p><dd data-own-words="">Learners</dd><p>Last learner</p>';
        $this->assertSame(
            '<div class="lesson prose"><p>Dear learners</p><div><p>learner</p></div></div><p>After the student</p>'
            . '<p data-own-words>My learners</p><dd data-own-words="">Learners</dd><p>Last student</p>',
            self::swap($html),
        );
        // A void element marked as own words does not swallow the rest of the page.
        $this->assertSame('<br data-own-words><p>student</p>', self::swap('<br data-own-words><p>learner</p>'));
        $this->assertSame('<!-- learner --><p>student</p>', self::swap('<!-- learner --><p>learner</p>'));
    }

    public function testNothingChangesForTheDefaultWord(): void
    {
        $this->assertTrue((new WordSwap(['learner' => Tenant::LEARNER_TERMS['learner']]))->isEmpty());
        $this->assertSame('<p>Learners</p>', self::swap('<p>Learners</p>', 'learner'));
        $tenant = Tenant::fromRow(['id' => 1, 'slug' => 'a', 'name' => 'A', 'status' => 'active', 'timezone' => 'UTC', 'locale' => 'en', 'learner_term' => 'castle']);
        $this->assertSame('learner', $tenant->learnerTerm, 'unknown values are ignored');
        $row = ['id' => 1, 'slug' => 'a', 'name' => 'A', 'status' => 'active', 'timezone' => 'UTC', 'locale' => 'en', 'learner_term' => 'pupil'];
        $this->assertSame('Pupils', Tenant::fromRow($row)->learnerWord(true, true));
    }
}
