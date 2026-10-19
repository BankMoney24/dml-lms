<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Networking: a learner's PC cannot reach the results server. The browser
 * simulates a small routed network with a terminal (ipconfig, ping,
 * tracert, show ip route, show interfaces, arp -a). One thing is broken:
 * the PC's gateway, a missing route, a route pointing the wrong way (a
 * loop), or a missing route back. The learner traces, reads the routing
 * tables, and says where the fault is and what the setting should be.
 *
 *   PC1 ─ LAN A ─ R1 ─ R2 ─ R3 ─ LAN B ─ server
 *                      │
 *                      R4 ─ LAN C (library)
 */
final class NetworkTraceLab implements Lab
{
    public const FAULTS = [
        'gateway' => 'The PC has the wrong default gateway',
        'missing' => 'A router has no route to the server’s network',
        'loop' => 'A router sends the traffic the wrong way, so it goes round in a loop',
        'return' => 'The way back is missing: a router has no route back to the PC’s network',
    ];
    public const DEVICES = ['PC1' => 'PC1 (your computer)', 'R1' => 'R1 (school edge)', 'R2' => 'R2 (core)', 'R3' => 'R3 (data centre)', 'R4' => 'R4 (library)', 'SRV' => 'The results server'];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'network-trace',
            version: 1,
            title: 'Trace the route: find the broken link',
            subject: 'Networking',
            track: 'skills',
            level: 'Intermediate',
            summary: 'Use ping, tracert and routing tables on a simulated network to find why a PC cannot reach a server, and say how to fix it.',
            objective: 'Troubleshoot a routed network by tracing the path a packet takes and reading each router’s routing table.',
            tasks: [
                'Run ipconfig and ping the server. Note what answers, if anything.',
                'Run tracert to the server and see where the trace stops or starts repeating.',
                'Read the routing tables (show ip route R1 … R4) along the path, there and back.',
                'Say which device is at fault, what is wrong, and what the next hop or gateway should be.',
            ],
            prerequisites: 'IP addresses, subnet masks and what a default gateway does.',
            minutes: 35,
        );
    }

    public function params(Seed $seed): array
    {
        $a = $seed->int(10, 60);
        $b = $seed->int(10, 99);
        $c = $seed->int(100, 199);
        $l = $seed->int(0, 60) * 4;
        $lanA = "10.{$a}.1.0/24";
        $lanB = "192.168.{$b}.0/24";
        $lanC = "192.168.{$c}.0/24";
        $link12 = "172.16.{$a}.{$l}/30";
        $link23 = "172.16.{$a}." . ($l + 4) . '/30';
        $link24 = "172.16.{$a}." . ($l + 8) . '/30';
        $host = static function (string $net, int $n): string {
            $v = (int) ip2long(explode('/', $net)[0]) + $n;

            return implode('.', [($v >> 24) & 255, ($v >> 16) & 255, ($v >> 8) & 255, $v & 255]);
        };

        $ip = [
            'pc' => $host($lanA, $seed->int(10, 99)),
            'r1_lan' => $host($lanA, 1),
            'r1_12' => $host($link12, 1),
            'r2_12' => $host($link12, 2),
            'r2_23' => $host($link23, 1),
            'r2_24' => $host($link24, 1),
            'r3_23' => $host($link23, 2),
            'r3_lan' => $host($lanB, 1),
            'r4_24' => $host($link24, 2),
            'r4_lan' => $host($lanC, 1),
            'srv' => $host($lanB, $seed->int(10, 60)),
        ];
        $routes = [
            'R1' => [[$lanA, 'connected', 'Gi0/0'], [$link12, 'connected', 'Gi0/1'], ['0.0.0.0/0', $ip['r2_12'], 'Gi0/1']],
            'R2' => [
                [$link12, 'connected', 'Gi0/0'], [$link23, 'connected', 'Gi0/1'], [$link24, 'connected', 'Gi0/2'],
                [$lanA, $ip['r1_12'], 'Gi0/0'], [$lanB, $ip['r3_23'], 'Gi0/1'], [$lanC, $ip['r4_24'], 'Gi0/2'],
            ],
            'R3' => [[$link23, 'connected', 'Gi0/0'], [$lanB, 'connected', 'Gi0/1'], ['0.0.0.0/0', $ip['r2_23'], 'Gi0/0']],
            'R4' => [[$link24, 'connected', 'Gi0/0'], [$lanC, 'connected', 'Gi0/1'], ['0.0.0.0/0', $ip['r2_24'], 'Gi0/0']],
        ];
        $gateway = $ip['r1_lan'];
        $fault = $seed->pick(array_keys(self::FAULTS));
        [$device, $fix] = match ($fault) {
            'gateway' => ['PC1', $ip['r1_lan']],
            'missing' => ['R2', $ip['r3_23']],
            'loop' => ['R2', $ip['r3_23']],
            default => ['R3', $ip['r2_23']],
        };
        if ($fault === 'gateway') {
            $gateway = $host($lanA, $seed->pick([254, 2, 100]));
            if ($gateway === $ip['pc']) {
                $gateway = $host($lanA, 254);
            }
        } elseif ($fault === 'missing') {
            $routes['R2'] = array_values(array_filter($routes['R2'], static fn (array $r): bool => $r[0] !== $lanB));
        } elseif ($fault === 'loop') {
            foreach ($routes['R2'] as $i => $r) {
                if ($r[0] === $lanB) {
                    $routes['R2'][$i] = [$lanB, $ip['r4_24'], 'Gi0/2'];
                }
            }
        } else {
            // R3 loses its default route but keeps one for the library, so it is not obviously empty.
            $routes['R3'] = [[$link23, 'connected', 'Gi0/0'], [$lanB, 'connected', 'Gi0/1'], [$lanC, $ip['r2_23'], 'Gi0/0']];
        }

        return [
            'public' => [
                'nets' => ['A' => $lanA, 'B' => $lanB, 'C' => $lanC, 'L12' => $link12, 'L23' => $link23, 'L24' => $link24],
                'pc' => ['ip' => $ip['pc'], 'mask' => '255.255.255.0', 'gateway' => $gateway, 'mac' => sprintf('3c:52:82:%02x:%02x:%02x', $seed->int(0, 255), $seed->int(0, 255), $seed->int(0, 255))],
                'server' => ['ip' => $ip['srv'], 'name' => 'results.school.lan'],
                'interfaces' => [
                    'R1' => [['Gi0/0', $ip['r1_lan'], $lanA], ['Gi0/1', $ip['r1_12'], $link12]],
                    'R2' => [['Gi0/0', $ip['r2_12'], $link12], ['Gi0/1', $ip['r2_23'], $link23], ['Gi0/2', $ip['r2_24'], $link24]],
                    'R3' => [['Gi0/0', $ip['r3_23'], $link23], ['Gi0/1', $ip['r3_lan'], $lanB]],
                    'R4' => [['Gi0/0', $ip['r4_24'], $link24], ['Gi0/1', $ip['r4_lan'], $lanC]],
                ],
                'routes' => $routes,
                'r1mac' => sprintf('00:1a:2b:%02x:%02x:%02x', $seed->int(0, 255), $seed->int(0, 255), $seed->int(0, 255)),
            ],
            'secret' => ['problem' => $fault, 'at' => $device, 'hop' => $fix, 'server_net' => $lanB],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('device', 'Which device has the fault?', 'select', '', '', ['' => 'Choose'] + self::DEVICES),
            new LabField('fault', 'What is wrong?', 'select', '', '', ['' => 'Choose'] + self::FAULTS),
            new LabField('next_hop', 'What should the faulty gateway or next hop be?', 'text', '', 'An IP address, for example 172.16.20.6'),
            new LabField('server_network', 'Which network is the server on?', 'text', '', 'Network and prefix, for example 192.168.40.0/24'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $s = $params['secret'];
        $clean = static fn (?string $v): string => strtolower((string) preg_replace('/\s+/', '', (string) $v));
        $where = self::DEVICES[(string) $s['at']] ?? (string) $s['at'];

        return LabResult::fromParts([
            ['label' => 'Faulty device', 'ok' => ($answers['device'] ?? '') === $s['at'], 'detail' => 'The fault is on ' . $where . '.', 'marks' => 25],
            ['label' => 'What is wrong', 'ok' => ($answers['fault'] ?? '') === $s['problem'], 'detail' => (self::FAULTS[(string) $s['problem']] ?? '') . '.', 'marks' => 25],
            [
                'label' => 'The fix',
                'ok' => $clean($answers['next_hop'] ?? '') === $s['hop'],
                'detail' => $s['problem'] === 'gateway'
                    ? sprintf('PC1’s default gateway should be %s, R1’s address on the PC’s network.', $s['hop'])
                    : sprintf('%s should send that traffic to %s.', $s['at'], $s['hop']),
                'marks' => 30,
            ],
            [
                'label' => 'Server network',
                'ok' => $clean($answers['server_network'] ?? '') === $s['server_net'],
                'detail' => sprintf('The server is on %s, behind R3.', $s['server_net']),
                'marks' => 20,
            ],
        ], array_intersect_key($answers, array_flip(['device', 'fault', 'next_hop', 'server_network'])));
    }
}
