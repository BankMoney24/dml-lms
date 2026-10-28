<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

use App\Modules\VirtualLabs\Seed;

/**
 * The VLAN and DHCP lab's plan (ADR 0038): one switch shared by staff and
 * students, kept apart by two VLANs, with a router on a stick between them and
 * one DHCP server in the staff VLAN that also serves the students through a
 * relay (ip helper-address).
 *
 *   PC1 PC2 SRV1 ─┐ VLAN staff              ┌ Gi0/0.<staff>   (gateway)
 *                 SW1 ═ trunk Gi0/1 ═══ Gi0/0 R1
 *   PC3 PC4 ─────┘ VLAN students            └ Gi0/0.<students> (gateway, helper → SRV1)
 *
 * Different numbers for every session, all from the seed.
 */
final class VlanScenario
{
    public const SITES = ['the new computer lab', 'the library annexe', 'the science block', 'the training centre', 'the hostel study room'];

    private function __construct(
        public readonly string $site,
        public readonly int $staffVlan,
        public readonly int $studentVlan,
        public readonly int $staffNet,
        public readonly int $studentNet,
        public readonly int $server,
    ) {
    }

    public static function from(Seed $seed): self
    {
        [$staff, $students] = array_slice($seed->shuffle([10, 20, 30, 40, 50, 60]), 0, 2);
        $b = $seed->int(16, 31);
        $staffNet = (172 << 24) | ($b << 16) | ($staff << 8);
        $studentNet = (172 << 24) | ($b << 16) | ($students << 8);

        return new self($seed->pick(self::SITES), (int) $staff, (int) $students, $staffNet, $studentNet, $staffNet + $seed->int(200, 250));
    }

    public function staffGateway(): int
    {
        return $this->staffNet + 1;
    }

    public function studentGateway(): int
    {
        return $this->studentNet + 1;
    }

    /**
     * The plan the learner is given.
     *
     * @return list<array{device: string, port: string, address: string, mask: string, gateway: string}>
     */
    public function plan(): array
    {
        $m24 = '255.255.255.0';
        $gw = Topology::show($this->staffGateway());
        $sgw = Topology::show($this->studentGateway());
        $srv = Topology::show($this->server);

        return [
            ['device' => 'SW1', 'port' => 'Fa0/1, Fa0/2, Fa0/5', 'address' => 'Access, VLAN ' . $this->staffVlan . ' (Staff)', 'mask' => '', 'gateway' => ''],
            ['device' => 'SW1', 'port' => 'Fa0/3, Fa0/4', 'address' => 'Access, VLAN ' . $this->studentVlan . ' (Students)', 'mask' => '', 'gateway' => ''],
            ['device' => 'SW1', 'port' => 'Gi0/1 (to R1)', 'address' => 'Trunk', 'mask' => '', 'gateway' => ''],
            ['device' => 'R1', 'port' => 'Gi0/0.' . $this->staffVlan . ' (VLAN ' . $this->staffVlan . ')', 'address' => $gw, 'mask' => $m24, 'gateway' => ''],
            ['device' => 'R1', 'port' => 'Gi0/0.' . $this->studentVlan . ' (VLAN ' . $this->studentVlan . ')', 'address' => $sgw, 'mask' => $m24, 'gateway' => 'Relay DHCP to ' . $srv],
            ['device' => 'SRV1', 'port' => 'Fa0', 'address' => $srv, 'mask' => $m24, 'gateway' => $gw],
            ['device' => 'SRV1', 'port' => 'DHCP', 'address' => 'A pool for each VLAN, from .100', 'mask' => $m24, 'gateway' => 'The VLAN’s router address'],
            ['device' => 'PCs', 'port' => 'Fa0', 'address' => 'DHCP', 'mask' => '', 'gateway' => ''],
        ];
    }

    /** @return array<string, mixed> what the browser shows */
    public function publicView(): array
    {
        return [
            'mode' => 'vlan',
            'site' => $this->site,
            'server' => long2ip($this->server),
            'staffVlan' => $this->staffVlan,
            'studentVlan' => $this->studentVlan,
            'staffNet' => long2ip($this->staffNet) . '/24',
            'studentNet' => long2ip($this->studentNet) . '/24',
            'plan' => $this->plan(),
            'planHead' => ['Device', 'Port or interface', 'Setting or address', 'Mask', 'Gateway or note'],
            'start' => $this->start(),
        ];
    }

    /**
     * The devices, placed and cabled, with nothing configured but the server's address.
     *
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public function start(): array
    {
        $empty = ['ip' => '', 'm' => '', 'u' => 0];
        $d = [
            ['i' => 'p1', 't' => 'pc', 'n' => 'PC1', 'x' => 110, 'y' => 90, 'ip' => '', 'm' => '', 'g' => ''],
            ['i' => 'p2', 't' => 'pc', 'n' => 'PC2', 'x' => 110, 'y' => 230, 'ip' => '', 'm' => '', 'g' => ''],
            ['i' => 'p3', 't' => 'pc', 'n' => 'PC3', 'x' => 110, 'y' => 370, 'ip' => '', 'm' => '', 'g' => ''],
            ['i' => 'p4', 't' => 'laptop', 'n' => 'PC4', 'x' => 330, 'y' => 440, 'ip' => '', 'm' => '', 'g' => ''],
            ['i' => 's1', 't' => 'switch', 'n' => 'SW1', 'x' => 400, 'y' => 250],
            ['i' => 'r1', 't' => 'router', 'n' => 'R1', 'x' => 680, 'y' => 250, 'if' => ['Gi0/0' => $empty, 'Gi0/1' => $empty, 'Gi0/2' => $empty], 'rt' => []],
            ['i' => 'v1', 't' => 'server', 'n' => 'SRV1', 'x' => 400, 'y' => 70, 'ip' => long2ip($this->server), 'm' => '255.255.255.0', 'g' => long2ip($this->staffGateway())],
        ];
        $l = [['p1', 'Fa0', 's1', 'Fa0/1'], ['p2', 'Fa0', 's1', 'Fa0/2'], ['p3', 'Fa0', 's1', 'Fa0/3'], ['p4', 'Fa0', 's1', 'Fa0/4'], ['v1', 'Fa0', 's1', 'Fa0/5'], ['s1', 'Gi0/1', 'r1', 'Gi0/0']];

        return ['d' => $d, 'l' => $l];
    }

    /**
     * The finished network, in the compact JSON shape (for tests and examples).
     *
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public function working(): array
    {
        $net = $this->start();
        $m24 = '255.255.255.0';
        foreach ($net['d'] as $i => $d) {
            if (in_array($d['i'], ['p1', 'p2', 'p3', 'p4'], true)) {
                $net['d'][$i]['dh'] = 1;
            }
            if ($d['i'] === 's1') {
                $net['d'][$i]['sp'] = [
                    'Fa0/1' => ['a', $this->staffVlan], 'Fa0/2' => ['a', $this->staffVlan], 'Fa0/5' => ['a', $this->staffVlan],
                    'Fa0/3' => ['a', $this->studentVlan], 'Fa0/4' => ['a', $this->studentVlan], 'Gi0/1' => ['t', 1],
                ];
                $net['d'][$i]['vl'] = [(string) $this->staffVlan => 'Staff', (string) $this->studentVlan => 'Students'];
            }
            if ($d['i'] === 'r1') {
                $net['d'][$i]['if']['Gi0/0']['u'] = 1;
                $net['d'][$i]['si'] = [
                    'Gi0/0.' . $this->staffVlan => ['ip' => long2ip($this->staffGateway()), 'm' => $m24, 'v' => $this->staffVlan],
                    'Gi0/0.' . $this->studentVlan => ['ip' => long2ip($this->studentGateway()), 'm' => $m24, 'v' => $this->studentVlan, 'h' => long2ip($this->server)],
                ];
            }
            if ($d['i'] === 'v1') {
                $net['d'][$i]['pl'] = [
                    ['n' => 'Staff', 's' => long2ip($this->staffNet + 100), 'm' => $m24, 'g' => long2ip($this->staffGateway()), 'c' => 50],
                    ['n' => 'Students', 's' => long2ip($this->studentNet + 100), 'm' => $m24, 'g' => long2ip($this->studentGateway()), 'c' => 100],
                ];
            }
        }

        return $net;
    }
}
