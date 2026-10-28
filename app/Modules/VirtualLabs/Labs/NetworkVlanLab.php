<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Network\Simulator;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Network\VlanScenario;
use App\Modules\VirtualLabs\Seed;

/**
 * Networking: split one switch into staff and student VLANs, route between them
 * with a router on a stick, and give every PC its address from one DHCP server
 * through a relay (ADR 0038). The server rebuilds the network and tests it.
 */
final class NetworkVlanLab implements Lab
{
    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'network-vlan',
            version: 1,
            title: 'VLANs, a router on a stick and DHCP',
            subject: 'Networking',
            track: 'skills',
            level: 'Advanced',
            summary: 'Split one switch into a staff VLAN and a student VLAN, route between them on one router port with subinterfaces, '
                . 'and hand out every address from a single DHCP server with a relay.',
            objective: 'Separate networks on shared hardware with VLANs and trunks, route between VLANs, and run DHCP for more than one network from one server.',
            tasks: [
                'On SW1, put the staff PCs and the server in the staff VLAN, the student PCs in the student VLAN, and make the port to R1 a trunk.',
                'On R1, bring Gi0/0 up and add a subinterface for each VLAN (encapsulation dot1Q) with the address from the plan.',
                'On SRV1, turn on DHCP with a pool for each VLAN, each handing out its VLAN’s router address as the gateway.',
                'The server is in the staff VLAN, so on R1’s student subinterface relay DHCP to it (ip helper-address).',
                'Set every PC to DHCP, check what it got (ipconfig), then ping the server from every PC.',
            ],
            prerequisites: 'The “Build a routed network” lab; what a broadcast domain is.',
            minutes: 60,
            engine: 'network-builder',
        );
    }

    public function params(Seed $seed): array
    {
        $s = VlanScenario::from($seed);

        return ['public' => $s->publicView(), 'secret' => []];
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
        $staffNet = Topology::ip(explode('/', (string) $p['staffNet'])[0]) ?? 0;
        $studentNet = Topology::ip(explode('/', (string) $p['studentNet'])[0]) ?? 0;
        $staffVlan = (int) $p['staffVlan'];
        $studentVlan = (int) $p['studentVlan'];
        $mask = 0xFFFFFF00;
        $byName = [];
        foreach ($net->devices as $d) {
            $byName[strtoupper($d['name'])] = $d;
        }
        $staffPcs = array_values(array_filter([$byName['PC1'] ?? null, $byName['PC2'] ?? null]));
        $studentPcs = array_values(array_filter([$byName['PC3'] ?? null, $byName['PC4'] ?? null]));
        $pcs = [...$staffPcs, ...$studentPcs];
        $parts = [];

        // R1's two subinterfaces, by their planned addresses.
        $subs = ['staff' => null, 'students' => null];
        foreach ($net->ofType('router') as $r) {
            foreach ($r['subs'] as $name => $sub) {
                foreach (['staff' => [$staffNet + 1, $staffVlan], 'students' => [$studentNet + 1, $studentVlan]] as $k => [$ip, $vlan]) {
                    if ($sub['ip'] === $ip && $sub['mask'] === $mask && $sub['vlan'] === $vlan) {
                        $subs[$k] = ['dev' => $r['id'], 'name' => (string) $name, 'up' => $r['ifaces'][$sub['parent']]['up'] ?? false, 'helper' => $sub['helper']];
                    }
                }
            }
        }
        $routerOk = $subs['staff'] !== null && $subs['students'] !== null && $subs['staff']['up'] && $subs['students']['up'];
        $parts[] = ['label' => 'Router on a stick', 'ok' => $routerOk, 'marks' => 20, 'detail' => match (true) {
            $subs['staff'] === null => 'R1 needs a subinterface for VLAN ' . $staffVlan . ' (encapsulation dot1Q ' . $staffVlan . ') with ' . long2ip($staffNet + 1) . ' 255.255.255.0.',
            $subs['students'] === null => 'R1 needs a subinterface for VLAN ' . $studentVlan . ' (encapsulation dot1Q ' . $studentVlan . ') with ' . long2ip($studentNet + 1) . ' 255.255.255.0.',
            !$routerOk => 'The subinterfaces are set, but their physical port is shut down: no shutdown on it.',
            default => 'Both subinterfaces have the planned VLAN and address, on a port that is up.',
        }];

        // VLANs: each PC (and the server) in the same broadcast domain as its VLAN's gateway, and the two kept apart.
        $vlanProblems = [];
        $srv = $byName['SRV1'] ?? null;
        $groups = ['staff' => [...$staffPcs, ...($srv !== null ? [$srv] : [])], 'students' => $studentPcs];
        foreach ($groups as $k => $devices) {
            $gwSeg = $subs[$k] === null ? null : $sim->segmentOf($subs[$k]['dev'], $subs[$k]['name']);
            foreach ($devices as $d) {
                $seg = $sim->segmentOf($d['id'], 'Fa0');
                if ($seg === null || $gwSeg === null || $seg !== $gwSeg) {
                    $vlanProblems[] = $d['name'] . ' is not in VLAN ' . ($k === 'staff' ? $staffVlan : $studentVlan) . ' with R1’s gateway';
                }
            }
        }
        $joined = $subs['staff'] !== null && $subs['students'] !== null
            && $sim->segmentOf($subs['staff']['dev'], $subs['staff']['name']) === $sim->segmentOf($subs['students']['dev'], $subs['students']['name']);
        if ($joined) {
            $vlanProblems[] = 'the two VLANs are joined into one broadcast domain';
        }
        $parts[] = ['label' => 'VLANs and the trunk', 'ok' => count($pcs) === 4 && $srv !== null && $vlanProblems === [], 'marks' => 25, 'detail' => match (true) {
            count($pcs) !== 4 || $srv === null => 'Keep PC1 to PC4 and SRV1 in the network.',
            $vlanProblems === [] => 'Staff and students are in separate VLANs, and the trunk carries both to R1.',
            default => ucfirst(implode('; ', array_slice($vlanProblems, 0, 4))) . '. Check the access ports’ VLANs and that Gi0/1 is a trunk.',
        }];

        // DHCP: every PC asks, and gets an address in its VLAN's network with the right gateway.
        $dhcpProblems = [];
        foreach ($groups as $k => $devices) {
            $netAddr = $k === 'staff' ? $staffNet : $studentNet;
            foreach ($devices as $d) {
                if (!in_array($d['type'], ['pc', 'laptop'], true)) {
                    continue;
                }
                $lease = $sim->lease($d['id']);
                if (!$d['dhcp']) {
                    $dhcpProblems[] = $d['name'] . ' is not set to get its address by DHCP';
                } elseif (is_string($lease) || $lease === null) {
                    $dhcpProblems[] = $d['name'] . ' got no address: ' . ($lease ?? 'no answer');
                } elseif (($lease['ip'] & $mask) !== $netAddr || $lease['mask'] !== $mask || $lease['gateway'] !== $netAddr + 1) {
                    $dhcpProblems[] = sprintf(
                        '%s got %s with gateway %s, not an address on %s/24 with gateway %s',
                        $d['name'],
                        long2ip($lease['ip']),
                        Topology::show($lease['gateway']),
                        long2ip($netAddr),
                        long2ip($netAddr + 1),
                    );
                }
            }
        }
        $relayed = $subs['students'] !== null && $subs['students']['helper'] === $server;
        $parts[] = ['label' => 'DHCP for both VLANs', 'ok' => count($pcs) === 4 && $dhcpProblems === [] && $relayed, 'marks' => 30, 'detail' => match (true) {
            count($pcs) !== 4 => 'Keep PC1 to PC4 in the network.',
            $dhcpProblems !== [] => ucfirst(implode('; ', array_slice($dhcpProblems, 0, 4))) . '.',
            !$relayed => 'The PCs have addresses, but the plan wants SRV1 to serve the students through a relay: ip helper-address ' . long2ip($server) . ' on R1’s student subinterface.',
            default => 'Every PC gets an address, mask and gateway for its own VLAN from SRV1; the student VLAN through R1’s relay.',
        }];

        $fails = [];
        foreach ($pcs as $pc) {
            $ping = $sim->ping($pc['id'], $server);
            if (!$ping['ok']) {
                $fails[] = $pc['name'] . ': ' . $ping['why'];
            }
        }
        $parts[] = ['label' => 'Every PC reaches the server', 'ok' => count($pcs) === 4 && $fails === [], 'marks' => 25, 'detail' => match (true) {
            count($pcs) !== 4 => 'Keep PC1 to PC4 in the network.',
            $fails === [] => 'Ping from every PC to ' . long2ip($server) . ' gets replies, across both VLANs.',
            default => ucfirst(implode('; ', array_slice($fails, 0, 4))) . '.',
        }];

        return LabResult::fromParts($parts, ['topology' => $net->toArray()]);
    }
}
