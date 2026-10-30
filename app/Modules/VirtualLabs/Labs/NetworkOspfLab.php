<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Network\OspfScenario;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;

/**
 * Networking: dynamic routing with OSPF (ADR 0041). Three routers in a
 * triangle, every address already set, no routes. The learner turns on OSPF so
 * the routers learn every network themselves. The server rebuilds the network,
 * then breaks each router-to-router link in turn to check that traffic finds
 * another way, which static routes alone would not do.
 *
 * @phpstan-import-type Device from Topology
 */
final class NetworkOspfLab implements Lab
{
    private const NAMES = ['r1' => 'R1', 'r2' => 'R2', 'r3' => 'R3'];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'network-ospf',
            version: 1,
            title: 'Dynamic routing with OSPF',
            subject: 'Networking',
            track: 'skills',
            level: 'Advanced',
            summary: 'Three sites, three routers in a triangle, no routes. Turn on OSPF so the routers find every network themselves, '
                . 'then watch traffic take another way when a link fails.',
            objective: 'Configure single-area OSPF with network statements and wildcards, use passive interfaces, read neighbours and learned routes, '
                . 'and explain why dynamic routing survives a failed link.',
            tasks: [
                'On each router, start OSPF (router ospf 1) and add network statements, with wildcards, that cover all three of its networks, in area 0.',
                'Make each router’s LAN port (Gi0/0) passive: its network is still advertised, but no hellos go out to the PCs.',
                'Check show ip ospf neighbor on every router (each should have two) and show ip route for the O routes.',
                'Ping between PC1, PC2 and SRV1. Use no static routes: the routers should learn everything.',
                'Shut down one router-to-router link and ping again: OSPF should send the traffic the long way round.',
            ],
            prerequisites: 'The “Build a routed network” lab; subnet masks and /30 links.',
            minutes: 50,
            engine: 'network-builder',
        );
    }

    public function params(Seed $seed): array
    {
        return ['public' => OspfScenario::from($seed)->publicView(), 'secret' => []];
    }

    public function fields(array $public): array
    {
        return [new LabField('topology', 'Your network', 'hidden')];
    }

    public function check(array $params, array $answers): LabResult
    {
        $net = Topology::fromJson((string) ($answers['topology'] ?? ''));
        $sim = new Simulator($net);
        $byName = [];
        foreach ($net->devices as $d) {
            $byName[strtoupper($d['name'])] = $d;
        }
        $routers = [];
        foreach (self::NAMES as $name) {
            if (isset($byName[$name]) && $byName[$name]['type'] === 'router') {
                $routers[$name] = $byName[$name];
            }
        }
        $hosts = array_values(array_filter([$byName['PC1'] ?? null, $byName['PC2'] ?? null, $byName['SRV1'] ?? null]));
        $complete = count($routers) === 3 && count($hosts) === 3;
        $parts = [];

        // 1. OSPF runs on every working interface of every router.
        $missing = [];
        foreach ($routers as $name => $r) {
            if ($r['ospf'] === null) {
                $missing[] = $name . ' does not run OSPF (router ospf 1)';
                continue;
            }
            $running = array_column($sim->ospfInterfaces($r['id']), 'port');
            foreach (array_keys($sim->interfaces($r)) as $port) {
                if (!in_array((string) $port, $running, true)) {
                    $missing[] = $name . ' ' . $port . ' is not covered by a network statement';
                }
            }
            $areas = array_unique(array_column($sim->ospfInterfaces($r['id']), 'area'));
            if (count($areas) > 1 || ($areas !== [] && reset($areas) !== 0)) {
                $missing[] = $name . ' has an interface outside area 0';
            }
        }
        $parts[] = ['label' => 'OSPF on every router', 'ok' => count($routers) === 3 && $missing === [], 'marks' => 25, 'detail' => match (true) {
            count($routers) !== 3 => 'Keep R1, R2 and R3 in the network.',
            $missing === [] => 'All three routers run OSPF on all their networks, in area 0.',
            default => ucfirst(implode('; ', array_slice($missing, 0, 4))) . '.',
        }];

        // 2. The three adjacencies.
        $pairs = [];
        foreach ($routers as $name => $r) {
            foreach ($sim->ospfNeighbours($r['id']) as $nb) {
                $other = $net->devices[$nb['dev']]['name'];
                $key = strcmp($name, $other) < 0 ? $name . '–' . $other : $other . '–' . $name;
                $pairs[$key] = true;
            }
        }
        $wanted = ['R1–R2', 'R2–R3', 'R1–R3'];
        $noPair = array_values(array_diff($wanted, array_keys($pairs)));
        $parts[] = ['label' => 'Neighbours on every link', 'ok' => count($routers) === 3 && $noPair === [], 'marks' => 20, 'detail' => match (true) {
            count($routers) !== 3 => 'Keep R1, R2 and R3 in the network.',
            $noPair === [] => 'R1, R2 and R3 are neighbours on all three links.',
            default => 'No OSPF neighbours on ' . implode(', ', $noPair) . '. Both ends must run OSPF on that link, not be passive, and share the network and area.',
        }];

        // 3. LAN ports passive, nothing else.
        $passiveProblems = [];
        foreach ($routers as $name => $r) {
            $passive = $r['ospf']['passive'] ?? [];
            if (!in_array('Gi0/0', $passive, true)) {
                $passiveProblems[] = $name . ' Gi0/0 is not passive';
            }
            foreach (['Gi0/1', 'Gi0/2'] as $core) {
                if (in_array($core, $passive, true)) {
                    $passiveProblems[] = $name . ' ' . $core . ' is passive, so it cannot find its neighbour';
                }
            }
        }
        $parts[] = ['label' => 'Passive LAN ports', 'ok' => count($routers) === 3 && $passiveProblems === [], 'marks' => 10, 'detail' => match (true) {
            count($routers) !== 3 => 'Keep R1, R2 and R3 in the network.',
            $passiveProblems === [] => 'Each LAN port is passive: its network is advertised, but no hellos go to the PCs.',
            default => ucfirst(implode('; ', array_slice($passiveProblems, 0, 4))) . '.',
        }];

        // 4. Everyone reaches everyone, with routes learned rather than typed.
        $statics = [];
        foreach ($routers as $name => $r) {
            if ($r['routes'] !== []) {
                $statics[] = $name;
            }
        }
        $fails = $complete ? $this->pingAll($sim, $hosts) : [];
        $parts[] = ['label' => 'Every site reaches every other', 'ok' => $complete && $fails === [] && $statics === [], 'marks' => 25, 'detail' => match (true) {
            !$complete => 'Keep PC1, PC2, SRV1 and the three routers in the network.',
            $fails !== [] => ucfirst(implode('; ', array_slice($fails, 0, 3))) . '.',
            $statics !== [] => 'Pings work, but ' . implode(', ', $statics) . ' still ' . (count($statics) === 1 ? 'has' : 'have')
                . ' static routes. Remove them: the routers should learn every network by OSPF.',
            default => 'PC1, PC2 and SRV1 all reach each other over routes the routers learned by OSPF.',
        }];

        // 5. Break each core link in turn.
        $broken = [];
        if ($complete && $fails === []) {
            foreach ($net->links as $i => [$a, $pa, $b, $pb]) {
                if (!isset(self::NAMES[$a], self::NAMES[$b]) || ($net->devices[$a]['type'] !== 'router') || ($net->devices[$b]['type'] !== 'router')) {
                    continue;
                }
                $label = $net->devices[$a]['name'] . '–' . $net->devices[$b]['name'];
                $without = new Simulator($net->withoutLink($i));
                $lost = $this->pingAll($without, $hosts);
                if ($lost !== []) {
                    $broken[] = 'with ' . $label . ' down, ' . $lost[0];
                }
            }
        }
        $parts[] = ['label' => 'Survives a failed link', 'ok' => $complete && $fails === [] && $broken === [] && count($pairs) >= 3, 'marks' => 20, 'detail' => match (true) {
            !$complete || $fails !== [] => 'First get every site reaching every other; then the server breaks each router-to-router link in turn.',
            $broken !== [] => ucfirst(implode('; ', array_slice($broken, 0, 3))) . '.',
            count($pairs) < 3 => 'Every link needs OSPF neighbours, so that traffic has another way when one fails.',
            default => 'The server broke each router-to-router link in turn; every time, OSPF found another way and every site still reached every other.',
        }];

        return LabResult::fromParts($parts, ['topology' => $net->toArray()]);
    }

    /**
     * Pings every pair of hosts both ways.
     *
     * @param list<Device> $hosts
     * @return list<string> what failed
     */
    private function pingAll(Simulator $sim, array $hosts): array
    {
        $fails = [];
        foreach ($hosts as $from) {
            foreach ($hosts as $to) {
                if ($from['id'] === $to['id']) {
                    continue;
                }
                [$ip] = $sim->addressing($to);
                if ($ip === null) {
                    $fails[] = $to['name'] . ' has no address';
                    continue;
                }
                $r = $sim->ping((string) $from['id'], $ip);
                if (!$r['ok']) {
                    $fails[] = $from['name'] . ' cannot reach ' . $to['name'] . ': ' . $r['why'];
                }
            }
        }

        return $fails;
    }
}
