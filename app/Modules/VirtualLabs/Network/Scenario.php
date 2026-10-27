<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

use App\Modules\VirtualLabs\Seed;

/**
 * The addressing plan shared by the network builder labs (ADR 0035):
 *
 *   PCs ─ SW1 ─ R1 ═══ R2 ─ SRV1
 *   LAN A /24   link /30   LAN B /24
 *
 * Different numbers for every session, all from the seed.
 */
final class Scenario
{
    public const SITES = ['the admin block', 'the library', 'the science block', 'the front office', 'the computer room', 'the staff room'];

    /** @param array<string, int> $ip */
    private function __construct(
        public readonly string $site,
        public readonly int $lanA,
        public readonly int $lanB,
        public readonly int $link,
        public readonly array $ip,
    ) {
    }

    public static function from(Seed $seed): self
    {
        $lanA = (192 << 24) | (168 << 16) | ($seed->int(1, 250) << 8);
        $lanB = (10 << 24) | ($seed->int(1, 250) << 16) | ($seed->int(0, 250) << 8);
        $link = (172 << 24) | ($seed->int(16, 31) << 16) | ($seed->int(0, 250) << 8) | ($seed->int(0, 63) * 4);

        return new self($seed->pick(self::SITES), $lanA, $lanB, $link, [
            'r1_lan' => $lanA + 1,
            'r1_link' => $link + 1,
            'r2_link' => $link + 2,
            'r2_lan' => $lanB + 1,
            'server' => $lanB + $seed->int(10, 250),
            'pc_from' => $lanA + 10,
            'pc_to' => $lanA + 200,
        ]);
    }

    public static function cidr(int $net, int $prefix): string
    {
        return long2ip($net) . '/' . $prefix;
    }

    /**
     * The plan the learner is given.
     *
     * @return list<array{device: string, port: string, address: string, mask: string, gateway: string}>
     */
    public function plan(bool $pcRange = true): array
    {
        $m24 = '255.255.255.0';
        $m30 = '255.255.255.252';
        $pcs = $pcRange
            ? 'Any free address from ' . Topology::show($this->ip['pc_from']) . ' to ' . Topology::show($this->ip['pc_to'])
            : 'A free address on ' . self::cidr($this->lanA, 24);

        return [
            ['device' => 'R1', 'port' => 'Gi0/0 (to the switch)', 'address' => Topology::show($this->ip['r1_lan']), 'mask' => $m24, 'gateway' => ''],
            ['device' => 'R1', 'port' => 'Gi0/1 (to R2)', 'address' => Topology::show($this->ip['r1_link']), 'mask' => $m30, 'gateway' => ''],
            ['device' => 'R2', 'port' => 'Gi0/0 (to R1)', 'address' => Topology::show($this->ip['r2_link']), 'mask' => $m30, 'gateway' => ''],
            ['device' => 'R2', 'port' => 'Gi0/1 (to the server)', 'address' => Topology::show($this->ip['r2_lan']), 'mask' => $m24, 'gateway' => ''],
            ['device' => 'SRV1', 'port' => 'Fa0', 'address' => Topology::show($this->ip['server']), 'mask' => $m24, 'gateway' => Topology::show($this->ip['r2_lan'])],
            ['device' => 'PCs', 'port' => 'Fa0', 'address' => $pcs, 'mask' => $m24, 'gateway' => Topology::show($this->ip['r1_lan'])],
        ];
    }

    /** @return array<string, mixed> what the browser shows */
    public function publicView(): array
    {
        return [
            'site' => $this->site,
            'lanA' => self::cidr($this->lanA, 24),
            'lanB' => self::cidr($this->lanB, 24),
            'link' => self::cidr($this->link, 30),
            'server' => long2ip($this->ip['server']),
            'plan' => $this->plan(),
        ];
    }

    /**
     * A finished, working network laid out on the canvas, in the compact JSON shape.
     *
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public function working(int $pcs, int $seedForHosts): array
    {
        $m24 = '255.255.255.0';
        $m30 = '255.255.255.252';
        $devices = [];
        $links = [];
        for ($i = 1; $i <= $pcs; $i++) {
            $devices[] = [
                'i' => 'p' . $i, 't' => $i === $pcs && $pcs > 2 ? 'laptop' : 'pc', 'n' => 'PC' . $i, 'x' => 110, 'y' => ($pcs > 2 ? 100 : 170) + ($i - 1) * ($pcs > 2 ? 160 : 180),
                'ip' => long2ip($this->lanA + 10 + (($seedForHosts + $i * 7) % 180)), 'm' => $m24, 'g' => long2ip($this->ip['r1_lan']),
            ];
            $links[] = ['p' . $i, 'Fa0', 's1', 'Fa0/' . $i];
        }
        $devices[] = ['i' => 's1', 't' => 'switch', 'n' => 'SW1', 'x' => 300, 'y' => 260];
        $devices[] = [
            'i' => 'r1', 't' => 'router', 'n' => 'R1', 'x' => 480, 'y' => 260,
            'if' => [
                'Gi0/0' => ['ip' => long2ip($this->ip['r1_lan']), 'm' => $m24, 'u' => 1],
                'Gi0/1' => ['ip' => long2ip($this->ip['r1_link']), 'm' => $m30, 'u' => 1],
                'Gi0/2' => ['ip' => '', 'm' => '', 'u' => 0],
            ],
            'rt' => [[long2ip($this->lanB), $m24, long2ip($this->ip['r2_link'])]],
        ];
        $devices[] = [
            'i' => 'r2', 't' => 'router', 'n' => 'R2', 'x' => 670, 'y' => 260,
            'if' => [
                'Gi0/0' => ['ip' => long2ip($this->ip['r2_link']), 'm' => $m30, 'u' => 1],
                'Gi0/1' => ['ip' => long2ip($this->ip['r2_lan']), 'm' => $m24, 'u' => 1],
                'Gi0/2' => ['ip' => '', 'm' => '', 'u' => 0],
            ],
            'rt' => [[long2ip($this->lanA), $m24, long2ip($this->ip['r1_link'])]],
        ];
        $devices[] = ['i' => 'v1', 't' => 'server', 'n' => 'SRV1', 'x' => 860, 'y' => 260, 'ip' => long2ip($this->ip['server']), 'm' => $m24, 'g' => long2ip($this->ip['r2_lan'])];
        $links[] = ['s1', 'Gi0/1', 'r1', 'Gi0/0'];
        $links[] = ['r1', 'Gi0/1', 'r2', 'Gi0/0'];
        $links[] = ['r2', 'Gi0/1', 'v1', 'Fa0'];

        return ['d' => $devices, 'l' => $links];
    }
}
