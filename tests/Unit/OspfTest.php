<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\Labs\NetworkOspfLab;
use App\Modules\VirtualLabs\Network\OspfScenario;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;
use PHPUnit\Framework\TestCase;

/** Update 41 (ADR 0041): OSPF and default routes in the network builder, and the OSPF lab. */
final class OspfTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function pub(string $seed = 'ospf-1'): array
    {
        return (new NetworkOspfLab())->params(new Seed($seed))['public'];
    }

    /**
     * The finished network, built only from what the browser is given (the plan's LANs and addresses).
     *
     * @param array<string, mixed> $pub
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public static function working(array $pub): array
    {
        $net = $pub['start'];
        foreach ($net['d'] as $i => $d) {
            if ($d['t'] !== 'router') {
                continue;
            }
            $n = [];
            foreach ($d['if'] as $port) {
                $n[] = [long2ip((Topology::ip($port['ip']) ?? 0) & (Topology::mask($port['m']) ?? 0)), long2ip(~(Topology::mask($port['m']) ?? 0) & 0xFFFFFFFF), 0];
            }
            $net['d'][$i]['os'] = ['n' => $n, 'pi' => ['Gi0/0']];
        }

        return $net;
    }

    /**
     * @param array{d: list<array<string, mixed>>, l: list<list<string>>} $net
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    private static function with(array $net, string $name, callable $change): array
    {
        foreach ($net['d'] as $i => $d) {
            if ($d['n'] === $name) {
                $net['d'][$i] = $change($d);
            }
        }

        return $net;
    }

    /**
     * @param array<string, mixed> $pub
     * @param array{d: list<array<string, mixed>>, l: list<list<string>>} $net
     * @return array{score: int, text: string}
     */
    private static function mark(array $pub, array $net): array
    {
        $r = (new NetworkOspfLab())->check(['public' => $pub, 'secret' => []], ['topology' => json_encode($net, JSON_THROW_ON_ERROR)]);

        return ['score' => $r->score, 'text' => (string) json_encode($r->feedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    public function testTheLabIsInTheCatalogue(): void
    {
        $this->assertNotNull(LabCatalogue::builtIn()->find('network-ospf'));
    }

    public function testTheFinishedNetworkLearnsEverythingAndSurvivesAnyOneLink(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $s) {
            $pub = self::pub('ospf-' . $s);
            $net = self::working($pub);
            $topo = Topology::fromArray($net);
            $sim = new Simulator($topo);
            foreach (['r1', 'r2', 'r3'] as $r) {
                $this->assertCount(2, $sim->ospfNeighbours($r), $r . ' has two neighbours');
                $this->assertCount(3, $sim->ospfRoutes($r), $r . ' learns the other two LANs and the core link it is not on');
            }
            $server = Topology::ip((string) $pub['server']) ?? 0;
            $this->assertTrue($sim->ping('p1', $server)['ok'], 'seed ' . $s);
            $this->assertSame(100, self::mark($pub, $net)['score'], 'seed ' . $s);

            // R1 reaches R2's LAN directly (cost 2), not round by R3 (cost 3).
            $lan2 = Topology::ip(explode('/', (string) $pub['lans'][1])[0]) ?? 0;
            $toLan2 = array_values(array_filter($sim->ospfRoutes('r1'), static fn (array $r): bool => $r['net'] === $lan2));
            $this->assertSame(2, $toLan2[0]['metric']);
            $this->assertSame('Gi0/1', $toLan2[0]['port']);

            // Break R1–R2: the route now goes through R3, and the ping still works.
            $without = new Simulator($topo->withoutLink(6));
            $again = array_values(array_filter($without->ospfRoutes('r1'), static fn (array $r): bool => $r['net'] === $lan2));
            $this->assertSame(3, $again[0]['metric']);
            $this->assertSame('Gi0/2', $again[0]['port']);
            $this->assertTrue($without->ping('p1', (Topology::ip((string) $pub['start']['d'][5]['ip']) ?? 0))['ok']);
        }
        $this->assertSame(100, self::mark(self::pub(), OspfScenario::from(new Seed('ospf-1'))->working())['score'], 'one /28 statement covering all core links works too');
    }

    public function testTheStartScoresNothingAndStaticRoutesAreNotEnough(): void
    {
        $pub = self::pub();
        $this->assertSame(0, self::mark($pub, $pub['start'])['score']);

        // Static routes on every router: pings work, but nothing is learned and a broken link stays broken.
        $a = [];
        foreach ($pub['start']['d'] as $d) {
            if ($d['t'] === 'router') {
                $a[$d['n']] = $d['if'];
            }
        }
        $lan = static fn (int $k): string => explode('/', (string) $pub['lans'][$k])[0];
        $net = $pub['start'];
        $net = self::with($net, 'R1', static fn (array $d): array => ['rt' => [[$lan(1), '255.255.255.0', $a['R2']['Gi0/1']['ip']], [$lan(2), '255.255.255.0', $a['R3']['Gi0/1']['ip']]]] + $d);
        $net = self::with($net, 'R2', static fn (array $d): array => ['rt' => [[$lan(0), '255.255.255.0', $a['R1']['Gi0/1']['ip']], [$lan(2), '255.255.255.0', $a['R3']['Gi0/2']['ip']]]] + $d);
        $net = self::with($net, 'R3', static fn (array $d): array => ['rt' => [[$lan(0), '255.255.255.0', $a['R1']['Gi0/2']['ip']], [$lan(1), '255.255.255.0', $a['R2']['Gi0/2']['ip']]]] + $d);
        $sim = new Simulator(Topology::fromArray($net));
        $this->assertTrue($sim->ping('p1', Topology::ip((string) $pub['server']) ?? 0)['ok']);
        $m = self::mark($pub, $net);
        $this->assertSame(0, $m['score']);
        $this->assertStringContainsString('R1 does not run OSPF', $m['text']);

        // OSPF done, but static routes left behind: the "learned, not typed" part fails.
        $both = self::with(self::working($pub), 'R2', static fn (array $d): array => ['rt' => [[$lan(0), '255.255.255.0', $a['R1']['Gi0/1']['ip']]]] + $d);
        $m = self::mark($pub, $both);
        $this->assertSame(55, $m['score']);
        $this->assertStringContainsString('R2 still has static routes', $m['text']);
    }

    public function testEachMistakeIsFoundAndExplained(): void
    {
        $pub = self::pub();
        $good = self::working($pub);

        // A core port made passive: that link has no neighbours, though traffic still flows the other way round.
        $passive = self::with($good, 'R2', static function (array $d): array {
            $d['os']['pi'][] = 'Gi0/2';

            return $d;
        });
        $m = self::mark($pub, $passive);
        $this->assertStringContainsString('No OSPF neighbours on R2–R3', $m['text']);
        $this->assertStringContainsString('R2 Gi0/2 is passive', $m['text']);
        $this->assertTrue((new Simulator(Topology::fromArray($passive)))->ping('p2', Topology::ip((string) $pub['server']) ?? 0)['ok'], 'round by R1');
        $this->assertLessThanOrEqual(50, $m['score']);

        // The LAN not passive: works, but loses the passive marks.
        $chatty = self::with($good, 'R3', static fn (array $d): array => ['os' => ['pi' => []] + $d['os']] + $d);
        $this->assertSame(90, self::mark($pub, $chatty)['score']);

        // A mask typed where the wildcard goes: no interface matches, so R1 has no OSPF interfaces.
        $mask = self::with($good, 'R1', static function (array $d): array {
            foreach ($d['os']['n'] as $i => $n) {
                $d['os']['n'][$i][1] = long2ip(~(Topology::ip($n[1]) ?? 0) & 0xFFFFFFFF);
            }

            return $d;
        });
        $sim = new Simulator(Topology::fromArray($mask));
        $this->assertSame([], $sim->ospfInterfaces('r1'));
        $this->assertStringContainsString('R1 Gi0/0 is not covered by a network statement', self::mark($pub, $mask)['text']);

        // Areas that do not match on a link: no neighbours there.
        $area = self::with($good, 'R3', static function (array $d): array {
            foreach ($d['os']['n'] as $i => $n) {
                $d['os']['n'][$i][2] = '1';
            }

            return $d;
        });
        $sim = new Simulator(Topology::fromArray($area));
        $this->assertSame([], $sim->ospfNeighbours('r3'));
        $this->assertStringContainsString('R3 has an interface outside area 0', self::mark($pub, $area)['text']);

        // A core interface shut down: OSPF goes round, and the failover check still passes for the other links.
        $down = self::with($good, 'R1', static function (array $d): array {
            $d['if']['Gi0/1']['u'] = 0;

            return $d;
        });
        $sim = new Simulator(Topology::fromArray($down));
        $this->assertCount(1, $sim->ospfNeighbours('r1'));
        $this->assertTrue($sim->ping('p1', Topology::ip((string) $pub['start']['d'][5]['ip']) ?? 0)['ok']);
    }

    public function testDefaultRoutesStaticAndShared(): void
    {
        // R1 has a default route to an "ISP" router; with default-information originate, R2 learns it.
        $net = ['d' => [
            ['i' => 'pc', 't' => 'pc', 'n' => 'PC', 'x' => 1, 'y' => 1, 'ip' => '192.168.2.10', 'm' => '/24', 'g' => '192.168.2.1'],
            ['i' => 'r1', 't' => 'router', 'n' => 'R1', 'x' => 1, 'y' => 1,
                'if' => ['Gi0/0' => ['ip' => '203.0.113.2', 'm' => '/30', 'u' => 1], 'Gi0/1' => ['ip' => '10.0.0.1', 'm' => '/30', 'u' => 1]],
                'rt' => [['0.0.0.0', '0.0.0.0', '203.0.113.1']],
                'os' => ['n' => [['10.0.0.0', '0.0.0.3', '0']], 'di' => 1]],
            ['i' => 'r2', 't' => 'router', 'n' => 'R2', 'x' => 1, 'y' => 1,
                'if' => ['Gi0/1' => ['ip' => '10.0.0.2', 'm' => '/30', 'u' => 1], 'Gi0/0' => ['ip' => '192.168.2.1', 'm' => '/24', 'u' => 1]],
                'os' => ['n' => [['0.0.0.0', '255.255.255.255', '0.0.0.0']], 'pi' => ['Gi0/0', 'Gi0/9', 'Gi0/0']]],
            ['i' => 'isp', 't' => 'router', 'n' => 'ISP', 'x' => 1, 'y' => 1,
                'if' => ['Gi0/0' => ['ip' => '203.0.113.1', 'm' => '/30', 'u' => 1], 'Gi0/1' => ['ip' => '8.8.8.1', 'm' => '/24', 'u' => 1]],
                'rt' => [['192.168.2.0', '/24', '203.0.113.2'], ['10.0.0.0', '/30', '203.0.113.2']]],
            ['i' => 'web', 't' => 'server', 'n' => 'WEB', 'x' => 1, 'y' => 1, 'ip' => '8.8.8.8', 'm' => '/24', 'g' => '8.8.8.1'],
        ], 'l' => [['r1', 'Gi0/0', 'isp', 'Gi0/0'], ['r1', 'Gi0/1', 'r2', 'Gi0/1'], ['r2', 'Gi0/0', 'pc', 'Fa0'], ['isp', 'Gi0/1', 'web', 'Fa0']]];
        $topo = Topology::fromArray($net);
        $this->assertSame(['Gi0/0'], $topo->devices['r2']['ospf']['passive'] ?? null, 'unknown and repeated passive ports are dropped');
        $this->assertSame(0, $topo->devices['r1']['routes'][0]['mask'], 'a default route is kept');
        $sim = new Simulator($topo);
        $default = array_values(array_filter($sim->ospfRoutes('r2'), static fn (array $r): bool => $r['mask'] === 0));
        $this->assertCount(1, $default);
        $this->assertTrue($default[0]['external']);
        $this->assertTrue($sim->ping('pc', Topology::ip('8.8.8.8') ?? 0)['ok'], 'out through the shared default route');
        $this->assertSame([], array_values(array_filter($sim->ospfRoutes('r1'), static fn (array $r): bool => $r['mask'] === 0)), 'the router that shares it does not learn its own');

        // Without originate, R2 has no way out.
        $net['d'][1]['os'] = ['n' => [['10.0.0.0', '0.0.0.3', 0]]];
        $this->assertFalse((new Simulator(Topology::fromArray($net)))->ping('pc', Topology::ip('8.8.8.8') ?? 0)['ok']);

        // A static route beats an OSPF route for the same network (distance 1 against 110):
        // R2's own default route to a next hop that does not answer wins over the shared one.
        $net['d'][1]['os']['di'] = 1;
        $net['d'][2]['rt'] = [['0.0.0.0', '0.0.0.0', '10.0.0.3']];
        $why = (new Simulator(Topology::fromArray($net)))->ping('pc', Topology::ip('8.8.8.8') ?? 0)['why'];
        $this->assertStringContainsString('next hop 10.0.0.3 does not answer', $why);

        // Round trip of the OSPF settings, as kept for evidence.
        $this->assertSame($topo->toArray(), Topology::fromArray($topo->toArray())->toArray());
        $this->assertSame(['n' => [['0.0.0.0', '255.255.255.255', 0]], 'pi' => ['Gi0/0']], $topo->toArray()['d'][2]['os']);
    }
}
