<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Network\Scenario;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;

/**
 * Networking: build a small routed network from nothing in the network builder
 * (ADR 0035): two PCs on a switch, two routers joined by a /30 link, and a
 * server. Address it from the plan, bring the interfaces up, add static routes
 * both ways and prove it with ping. The server rebuilds the network from what
 * was sent and tests it itself.
 */
final class NetworkBuildLab implements Lab
{
    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'network-build',
            version: 1,
            title: 'Build a routed network',
            subject: 'Networking',
            track: 'skills',
            level: 'Intermediate',
            summary: 'In the network builder, place and cable PCs, a switch, two routers and a server, address them from a plan, add static routes and prove it works with ping.',
            objective: 'Build, address and route a two-network topology so every PC can reach the server, and test it like a network technician.',
            tasks: [
                'Add two PCs, a switch, two routers and a server, and cable them: PCs to the switch, the switch to R1, R1 to R2, R2 to the server.',
                'Give each router interface its address from the plan and bring it up (no shutdown).',
                'Set each PC’s and the server’s address, mask and default gateway.',
                'Add a static route on each router to the network it cannot see (R1 to the server’s network, R2 back to the PCs’ network).',
                'Ping the server from each PC. Use Simulation to watch the packets if something fails.',
            ],
            prerequisites: 'IP addresses, subnet masks and default gateways; what switches and routers do.',
            minutes: 60,
            engine: 'network-builder',
        );
    }

    public function params(Seed $seed): array
    {
        $s = Scenario::from($seed);

        return ['public' => ['mode' => 'build'] + $s->publicView(), 'secret' => []];
    }

    public function fields(array $public): array
    {
        return [new LabField('topology', 'Your network', 'hidden')];
    }

    public function check(array $params, array $answers): LabResult
    {
        $p = $params['public'];
        $net = Topology::fromJson((string) ($answers['topology'] ?? ''));

        return LabResult::fromParts(self::mark($net, $p), ['topology' => $net->toArray()]);
    }

    /**
     * @param array<string, mixed> $p the public scenario
     * @return list<array{label: string, ok: bool, detail: string, marks: int}>
     */
    public static function mark(Topology $net, array $p): array
    {
        $sim = new Simulator($net);
        $lanA = Topology::ip(explode('/', (string) $p['lanA'])[0]) ?? 0;
        $lanB = Topology::ip(explode('/', (string) $p['lanB'])[0]) ?? 0;
        $link = Topology::ip(explode('/', (string) $p['link'])[0]) ?? 0;
        $server = Topology::ip((string) $p['server']) ?? 0;
        $m24 = Topology::prefixMask(24);
        $m30 = Topology::prefixMask(30);
        $pcs = $net->ofType('pc', 'laptop');
        $parts = [];

        // 1. Devices
        $missing = array_filter([
            count($pcs) < 2 ? 'two PCs' : null,
            count($net->ofType('switch')) < 1 ? 'a switch' : null,
            count($net->ofType('router')) < 2 ? 'two routers' : null,
            count($net->ofType('server')) < 1 ? 'a server' : null,
        ]);
        $parts[] = ['label' => 'Devices', 'ok' => $missing === [], 'marks' => 10,
            'detail' => $missing === [] ? 'Every device the plan needs is there.' : 'Still needed: ' . implode(', ', $missing) . '.'];

        // 2. Router interfaces: find each planned address, whether it is right and up.
        $want = [
            'R1 towards the PCs' => [$lanA + 1, $m24],
            'R1 towards R2' => [$link + 1, $m30],
            'R2 towards R1' => [$link + 2, $m30],
            'R2 towards the server' => [$lanB + 1, $m24],
        ];
        $found = [];
        foreach ($net->ofType('router') as $r) {
            foreach ($r['ifaces'] as $port => $i) {
                foreach ($want as $label => [$ip, $mask]) {
                    if ($i['ip'] === $ip) {
                        $found[$label] = ['dev' => $r['id'], 'port' => $port, 'mask' => $i['mask'] === $mask, 'up' => $i['up']];
                    }
                }
            }
        }
        $wrong = [];
        foreach ($want as $label => [$ip, $mask]) {
            if (!isset($found[$label])) {
                $wrong[] = $label . ' (' . long2ip($ip) . ') is missing';
            } elseif (!$found[$label]['mask']) {
                $wrong[] = $label . ' needs the mask ' . long2ip($mask);
            }
        }
        if (isset($found['R1 towards the PCs'], $found['R1 towards R2']) && $found['R1 towards the PCs']['dev'] !== $found['R1 towards R2']['dev']) {
            $wrong[] = 'R1’s two addresses must be on the same router';
        }
        if (isset($found['R1 towards R2'], $found['R2 towards R1']) && $found['R1 towards R2']['dev'] === $found['R2 towards R1']['dev']) {
            $wrong[] = 'the two ends of the R1–R2 link must be on different routers';
        }
        $parts[] = ['label' => 'Router addresses', 'ok' => $wrong === [], 'marks' => 15,
            'detail' => $wrong === [] ? 'All four router interfaces have the planned address and mask.' : ucfirst(implode('; ', $wrong)) . '.'];

        $down = array_keys(array_filter($found, static fn (array $f): bool => !$f['up']));
        $parts[] = ['label' => 'Interfaces up', 'ok' => $found !== [] && $down === [], 'marks' => 5,
            'detail' => match (true) {
                $found === [] => 'No router interface has a planned address yet.',
                $down === [] => 'Every addressed router interface is up.',
                default => 'Still shut down: ' . implode(', ', $down) . '. Use “no shutdown” (or tick Up).',
            }];

        // 3. Host settings
        $problems = [];
        $seen = [];
        $goodPcs = 0;
        foreach ($pcs as $pc) {
            $ok = $pc['ip'] !== null && $pc['mask'] === $m24 && ($pc['ip'] & $m24) === $lanA && Topology::isHostAddress($pc['ip'], $m24)
                && $pc['ip'] !== $lanA + 1 && !isset($seen[$pc['ip']]) && $pc['gateway'] === $lanA + 1;
            if ($pc['ip'] !== null) {
                $seen[$pc['ip']] = true;
            }
            if ($ok) {
                $goodPcs++;
            } else {
                $problems[] = $pc['name'] . ' needs a free address in ' . $p['lanA'] . ', mask 255.255.255.0 and gateway ' . long2ip($lanA + 1);
            }
        }
        $srvOk = false;
        foreach ($net->ofType('server') as $srv) {
            $srvOk = $srvOk || ($srv['ip'] === $server && $srv['mask'] === $m24 && $srv['gateway'] === $lanB + 1);
        }
        if (!$srvOk) {
            $problems[] = 'the server needs ' . long2ip($server) . ', mask 255.255.255.0 and gateway ' . long2ip($lanB + 1);
        }
        $parts[] = ['label' => 'PC and server settings', 'ok' => $goodPcs >= 2 && $srvOk && count($problems) === 0, 'marks' => 15,
            'detail' => $problems === [] && $goodPcs >= 2 ? 'Every PC and the server have a correct address, mask and gateway.' : ucfirst(implode('; ', array_slice($problems, 0, 3))) . '.'];

        // 4. Cabling: who shares a segment with whom.
        $cable = [];
        $switchIds = array_column($net->ofType('switch'), 'id');
        foreach ($pcs as $pc) {
            $with = $sim->devicesWith($pc['id'], 'Fa0');
            if ($with === []) {
                $cable[] = $pc['name'] . ' has no cable';
            } elseif (array_intersect($with, $switchIds) === []) {
                $cable[] = $pc['name'] . ' should be cabled to the switch';
            } elseif (isset($found['R1 towards the PCs']) && !in_array($found['R1 towards the PCs']['dev'], $with, true)) {
                $cable[] = $pc['name'] . '’s switch is not cabled to R1’s ' . $found['R1 towards the PCs']['port'];
            }
        }
        if (isset($found['R1 towards R2'], $found['R2 towards R1'])) {
            $sa = $sim->segmentOf($found['R1 towards R2']['dev'], $found['R1 towards R2']['port']);
            if ($sa === null || $sa !== $sim->segmentOf($found['R2 towards R1']['dev'], $found['R2 towards R1']['port'])) {
                $cable[] = 'R1’s ' . $found['R1 towards R2']['port'] . ' and R2’s ' . $found['R2 towards R1']['port'] . ' should be cabled together';
            }
        }
        foreach ($net->ofType('server') as $srv) {
            if (isset($found['R2 towards the server']) && !in_array($found['R2 towards the server']['dev'], $sim->devicesWith($srv['id'], 'Fa0'), true)) {
                $cable[] = $srv['name'] . ' should be cabled to R2’s ' . $found['R2 towards the server']['port'];
            }
        }
        $parts[] = ['label' => 'Cables', 'ok' => $cable === [] && count($pcs) >= 2 && $found !== [], 'marks' => 15,
            'detail' => match (true) {
                $cable !== [] => ucfirst(implode('; ', array_slice($cable, 0, 3))) . '.',
                $found === [] => 'Address the routers so the cabling can be checked.',
                default => 'Every device is cabled where the plan says.',
            }];

        // 5. Routing between the routers.
        $r1 = $found['R1 towards the PCs']['dev'] ?? null;
        $r2 = $found['R2 towards the server']['dev'] ?? null;
        $routeProblems = [];
        if ($r1 === null || $r2 === null) {
            $routeProblems[] = 'both routers need their addresses first';
        } else {
            $there = $sim->walk($r1, $server);
            $back = $sim->walk($r2, $lanA + 1);
            if (!$there['ok']) {
                $routeProblems[] = 'from R1 to the server: ' . $there['why'];
            }
            if (!$back['ok']) {
                $routeProblems[] = 'from R2 back to the PCs’ network: ' . $back['why'];
            }
        }
        $parts[] = ['label' => 'Routes', 'ok' => $routeProblems === [], 'marks' => 10,
            'detail' => $routeProblems === [] ? 'Each router knows the way to the other network.' : ucfirst(implode('; ', $routeProblems)) . '.'];

        // 6. The real test: every PC pings the server.
        $fails = [];
        foreach ($pcs as $pc) {
            $ping = $sim->ping($pc['id'], $server);
            if (!$ping['ok']) {
                $fails[] = $pc['name'] . ': ' . $ping['why'];
            }
        }
        $dupes = $sim->duplicates();
        $parts[] = ['label' => 'Every PC reaches the server', 'ok' => count($pcs) >= 2 && $fails === [] && $dupes === [], 'marks' => 30,
            'detail' => count($pcs) < 2 ? 'Add and connect at least two PCs.' : ($fails === [] && $dupes === []
                ? 'Ping from every PC to ' . long2ip($server) . ' gets replies.'
                : ucfirst(implode('; ', array_slice(array_merge($dupes === [] ? [] : ['two devices use ' . implode(', ', $dupes)], $fails), 0, 3))) . '.')];

        return $parts;
    }
}
