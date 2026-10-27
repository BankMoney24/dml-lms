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
 * Networking: a finished office network in the network builder with three
 * faults planted in it (ADR 0035). The learner tests, finds and fixes them so
 * every PC reaches the server. Each session plants a different three.
 */
final class NetworkRepairLab implements Lab
{
    /** fault => what it is, for the feedback */
    public const FAULTS = [
        'pc_gateway' => 'a PC had the wrong default gateway',
        'pc_mask' => 'a PC had a subnet mask too small for its network, so its gateway looked like it was on another network',
        'pc_ip' => 'a PC had an address on the wrong network',
        'unplugged' => 'a PC’s cable was unplugged',
        'if_down' => 'a router interface was shut down',
        'route_missing' => 'R2 had no route back to the PCs’ network',
        'route_wrong' => 'R1’s route to the server’s network pointed at R2’s far interface instead of R2’s address on the link',
        'srv_gateway' => 'the server had the wrong default gateway',
    ];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'network-repair',
            version: 1,
            title: 'Repair the office network',
            subject: 'Networking',
            track: 'skills',
            level: 'Intermediate',
            summary: 'An office network has three faults. Test it in the network builder, find each fault with ping, the command line and Simulation, and fix it.',
            objective: 'Troubleshoot a working-looking network methodically: test, locate the fault, fix one thing at a time and test again.',
            tasks: [
                'Ping the server from each PC to see which ones fail.',
                'Check each failing PC’s settings (ipconfig), then the routers (show ip interface brief, show ip route).',
                'Use Simulation to watch where a packet stops.',
                'Fix each fault and ping again until every PC gets replies.',
            ],
            prerequisites: 'The “Build a routed network” lab, or knowing how addresses, gateways and static routes work.',
            minutes: 45,
            engine: 'network-builder',
        );
    }

    public function params(Seed $seed): array
    {
        $s = Scenario::from($seed);
        $start = $s->working(3, $seed->int(0, 1000));
        // Three different faults; at most one per PC.
        $faults = array_slice($seed->shuffle(array_keys(self::FAULTS)), 0, 3);
        $pc = 0;
        $where = [];
        foreach ($faults as $fault) {
            $target = in_array($fault, ['pc_gateway', 'pc_mask', 'pc_ip', 'unplugged'], true) ? 'p' . (++$pc) : null;
            $where[$fault] = $target;
            $start = self::plant($start, $fault, $target, $s, $seed);
        }

        $public = ['mode' => 'repair', 'start' => $start] + $s->publicView();
        $public['plan'] = $s->plan(false);

        return ['public' => $public, 'secret' => ['faults' => $where]];
    }

    /**
     * @param array{d: list<array<string, mixed>>, l: list<list<string>>} $net
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    private static function plant(array $net, string $fault, ?string $pc, Scenario $s, Seed $seed): array
    {
        foreach ($net['d'] as $i => $d) {
            if ($d['i'] === $pc) {
                $ip = Topology::ip((string) $d['ip']) ?? 0;
                $net['d'][$i] = match ($fault) {
                    'pc_gateway' => ['g' => long2ip($s->lanA + $seed->pick([254, 2, 100]))] + $d,
                    'pc_mask' => ['ip' => long2ip($s->lanA + 200 + $seed->int(0, 40)), 'm' => '255.255.255.240'] + $d,
                    'pc_ip' => ['ip' => long2ip(($ip & 0xFFFF00FF) | ((((($ip >> 8) & 255) + 1) % 250) << 8))] + $d,
                    default => $d,
                };
            }
            if ($fault === 'if_down' && $d['i'] === 'r2') {
                $net['d'][$i]['if']['Gi0/1']['u'] = 0;
            }
            if ($fault === 'route_missing' && $d['i'] === 'r2') {
                $net['d'][$i]['rt'] = [];
            }
            if ($fault === 'route_wrong' && $d['i'] === 'r1') {
                $net['d'][$i]['rt'] = [[long2ip($s->lanB), '255.255.255.0', long2ip($s->ip['r2_lan'])]];
            }
            if ($fault === 'srv_gateway' && $d['i'] === 'v1') {
                $net['d'][$i]['g'] = long2ip($s->lanB + 254);
            }
        }
        if ($fault === 'unplugged') {
            $net['l'] = array_values(array_filter($net['l'], static fn (array $l): bool => $l[0] !== $pc));
        }

        return $net;
    }

    public function fields(array $public): array
    {
        return [new LabField('topology', 'Your network', 'hidden')];
    }

    public function check(array $params, array $answers): LabResult
    {
        $p = $params['public'];
        $net = Topology::fromJson((string) ($answers['topology'] ?? ''));
        $sim = new Simulator($net);
        $server = Topology::ip((string) $p['server']) ?? 0;
        $pcs = $net->ofType('pc', 'laptop');
        $parts = [];

        $lanA = Topology::ip(explode('/', (string) $p['lanA'])[0]) ?? 0;
        $lanB = Topology::ip(explode('/', (string) $p['lanB'])[0]) ?? 0;
        $link = Topology::ip(explode('/', (string) $p['link'])[0]) ?? 0;
        foreach ((array) $params['secret']['faults'] as $fault => $pcId) {
            $ok = match ($fault) {
                'if_down' => self::interfaceUp($net, $lanB + 1),
                'route_missing' => self::routesVia($net, $link + 2, $lanA + 1, $link + 1),
                'route_wrong' => self::routesVia($net, $link + 1, $server, $link + 2),
                'srv_gateway' => array_filter($net->ofType('server'), static fn (array $d): bool => $d['ip'] === $server && $d['gateway'] === $lanB + 1) !== [],
                default => isset($net->devices[(string) $pcId]) && $sim->ping((string) $pcId, $server)['ok'],
            };
            $name = isset($net->devices[(string) $pcId]) ? $net->devices[(string) $pcId]['name'] : 'PC';
            $what = self::FAULTS[$fault] ?? $fault;
            $parts[] = ['label' => 'Fault: ' . ucfirst(explode(',', $what)[0]), 'ok' => $ok, 'marks' => 20,
                'detail' => ($ok ? 'Fixed: ' : 'Not fixed yet: ') . ($pcId !== null ? str_replace('a PC', $name, $what) : $what) . '.'];
        }

        $fails = [];
        foreach ($pcs as $pc) {
            $ping = $sim->ping($pc['id'], $server);
            if (!$ping['ok']) {
                $fails[] = $pc['name'] . ': ' . $ping['why'];
            }
        }
        $parts[] = ['label' => 'Every PC reaches the server', 'ok' => count($pcs) >= 3 && $fails === [], 'marks' => 40,
            'detail' => match (true) {
                count($pcs) < 3 => 'All three PCs must stay in the network.',
                $fails === [] => 'Ping from every PC to ' . long2ip($server) . ' gets replies.',
                default => ucfirst(implode('; ', $fails)) . '.',
            }];

        return LabResult::fromParts($parts, ['topology' => $net->toArray()]);
    }

    /** Is the router interface with this address up? */
    private static function interfaceUp(Topology $net, int $ip): bool
    {
        foreach ($net->ofType('router') as $r) {
            foreach ($r['ifaces'] as $i) {
                if ($i['ip'] === $ip) {
                    return $i['up'];
                }
            }
        }

        return false;
    }

    /** Does the router owning $routerIp send traffic for $dst to $via (best matching static route)? */
    private static function routesVia(Topology $net, int $routerIp, int $dst, int $via): bool
    {
        foreach ($net->ofType('router') as $r) {
            if (!in_array($routerIp, array_column($r['ifaces'], 'ip'), true)) {
                continue;
            }
            $best = null;
            foreach ($r['routes'] as $route) {
                if (($dst & $route['mask']) === $route['net'] && ($best === null || $route['mask'] > $best['mask'])) {
                    $best = $route;
                }
            }

            return $best !== null && $best['via'] === $via;
        }

        return false;
    }
}
