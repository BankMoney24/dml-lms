<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\VirtualLabs\Labs\DnsLab;
use App\Modules\VirtualLabs\Labs\FirewallLab;
use App\Modules\VirtualLabs\Labs\NetworkBuildLab;
use App\Modules\VirtualLabs\Labs\NetworkRepairLab;
use App\Modules\VirtualLabs\Network\Scenario;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;
use PHPUnit\Framework\TestCase;

/** Update 35 (ADR 0035): the network builder's model and marking, the firewall and DNS labs. */
final class NetworkLabsTest extends TestCase
{
    /**
     * A correct network for a scenario, built only from what the browser is given.
     *
     * @param array<string, mixed> $pub
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public static function workingNetwork(array $pub, int $pcs): array
    {
        $a = substr((string) $pub['lanA'], 0, (int) strrpos((string) $pub['lanA'], '.'));
        $b = substr((string) $pub['lanB'], 0, (int) strrpos((string) $pub['lanB'], '.'));
        $link = Topology::ip(explode('/', (string) $pub['link'])[0]) ?? 0;
        $m24 = '255.255.255.0';
        $m30 = '255.255.255.252';
        $d = [];
        $l = [];
        for ($i = 1; $i <= $pcs; $i++) {
            $d[] = ['i' => 'p' . $i, 't' => 'pc', 'n' => 'PC' . $i, 'x' => 100, 'y' => 100 * $i, 'ip' => $a . '.' . (20 + $i), 'm' => $m24, 'g' => $a . '.1'];
            $l[] = ['p' . $i, 'Fa0', 's1', 'Fa0/' . $i];
        }
        $d[] = ['i' => 's1', 't' => 'switch', 'n' => 'SW1', 'x' => 300, 'y' => 200];
        $d[] = ['i' => 'r1', 't' => 'router', 'n' => 'R1', 'x' => 500, 'y' => 200,
            'if' => ['Gi0/0' => ['ip' => $a . '.1', 'm' => $m24, 'u' => 1], 'Gi0/1' => ['ip' => long2ip($link + 1), 'm' => $m30, 'u' => 1]],
            'rt' => [[$b . '.0', $m24, long2ip($link + 2)]]];
        $d[] = ['i' => 'r2', 't' => 'router', 'n' => 'R2', 'x' => 700, 'y' => 200,
            'if' => ['Gi0/0' => ['ip' => long2ip($link + 2), 'm' => $m30, 'u' => 1], 'Gi0/1' => ['ip' => $b . '.1', 'm' => $m24, 'u' => 1]],
            'rt' => [[$a . '.0', $m24, long2ip($link + 1)]]];
        $d[] = ['i' => 'v1', 't' => 'server', 'n' => 'SRV1', 'x' => 900, 'y' => 200, 'ip' => (string) $pub['server'], 'm' => $m24, 'g' => $b . '.1'];
        $l[] = ['s1', 'Gi0/1', 'r1', 'Gi0/0'];
        $l[] = ['r1', 'Gi0/1', 'r2', 'Gi0/0'];
        $l[] = ['r2', 'Gi0/1', 'v1', 'Fa0'];

        return ['d' => $d, 'l' => $l];
    }

    /**
     * Least-privilege rules for the firewall lab, read from its policy text.
     *
     * @param array<string, mixed> $pub
     * @return list<list<string>>
     */
    public static function goodRules(array $pub): array
    {
        preg_match('/network (\S+) may log in with SSH \(TCP port (\d+)\)/', (string) $pub['policy'][1], $ssh);
        preg_match('/server (\S+) may reach .* \(TCP port (\d+)\)/', (string) $pub['policy'][2], $db);

        return [['allow', 'tcp', 'any', '443'], ['allow', 'tcp', $ssh[1] ?? '', $ssh[2] ?? ''], ['allow', 'tcp', ($db[1] ?? '') . '/32', $db[2] ?? '']];
    }

    /** @return array<string, mixed> */
    private function pub(string $seed): array
    {
        return (new NetworkBuildLab())->params(new Seed($seed))['public'];
    }

    /** @param array<string, mixed> $net */
    private function sim(array $net): Simulator
    {
        return new Simulator(Topology::fromArray($net));
    }

    public function testAWorkingNetworkPingsBothWaysAndEachSingleFaultBreaksIt(): void
    {
        $pub = $this->pub('net-1');
        $server = Topology::ip((string) $pub['server']) ?? 0;
        $net = self::workingNetwork($pub, 2);
        $this->assertTrue($this->sim($net)->ping('p1', $server)['ok']);
        $this->assertTrue($this->sim($net)->ping('v1', Topology::ip((string) $net['d'][0]['ip']) ?? 0)['ok'], 'the server reaches the PC too');

        $broken = $net;
        $broken['d'][3]['if']['Gi0/1']['u'] = 0; // R1's link shut down
        $this->assertStringContainsString('next hop', $this->sim($broken)->ping('p1', $server)['why']);

        $broken = $net;
        $broken['d'][4]['rt'] = []; // R2 has no way back
        $why = $this->sim($broken)->ping('p1', $server)['why'];
        $this->assertStringContainsString('reply cannot get back', $why);
        $this->assertStringContainsString('R2 has no route', $why);

        $broken = $net;
        $broken['d'][0]['g'] = substr((string) $pub['lanA'], 0, -4) . '254';
        $this->assertStringContainsString('default gateway', $this->sim($broken)->ping('p1', $server)['why']);

        $broken = $net;
        array_shift($broken['l']); // PC1 unplugged
        $this->assertStringContainsString('no cable', $this->sim($broken)->ping('p1', $server)['why']);
        $this->assertTrue($this->sim($broken)->ping('p2', $server)['ok'], 'PC2 is unaffected');

        // A loop: R1 sends LAN B back to... R1 via R2, and R2 sends it back to R1.
        $broken = $net;
        $broken['d'][4]['if']['Gi0/1']['u'] = 0;
        $broken['d'][4]['rt'][] = [substr((string) $pub['lanB'], 0, -3), '255.255.255.0', $net['d'][3]['if']['Gi0/1']['ip']];
        $this->assertStringContainsString('loop', $this->sim($broken)->ping('p1', $server)['why']);
    }

    public function testTheTopologyReaderRejectsWhatItCannotTrust(): void
    {
        $t = Topology::fromArray(['d' => [
            ['i' => 'a', 't' => 'pc', 'n' => '<script>', 'ip' => '300.1.1.1', 'm' => '255.0.255.0', 'g' => '10.0.0.1'],
            ['i' => 'b', 't' => 'pc', 'n' => 'PC2', 'ip' => '10.0.0.5', 'm' => '/24'],
            ['i' => 'a', 't' => 'router'],
            ['i' => 'c', 't' => 'toaster'],
        ], 'l' => [['a', 'Fa0', 'b', 'Fa0'], ['a', 'Fa0', 'b', 'Fa0'], ['a', 'Fa9', 'b', 'Fa0']]]);
        $this->assertCount(2, $t->devices, 'duplicate ids and unknown kinds are dropped');
        $this->assertSame('A', $t->devices['a']['name'], 'names are letters, numbers, - and _');
        $this->assertNull($t->devices['a']['ip'], 'octets over 255 are refused');
        $this->assertNull($t->devices['a']['mask'], 'non-contiguous masks are refused');
        $this->assertSame(Topology::prefixMask(24), $t->devices['b']['mask']);
        $this->assertCount(1, $t->links, 'one cable per port');
        $this->assertSame([], Topology::fromJson(str_repeat('x', 50000))->devices);
        $this->assertSame(24, Topology::prefixOf(Topology::prefixMask(24)));
        $this->assertTrue(Topology::isHostAddress(Topology::ip('10.0.0.1') ?? 0, Topology::prefixMask(30)));
        $this->assertFalse(Topology::isHostAddress(Topology::ip('10.0.0.3') ?? 0, Topology::prefixMask(30)));
    }

    public function testBuildMarkingExplainsWhatIsMissing(): void
    {
        $lab = new NetworkBuildLab();
        $params = $lab->params(new Seed('build-1'));
        $net = self::workingNetwork($params['public'], 2);
        $this->assertSame(100, $lab->check($params, ['topology' => json_encode($net, JSON_THROW_ON_ERROR)])->score);

        $net['d'][3]['rt'] = [];
        $net['d'][4]['if']['Gi0/1']['u'] = 0;
        $result = $lab->check($params, ['topology' => json_encode($net, JSON_THROW_ON_ERROR)]);
        $this->assertLessThan(70, $result->score);
        $byLabel = array_column($result->feedback, null, 'label');
        $this->assertFalse($byLabel['Interfaces up']['ok']);
        $this->assertStringContainsString('R2 towards the server', $byLabel['Interfaces up']['detail']);
        $this->assertFalse($byLabel['Routes']['ok']);
        $this->assertTrue($byLabel['Cables']['ok']);
        $this->assertArrayHasKey('topology', $result->evidence);
    }

    public function testRepairSessionsPlantThreeFaultsThatReallyBreakTheNetwork(): void
    {
        $lab = new NetworkRepairLab();
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $params = $lab->params(new Seed('repair-' . $i));
            $faults = $params['secret']['faults'];
            $this->assertCount(3, $faults);
            $seen += $faults;
            $broken = $lab->check($params, ['topology' => json_encode($params['public']['start'], JSON_THROW_ON_ERROR)]);
            $this->assertSame(0, $broken->score, 'nothing is fixed at the start: ' . json_encode($faults, JSON_THROW_ON_ERROR));
            $fixed = $lab->check($params, ['topology' => json_encode(self::workingNetwork($params['public'], 3), JSON_THROW_ON_ERROR)]);
            $this->assertSame(100, $fixed->score);
        }
        $this->assertCount(count(NetworkRepairLab::FAULTS), $seen, 'every kind of fault is used');
    }

    public function testFirewallRulesAreFirstMatchAndBlockingAloneEarnsNothing(): void
    {
        $lab = new FirewallLab();
        $params = $lab->params(new Seed('fw-1'));
        $good = self::goodRules($params['public']);
        $this->assertSame(100, $lab->check($params, ['rules' => json_encode($good, JSON_THROW_ON_ERROR)])->score);
        $this->assertSame(0, $lab->check($params, ['rules' => '[]'])->score, 'blocking everything serves nobody');
        $this->assertSame(50, $lab->check($params, ['rules' => json_encode([['allow', 'any', 'any', 'any']], JSON_THROW_ON_ERROR)])->score);
        $shadowed = array_merge([['allow', 'tcp', 'any', 'any']], $good);
        $this->assertLessThan(70, $lab->check($params, ['rules' => json_encode($shadowed, JSON_THROW_ON_ERROR)])->score, 'a broad rule on top lets everything through');
        $this->assertSame([], FirewallLab::rules(json_encode([['allow', 'tcp', '10.0.0.1/40', '22'], ['allow', 'icmp', 'any', 'any'], ['maybe', 'tcp', 'any', '1']], JSON_THROW_ON_ERROR)));
        $this->assertTrue(FirewallLab::inCidr('192.168.4.200', '192.168.4.0/24'));
        $this->assertFalse(FirewallLab::inCidr('192.168.5.1', '192.168.4.0/24'));
    }

    public function testEveryDnsReferralLeadsSomewhereRealInTheLab(): void
    {
        $lab = new DnsLab();
        for ($i = 0; $i < 30; $i++) {
            $p = $lab->params(new Seed('dns-' . $i));
            $servers = $p['public']['servers'];
            $this->assertCount(8, $servers, 'no two servers share an address');
            foreach ($servers as $ip => $s) {
                foreach ($s['records'] as $r) {
                    if ($r[1] === 'A' && str_starts_with((string) $r[0], 'ns')) {
                        $this->assertArrayHasKey($r[2], $servers, 'glue for ' . $r[0] . ' points at a server in the lab');
                    }
                }
            }
            $this->assertStringEndsWith('.test', (string) $p['public']['name']);
            $this->assertStringStartsWith('203.0.113.', (string) $p['secret']['answer'], 'documentation addresses only');
        }
    }

    public function testScenariosNeverOverlap(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $s = Scenario::from(new Seed('sc-' . $i));
            $this->assertNotSame($s->lanA >> 8, $s->lanB >> 8);
            $this->assertSame(0, $s->link % 4, 'the /30 starts on a boundary');
            $this->assertTrue(Topology::isHostAddress($s->ip['server'], Topology::prefixMask(24)));
        }
    }
}
