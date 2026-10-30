<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

use App\Modules\VirtualLabs\Seed;

/**
 * The OSPF lab's network (ADR 0041): three sites, each with a LAN behind its
 * own router, the routers joined in a triangle of /30 links. Everything is
 * addressed and up; nothing is routed. The learner turns on OSPF so the
 * routers learn every network themselves, and the triangle means traffic
 * still flows when any one link fails.
 *
 *     PC1─SW1─Gi0/0 R1 Gi0/1 ──── Gi0/1 R2 Gi0/0─SW2─PC2
 *                   Gi0/2            Gi0/2
 *                      ╲              ╱
 *                     Gi0/1   R3   Gi0/2
 *                           Gi0/0─SW3─SRV1
 *
 * Different numbers for every session, all from the seed.
 */
final class OspfScenario
{
    /** Index (into start()['l']) of the three router-to-router cables. */
    public const CORE_LINKS = [6 => 'R1–R2', 7 => 'R2–R3', 8 => 'R1–R3'];

    public const SITES = [
        ['Head office', 'the Annex', 'the Workshop'],
        ['Main campus', 'the Hostel', 'the Library'],
        ['The Lagos branch', 'the Ibadan branch', 'the Abeokuta branch'],
        ['The admin block', 'the labs', 'the clinic'],
    ];

    /**
     * @param list<string> $siteNames
     * @param list<int> $lans  network address of LAN 1, 2, 3
     * @param array{12: int, 23: int, 13: int} $links  network address of each /30
     */
    private function __construct(
        public readonly array $siteNames,
        public readonly array $lans,
        public readonly array $links,
        public readonly int $pcHost,
    ) {
    }

    public static function from(Seed $seed): self
    {
        $b = $seed->int(16, 200);
        $thirds = array_slice($seed->shuffle([10, 20, 30, 40, 50, 60, 70, 80]), 0, 3);
        $lans = array_map(static fn (int $t): int => (10 << 24) | ($b << 16) | ($t << 8), array_map('intval', $thirds));
        $core = (10 << 24) | ($b << 16) | ($seed->int(200, 250) << 8);
        $start = $seed->pick([0, 16, 32, 64]);
        /** @var list<string> $sites */
        $sites = $seed->pick(self::SITES);

        return new self($sites, $lans, [12 => $core + $start, 23 => $core + $start + 4, 13 => $core + $start + 8], $seed->int(10, 99));
    }

    public function server(): int
    {
        return $this->lans[2] + $this->pcHost;
    }

    /**
     * Interface addresses: router id → port → [ip, mask].
     *
     * @return array<string, array<string, array{0: int, 1: int}>>
     */
    public function addresses(): array
    {
        $m24 = 0xFFFFFF00;
        $m30 = 0xFFFFFFFC;

        return [
            'r1' => ['Gi0/0' => [$this->lans[0] + 1, $m24], 'Gi0/1' => [$this->links[12] + 1, $m30], 'Gi0/2' => [$this->links[13] + 1, $m30]],
            'r2' => ['Gi0/0' => [$this->lans[1] + 1, $m24], 'Gi0/1' => [$this->links[12] + 2, $m30], 'Gi0/2' => [$this->links[23] + 1, $m30]],
            'r3' => ['Gi0/0' => [$this->lans[2] + 1, $m24], 'Gi0/1' => [$this->links[13] + 2, $m30], 'Gi0/2' => [$this->links[23] + 2, $m30]],
        ];
    }

    /**
     * The plan the learner is given.
     *
     * @return list<array{device: string, port: string, address: string, mask: string, gateway: string}>
     */
    public function plan(): array
    {
        $rows = [];
        $names = ['r1' => 'R1', 'r2' => 'R2', 'r3' => 'R3'];
        foreach ($this->addresses() as $id => $ports) {
            foreach ($ports as $port => [$ip, $mask]) {
                $rows[] = [
                    'device' => $names[$id],
                    'port' => $port . ($port === 'Gi0/0' ? ' (LAN)' : ''),
                    'address' => Topology::show($ip),
                    'mask' => Topology::show($mask),
                    'gateway' => $port === 'Gi0/0' ? 'Passive' : 'OSPF area 0',
                ];
            }
        }
        $rows[] = ['device' => 'PC1, PC2, SRV1', 'port' => 'Fa0', 'address' => 'Already set', 'mask' => '255.255.255.0', 'gateway' => 'Their router’s LAN address'];

        return $rows;
    }

    /** @return array<string, mixed> what the browser shows */
    public function publicView(): array
    {
        return [
            'mode' => 'ospf',
            'sites' => $this->siteNames,
            'server' => Topology::show($this->server()),
            'lans' => array_map(static fn (int $n): string => Topology::show($n) . '/24', $this->lans),
            'plan' => $this->plan(),
            'planHead' => ['Router', 'Interface', 'Address', 'Mask', 'OSPF'],
            'start' => $this->start(),
        ];
    }

    /**
     * Placed, cabled and addressed; no routing at all.
     *
     * @return array{d: list<array<string, mixed>>, l: list<list<string>>}
     */
    public function start(): array
    {
        $a = $this->addresses();
        $if = static fn (string $id): array => array_map(
            static fn (array $x): array => ['ip' => Topology::show($x[0]), 'm' => Topology::show($x[1]), 'u' => 1],
            $a[$id],
        );
        $m24 = '255.255.255.0';
        $d = [
            ['i' => 'p1', 't' => 'pc', 'n' => 'PC1', 'x' => 55, 'y' => 170, 'ip' => Topology::show($this->lans[0] + $this->pcHost), 'm' => $m24, 'g' => Topology::show($this->lans[0] + 1)],
            ['i' => 's1', 't' => 'switch', 'n' => 'SW1', 'x' => 175, 'y' => 170],
            ['i' => 'r1', 't' => 'router', 'n' => 'R1', 'x' => 300, 'y' => 170, 'if' => $if('r1'), 'rt' => []],
            ['i' => 'r2', 't' => 'router', 'n' => 'R2', 'x' => 660, 'y' => 170, 'if' => $if('r2'), 'rt' => []],
            ['i' => 's2', 't' => 'switch', 'n' => 'SW2', 'x' => 785, 'y' => 170],
            ['i' => 'p2', 't' => 'pc', 'n' => 'PC2', 'x' => 905, 'y' => 170, 'ip' => Topology::show($this->lans[1] + $this->pcHost), 'm' => $m24, 'g' => Topology::show($this->lans[1] + 1)],
            ['i' => 'r3', 't' => 'router', 'n' => 'R3', 'x' => 480, 'y' => 400, 'if' => $if('r3'), 'rt' => []],
            ['i' => 's3', 't' => 'switch', 'n' => 'SW3', 'x' => 660, 'y' => 455],
            ['i' => 'v1', 't' => 'server', 'n' => 'SRV1', 'x' => 860, 'y' => 455, 'ip' => Topology::show($this->server()), 'm' => $m24, 'g' => Topology::show($this->lans[2] + 1)],
        ];
        $l = [
            ['p1', 'Fa0', 's1', 'Fa0/1'], ['s1', 'Gi0/1', 'r1', 'Gi0/0'],
            ['p2', 'Fa0', 's2', 'Fa0/1'], ['s2', 'Gi0/1', 'r2', 'Gi0/0'],
            ['v1', 'Fa0', 's3', 'Fa0/1'], ['s3', 'Gi0/1', 'r3', 'Gi0/0'],
            ['r1', 'Gi0/1', 'r2', 'Gi0/1'], ['r2', 'Gi0/2', 'r3', 'Gi0/2'], ['r1', 'Gi0/2', 'r3', 'Gi0/1'],
        ];

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
        foreach ($net['d'] as $i => $d) {
            if ($d['t'] !== 'router') {
                continue;
            }
            $lan = $this->lans[(int) substr((string) $d['i'], 1) - 1];
            $net['d'][$i]['os'] = [
                'n' => [[Topology::show($lan), '0.0.0.255', 0], [Topology::show($this->links[12] & 0xFFFFFFF0), '0.0.0.15', 0]],
                'pi' => ['Gi0/0'],
            ];
        }

        return $net;
    }
}
