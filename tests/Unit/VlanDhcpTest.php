<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\VirtualLabs\Labs\NetworkVlanLab;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;
use PHPUnit\Framework\TestCase;

/** Update 38 (ADR 0038): VLANs, trunks, router subinterfaces and DHCP in the network builder. */
final class VlanDhcpTest extends TestCase
{
    /**
     * The finished VLAN lab network, built only from what the browser is given.
     *
     * @param array<string, mixed> $pub
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public static function workingVlanNetwork(array $pub): array
    {
        $net = $pub['start'];
        $staff = (int) $pub['staffVlan'];
        $students = (int) $pub['studentVlan'];
        $sNet = substr((string) $pub['staffNet'], 0, (int) strrpos((string) $pub['staffNet'], '.'));
        $tNet = substr((string) $pub['studentNet'], 0, (int) strrpos((string) $pub['studentNet'], '.'));
        $m24 = '255.255.255.0';
        foreach ($net['d'] as $i => $d) {
            if (str_starts_with((string) $d['n'], 'PC')) {
                $net['d'][$i]['dh'] = 1;
            }
            if ($d['n'] === 'SW1') {
                $net['d'][$i]['vl'] = [(string) $staff => 'Staff', (string) $students => 'Students'];
                $net['d'][$i]['sp'] = ['Fa0/1' => ['a', $staff], 'Fa0/2' => ['a', $staff], 'Fa0/5' => ['a', $staff], 'Fa0/3' => ['a', $students], 'Fa0/4' => ['a', $students], 'Gi0/1' => ['t', 1]];
            }
            if ($d['n'] === 'R1') {
                $net['d'][$i]['if']['Gi0/0']['u'] = 1;
                $net['d'][$i]['si'] = [
                    'Gi0/0.' . $staff => ['ip' => $sNet . '.1', 'm' => $m24, 'v' => $staff],
                    'Gi0/0.' . $students => ['ip' => $tNet . '.1', 'm' => $m24, 'v' => $students, 'h' => (string) $pub['server']],
                ];
            }
            if ($d['n'] === 'SRV1') {
                $net['d'][$i]['pl'] = [
                    ['n' => 'STAFF', 's' => $sNet . '.100', 'm' => $m24, 'g' => $sNet . '.1', 'c' => 20],
                    ['n' => 'STUDENTS', 's' => $tNet . '.100', 'm' => $m24, 'g' => $tNet . '.1', 'c' => 20],
                ];
            }
        }

        return $net;
    }

    /** @return array<string, mixed> */
    private static function pub(string $seed = 'vlan-1'): array
    {
        return (new NetworkVlanLab())->params(new Seed($seed))['public'];
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
     * @param array<string, mixed>                                 $pub
     * @param array{d: list<array<string, mixed>>, l: list<list<string>>} $net
     */
    private static function score(array $pub, array $net): int
    {
        return (new NetworkVlanLab())->check(['public' => $pub, 'secret' => []], ['topology' => json_encode($net, JSON_THROW_ON_ERROR)])->score;
    }

    private static function why(mixed $lease): string
    {
        return is_string($lease) ? $lease : '';
    }

    public function testTheFinishedNetworkLeasesAndPingsAcrossVlans(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $s) {
            $pub = self::pub('vlan-' . $s);
            $net = self::workingVlanNetwork($pub);
            $sim = new Simulator(Topology::fromArray($net));
            $lease = $sim->lease('p3');
            $this->assertIsArray($lease, 'PC3 is relayed to the server');
            $this->assertSame(explode('/', (string) $pub['studentNet'])[0], long2ip($lease['ip'] & 0xFFFFFF00));
            $this->assertSame('v1', $lease['server']);
            $this->assertNotSame($sim->lease('p1'), $sim->lease('p2'), 'two PCs never get the same address');
            $this->assertTrue($sim->ping('p4', Topology::ip((string) $pub['server']) ?? 0)['ok']);
            $this->assertTrue($sim->ping('p3', ($lease['ip'] & 0xFFFFFF00) + 1)['ok'], 'the gateway answers');
            $this->assertSame(100, self::score($pub, $net), 'seed ' . $s);
            $this->assertSame([], $sim->duplicates());
        }
    }

    public function testEachMissingPieceIsFoundAndExplained(): void
    {
        $pub = self::pub();
        $good = self::workingVlanNetwork($pub);
        $server = Topology::ip((string) $pub['server']) ?? 0;

        // No trunk: the router's tagged frames meet nothing, so nobody reaches a gateway.
        $noTrunk = self::with($good, 'SW1', static fn (array $d): array => ['sp' => ['Gi0/1' => ['a', 1]] + $d['sp']] + $d);
        $sim = new Simulator(Topology::fromArray($noTrunk));
        $this->assertFalse($sim->ping('p1', $server)['ok'] && $sim->ping('p3', $server)['ok']);
        $this->assertStringContainsString('no DHCP server answers', self::why($sim->lease('p3')));
        $this->assertLessThan(60, self::score($pub, $noTrunk));

        // A student port left in the staff VLAN: that PC gets a staff address, and the VLAN check says so.
        $wrongPort = self::with($good, 'SW1', static fn (array $d): array => ['sp' => ['Fa0/3' => ['a', (int) $pub['staffVlan']]] + $d['sp']] + $d);
        $sim = new Simulator(Topology::fromArray($wrongPort));
        $this->assertTrue($sim->ping('p3', $server)['ok'], 'it still works, in the wrong VLAN');
        $result = (new NetworkVlanLab())->check(['public' => $pub, 'secret' => []], ['topology' => json_encode($wrongPort, JSON_THROW_ON_ERROR)]);
        $this->assertStringContainsString('PC3 is not in VLAN ' . $pub['studentVlan'], (string) json_encode($result->feedback));

        // No relay: the students ask, and nobody answers in their VLAN.
        $noHelper = self::with($good, 'R1', static function (array $d): array {
            foreach ($d['si'] as $k => $v) {
                unset($d['si'][$k]['h']);
            }

            return $d;
        });
        $sim = new Simulator(Topology::fromArray($noHelper));
        $this->assertIsArray($sim->lease('p1'));
        $this->assertSame('no DHCP server answers on its network', $sim->lease('p3'));
        $this->assertStringContainsString('PC3 got no address from DHCP', $sim->ping('p3', $server)['why']);

        // The server cannot answer back through the relay: no gateway.
        $noGw = self::with($good, 'SRV1', static fn (array $d): array => ['g' => ''] + $d);
        $this->assertStringContainsString('cannot send its answer back', self::why((new Simulator(Topology::fromArray($noGw)))->lease('p3')));

        // A pool with room for one: the second student gets nothing.
        $small = self::with($good, 'SRV1', static function (array $d): array {
            $d['pl'][1]['c'] = 1;

            return $d;
        });
        $sim = new Simulator(Topology::fromArray($small));
        $this->assertIsArray($sim->lease('p3'));
        $this->assertStringContainsString('has no free addresses left', self::why($sim->lease('p4')));

        // The physical port shut down: the subinterfaces go with it.
        $down = self::with($good, 'R1', static function (array $d): array {
            $d['if']['Gi0/0']['u'] = 0;

            return $d;
        });
        $this->assertFalse((new Simulator(Topology::fromArray($down)))->ping('p3', $server)['ok'], 'students depend on the router');
        $this->assertLessThanOrEqual(55, self::score($pub, $down));
    }

    public function testRouterPoolsTrunksBetweenSwitchesAndExclusions(): void
    {
        // Two switches joined by a trunk, a router serving DHCP itself, with the first ten addresses kept back.
        $net = ['d' => [
            ['i' => 'a', 't' => 'pc', 'n' => 'A', 'x' => 1, 'y' => 1, 'dh' => 1],
            ['i' => 'b', 't' => 'pc', 'n' => 'B', 'x' => 1, 'y' => 1, 'dh' => 1],
            ['i' => 'c', 't' => 'pc', 'n' => 'C', 'x' => 1, 'y' => 1, 'ip' => '10.0.20.11', 'm' => '/24', 'g' => '10.0.20.1'],
            ['i' => 's1', 't' => 'switch', 'n' => 'S1', 'x' => 1, 'y' => 1, 'sp' => ['Fa0/1' => ['a', 10], 'Gi0/1' => ['t', 1], 'Gi0/2' => ['t', 1]]],
            ['i' => 's2', 't' => 'switch', 'n' => 'S2', 'x' => 1, 'y' => 1, 'sp' => ['Fa0/1' => ['a', 10], 'Fa0/2' => ['a', 20], 'Gi0/1' => ['t', 1]]],
            ['i' => 'r', 't' => 'router', 'n' => 'R', 'x' => 1, 'y' => 1,
                'if' => ['Gi0/0' => ['ip' => '', 'm' => '', 'u' => 1]],
                'si' => ['Gi0/0.10' => ['ip' => '10.0.10.1', 'm' => '/24', 'v' => 10], 'Gi0/0.20' => ['ip' => '10.0.20.1', 'm' => '/24', 'v' => 20]],
                'pl' => [['n' => 'TEN', 's' => '10.0.10.1', 'm' => '255.255.255.0', 'g' => '10.0.10.1']],
                'ex' => [['10.0.10.1', '10.0.10.10']]],
        ], 'l' => [['a', 'Fa0', 's1', 'Fa0/1'], ['s1', 'Gi0/1', 's2', 'Gi0/1'], ['b', 'Fa0', 's2', 'Fa0/1'], ['c', 'Fa0', 's2', 'Fa0/2'], ['s1', 'Gi0/2', 'r', 'Gi0/0']]];
        $topo = Topology::fromArray($net);
        $sim = new Simulator($topo);
        $a = $sim->lease('a');
        $b = $sim->lease('b');
        $this->assertIsArray($a);
        $this->assertIsArray($b);
        $this->assertSame('10.0.10.11', long2ip($a['ip']), 'past the excluded range');
        $this->assertSame('10.0.10.12', long2ip($b['ip']), 'across the trunk between switches');
        $this->assertTrue($sim->ping('b', Topology::ip('10.0.20.11') ?? 0)['ok'], 'VLAN 10 to VLAN 20 through the router');
        $this->assertSame($sim->segmentOf('a', 'Fa0'), $sim->segmentOf('b', 'Fa0'));
        $this->assertNotSame($sim->segmentOf('a', 'Fa0'), $sim->segmentOf('c', 'Fa0'));
        $this->assertContains('s2', $sim->devicesWith('a', 'Fa0'), 'switches are listed with their VLAN');

        // Round trip: what is kept as evidence reads back the same.
        $again = Topology::fromArray($topo->toArray());
        $this->assertSame($topo->toArray(), $again->toArray());
        $this->assertSame(10, $again->devices['r']['subs']['Gi0/0.10']['vlan']);

        // Rubbish is dropped: bad VLANs, bad subinterface names, more than eight subinterfaces.
        $odd = Topology::fromArray(['d' => [['i' => 's', 't' => 'switch', 'n' => 'S', 'sp' => ['Fa0/1' => ['a', 9999]]], ['i' => 'r', 't' => 'router', 'n' => 'R',
            'si' => ['Gi0/9.10' => ['v' => 10], 'Gi0/0.0' => ['v' => 10], 'Gi0/0.5' => ['v' => 0], 'Gi0/0.6' => ['v' => '6']]]], 'l' => []]);
        $this->assertSame(1, $odd->devices['s']['ports']['Fa0/1']['vlan']);
        $this->assertSame(['Gi0/0.6'], array_keys($odd->devices['r']['subs']));
    }
}
