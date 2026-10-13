<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\LabGateway;
use App\Modules\VirtualLabs\Labs\JsGradeLab;
use App\Modules\VirtualLabs\Labs\PhishingLab;
use App\Modules\VirtualLabs\Seed;
use PHPUnit\Framework\TestCase;

final class VirtualLabsTest extends TestCase
{
    /**
     * Correct answers for any session of any built-in lab, worked out the way
     * a careful learner would (from the public numbers where possible).
     *
     * @param array{public: array<string, mixed>, secret: array<string, mixed>} $p
     * @return array<string, string>
     */
    public static function correctAnswers(string $key, array $p): array
    {
        $pub = $p['public'];
        $sec = $p['secret'];

        return match ($key) {
            'ohms-law' => [
                'resistance' => (string) $pub['rx'],
                'current' => (string) round($pub['vq'] / $pub['rx'] * 1000, 2),
                'readings' => json_encode([[1.5, 10.0], [3.0, 20.0], [4.5, 30.0]], JSON_THROW_ON_ERROR),
            ],
            'titration' => [
                'titre' => (string) round($pub['acid'] * $pub['acid_volume'] / $pub['base'], 2),
                'concentration' => (string) $pub['acid'],
                'titres' => json_encode([round($pub['acid'] * 250, 2) + 0.05, round($pub['acid'] * 250, 2)], JSON_THROW_ON_ERROR),
            ],
            'straight-line' => [
                'gradient' => (string) $sec['m'],
                'intercept' => (string) $sec['c'],
                'y' => (string) ($sec['m'] * $pub['xq'] + $sec['c']),
            ],
            'subnetting' => [
                'network' => (string) $sec['network'],
                'broadcast' => (string) $sec['broadcast'],
                'hosts' => (string) $sec['hosts'],
                'same' => $sec['same'] ? 'yes' : 'no',
            ],
            'js-grades' => ['code' => 'function grade(s){}', 'outputs' => json_encode($sec['expected'], JSON_THROW_ON_ERROR)],
            'phishing' => self::phishingAnswers((array) $sec['ids']),
            default => [],
        };
    }

    /**
     * @param array<mixed> $ids
     * @return array<string, string>
     */
    private static function phishingAnswers(array $ids): array
    {
        $answers = [];
        foreach (array_values($ids) as $i => $id) {
            $answers['email_' . ($i + 1)] = PhishingLab::answerFor((string) $id);
        }

        return $answers;
    }

    /** @return iterable<string, array{Lab}> */
    public static function labs(): iterable
    {
        foreach (LabCatalogue::builtIn()->definitions() as $d) {
            yield $d->key => [LabCatalogue::builtIn()->find($d->key) ?? throw new \LogicException()];
        }
    }

    public function testSeedsAreRepeatableAndDiffer(): void
    {
        $a = new Seed('abc');
        $b = new Seed('abc');
        $c = new Seed('abd');
        $seqA = [$a->int(0, 1000), $a->int(0, 1000), $a->int(0, 1000)];
        $this->assertSame($seqA, [$b->int(0, 1000), $b->int(0, 1000), $b->int(0, 1000)]);
        $this->assertNotSame($seqA, [$c->int(0, 1000), $c->int(0, 1000), $c->int(0, 1000)]);
        $this->assertSame(5, (new Seed('x'))->int(5, 5));
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], (new Seed('s'))->shuffle([1, 2, 3, 4]));
    }

    /** @dataProvider labs */
    public function testCorrectAnswersPassAndBlankAnswersFail(Lab $lab): void
    {
        $key = $lab->definition()->key;
        for ($i = 0; $i < 25; $i++) {
            $params = $lab->params(new Seed('seed-' . $i));
            $this->assertSame($params, $lab->params(new Seed('seed-' . $i)), 'the same seed gives the same numbers');
            $right = $lab->check($params, self::correctAnswers($key, $params));
            $this->assertSame(100, $right->score, $key . ' seed ' . $i . ': ' . json_encode($right->feedback));
            $blank = $lab->check($params, []);
            $this->assertLessThan($lab->definition()->passMark, $blank->score, $key);
            $this->assertNotSame([], $lab->fields($params['public']));
        }
    }

    /** @dataProvider labs */
    public function testTheBrowserNeverGetsTheAnswerKey(Lab $lab): void
    {
        $params = $lab->params(new Seed('peek'));
        $public = json_encode($params['public'], JSON_THROW_ON_ERROR);
        $this->assertSame([], array_intersect_key($params['secret'], $params['public']), 'secret values are never copied into what the browser gets');
        foreach ($params['secret'] as $name => $value) {
            if ($name === 'expected' || $name === 'ids') {
                $this->assertStringNotContainsString(json_encode($value, JSON_THROW_ON_ERROR), $public, $name);
            }
        }
        if ($lab instanceof PhishingLab) {
            $this->assertStringNotContainsString('phishing', strtolower($public), 'no hint which email is phishing');
        }
    }

    public function testSubnetNumbersAreConsistent(): void
    {
        $lab = LabCatalogue::builtIn()->find('subnetting') ?? throw new \LogicException();
        for ($i = 0; $i < 200; $i++) {
            $p = $lab->params(new Seed('net' . $i));
            $prefix = (int) $p['public']['prefix'];
            $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $ip = (int) ip2long((string) $p['public']['ip']);
            $other = (int) ip2long((string) $p['public']['other']);
            $this->assertSame(long2ip($ip & $mask), $p['secret']['network']);
            $this->assertSame(long2ip(($ip & $mask) | (~$mask & 0xFFFFFFFF)), $p['secret']['broadcast']);
            $this->assertSame((1 << (32 - $prefix)) - 2, $p['secret']['hosts']);
            $this->assertSame(($other & $mask) === ($ip & $mask), $p['secret']['same']);
            $this->assertNotSame($ip, $other);
            $this->assertNotContains($ip, [$ip & $mask, ($ip & $mask) | (~$mask & 0xFFFFFFFF)], 'a host, not the network or broadcast');
        }
    }

    public function testStraightLinePointsLieOnTheLine(): void
    {
        $lab = LabCatalogue::builtIn()->find('straight-line') ?? throw new \LogicException();
        for ($i = 0; $i < 100; $i++) {
            $p = $lab->params(new Seed('line' . $i));
            $this->assertGreaterThanOrEqual(3, count($p['public']['points']));
            foreach ($p['public']['points'] as [$x, $y]) {
                $this->assertEqualsWithDelta($p['secret']['m'] * $x + $p['secret']['c'], $y, 1e-9);
                $this->assertLessThanOrEqual(9, abs($y));
            }
            $this->assertNotContains($p['public']['xq'], array_column($p['public']['points'], 0));
        }
    }

    public function testCodeLabMarksOutputsAgainstItsOwnAnswers(): void
    {
        $lab = new JsGradeLab();
        $p = $lab->params(new Seed('code'));
        $this->assertCount(12, $p['public']['tests']);
        $outputs = $p['secret']['expected'];
        $outputs[0] = $outputs[0] === 'A' ? 'B' : 'A';
        $result = $lab->check($p, ['code' => str_repeat('x', 9000), 'outputs' => json_encode($outputs, JSON_THROW_ON_ERROR)]);
        $this->assertSame(91, $result->score);
        $this->assertSame(JsGradeLab::MAX_CODE, mb_strlen((string) $result->evidence['code']), 'code kept is capped');
        $this->assertSame(0, $lab->check($p, ['outputs' => 'not json'])->score);
        $this->assertSame('E', JsGradeLab::reference(44));
        $this->assertSame('D', JsGradeLab::reference(45));
        $this->assertSame('B', JsGradeLab::reference(69));
    }

    public function testAnswersAreReadForgivingly(): void
    {
        $this->assertSame(0.25, Answers::number('0,25'));
        $this->assertSame(12.5, Answers::number(' 12.5 Ω'));
        $this->assertSame(1200.0, Answers::number('1 200'));
        $this->assertSame(-3.0, Answers::number('-3'));
        $this->assertNull(Answers::number('twelve'));
        $this->assertNull(Answers::number(''));
        $this->assertTrue(Answers::close(102.0, 100.0, 3));
        $this->assertFalse(Answers::close(104.0, 100.0, 3));
        $this->assertSame('0.083', Answers::show(0.0830, 4));
    }

    public function testEvidenceIsKeptSmall(): void
    {
        $big = ['answer' => '42', 'readings' => array_fill(0, 5000, [1.234567, 2.345678])];
        $capped = LabGateway::capped($big);
        $this->assertSame('42', $capped['answer']);
        $this->assertArrayNotHasKey('readings', $capped);
        $this->assertLessThanOrEqual(LabGateway::MAX_EVIDENCE_BYTES, strlen((string) json_encode($capped)));
        $this->assertSame(['a' => 1], LabGateway::capped(['a' => 1]));
    }
}
