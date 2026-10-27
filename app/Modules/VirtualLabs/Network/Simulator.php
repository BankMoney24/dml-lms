<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

/**
 * Works out what a built network does (ADR 0035). The same rules run in the
 * browser (public/assets/labs/network-core.js) for the learner's own tests;
 * marking always uses this copy.
 *
 * Model:
 *   - Cables join ports; every port of a switch is in one broadcast domain
 *     (no VLANs). A "segment" is everything joined that way.
 *   - A host or router interface takes part only with a valid address and mask;
 *     a router interface only when it is up ("no shutdown").
 *   - A host sends to its own network directly (ARP on its segment) and to
 *     anything else through its default gateway, which must answer on the segment.
 *   - A router delivers to itself, or forwards by longest prefix match over its
 *     connected networks and static routes; TTL stops loops.
 *   - A ping works only if the request arrives AND the reply gets back.
 */
/**
 * @phpstan-import-type Device from Topology
 */
final class Simulator
{
    /** @var array<string, string> node "dev:port" → parent, for union-find */
    private array $parent = [];

    /** @var array<string, list<array{dev: string, port: string, ip: int, mask: int}>> segment → L3 interfaces on it */
    private array $onSegment = [];

    /** @var array<string, string> "dev:port" → the device's segment, only for cabled ports */
    private array $cabled = [];

    public function __construct(public readonly Topology $net)
    {
        foreach ($net->links as [$a, $pa, $b, $pb]) {
            $this->union($a . ':' . $pa, $b . ':' . $pb);
            $this->cabled[$a . ':' . $pa] = $this->cabled[$b . ':' . $pb] = '';
        }
        foreach ($net->ofType('switch') as $sw) {
            $first = null;
            foreach (Topology::PORTS['switch'] as $port) {
                if (!isset($this->cabled[$sw['id'] . ':' . $port])) {
                    continue;
                }
                $first ??= $sw['id'] . ':' . $port;
                $this->union($first, $sw['id'] . ':' . $port);
            }
        }
        foreach (array_keys($this->cabled) as $node) {
            $this->cabled[$node] = $this->find($node);
        }
        foreach ($net->devices as $d) {
            foreach ($this->interfaces($d) as $port => [$ip, $mask]) {
                $node = $d['id'] . ':' . $port;
                if (isset($this->cabled[$node])) {
                    $this->onSegment[$this->cabled[$node]][] = ['dev' => $d['id'], 'port' => $port, 'ip' => $ip, 'mask' => $mask];
                }
            }
        }
    }

    /**
     * Working layer-3 interfaces of a device: port → [ip, mask].
     *
     * @param Device $d
     * @return array<string, array{0: int, 1: int}>
     */
    public function interfaces(array $d): array
    {
        if (in_array($d['type'], Topology::HOSTS, true)) {
            return $d['ip'] !== null && $d['mask'] !== null ? ['Fa0' => [$d['ip'], $d['mask']]] : [];
        }
        $out = [];
        foreach ($d['ifaces'] as $port => $i) {
            if ($i['up'] && $i['ip'] !== null && $i['mask'] !== null) {
                $out[$port] = [$i['ip'], $i['mask']];
            }
        }

        return $out;
    }

    /** The segment a port is cabled into, or null when nothing is plugged in. */
    public function segmentOf(string $dev, string $port): ?string
    {
        return $this->cabled[$dev . ':' . $port] ?? null;
    }

    /**
     * Device ids on the same segment as a port (any kind, including switches).
     *
     * @return list<string>
     */
    public function devicesWith(string $dev, string $port): array
    {
        $seg = $this->segmentOf($dev, $port);
        if ($seg === null) {
            return [];
        }
        $out = [];
        foreach ($this->cabled as $node => $s) {
            if ($s === $seg) {
                $out[explode(':', $node)[0]] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Sends a ping from a device to an address.
     *
     * @return array{ok: bool, why: string}
     */
    public function ping(string $fromId, int $dst): array
    {
        $from = $this->net->devices[$fromId] ?? null;
        if ($from === null) {
            return ['ok' => false, 'why' => 'no such device'];
        }
        $src = $this->sourceFor($from, $dst);
        if ($src === null) {
            return ['ok' => false, 'why' => $this->describe($from) . ' has no working address to send from'];
        }
        $there = $this->walk($fromId, $dst);
        if (!$there['ok']) {
            return ['ok' => false, 'why' => $there['why']];
        }
        $back = $this->walk($there['at'], $src);
        if (!$back['ok']) {
            return ['ok' => false, 'why' => 'the request arrives but the reply cannot get back: ' . $back['why']];
        }

        return ['ok' => true, 'why' => 'replies arrive'];
    }

    /**
     * Follows one packet. Returns where it was delivered or why it was lost.
     *
     * @return array{ok: bool, at: string, why: string}
     */
    public function walk(string $fromId, int $dst, int $ttl = 32): array
    {
        $dev = $this->net->devices[$fromId] ?? null;
        if ($dev === null) {
            return ['ok' => false, 'at' => '', 'why' => 'no such device'];
        }
        $target = long2ip($dst);
        for ($guard = 0; $guard < 64; $guard++) {
            foreach ($this->interfaces($dev) as [$ip]) {
                if ($ip === $dst) {
                    return ['ok' => true, 'at' => $dev['id'], 'why' => ''];
                }
            }
            if (in_array($dev['type'], Topology::HOSTS, true)) {
                if ($guard > 0) {
                    return ['ok' => false, 'at' => $dev['id'], 'why' => $this->describe($dev) . ' is not ' . $target . ' and does not forward packets'];
                }
                $step = $this->hostNextHop($dev, $dst);
            } else {
                if ($guard > 0 && --$ttl <= 0) {
                    return ['ok' => false, 'at' => $dev['id'], 'why' => 'the packet goes round in a loop (TTL expired at ' . $dev['name'] . ')'];
                }
                $step = $this->routerNextHop($dev, $dst);
            }
            if (!$step[0]) {
                return ['ok' => false, 'at' => $dev['id'], 'why' => $step[1]];
            }
            $dev = $this->net->devices[$step[1]];
        }

        return ['ok' => false, 'at' => $dev['id'], 'why' => 'the packet goes round in a loop'];
    }

    /**
     * @param Device $dev
     * @return array{0: bool, 1: string} [true, next device id] or [false, why the packet is dropped]
     */
    private function hostNextHop(array $dev, int $dst): array
    {
        if ($dev['ip'] === null || $dev['mask'] === null) {
            return [false, $this->describe($dev) . ' has no IP address and mask'];
        }
        $seg = $this->segmentOf($dev['id'], 'Fa0');
        if ($seg === null) {
            return [false, $this->describe($dev) . ' has no cable plugged in'];
        }
        if (($dst & $dev['mask']) === ($dev['ip'] & $dev['mask'])) {
            $owner = $this->arp($seg, $dst);

            return $owner !== null ? [true, $owner] : [false, long2ip($dst) . ' does not answer on ' . $dev['name'] . '’s network (nothing with that address is connected there)'];
        }
        if ($dev['gateway'] === null) {
            return [false, $this->describe($dev) . ' has no default gateway, so it cannot reach other networks'];
        }
        if (($dev['gateway'] & $dev['mask']) !== ($dev['ip'] & $dev['mask'])) {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($dev['gateway']) . ' is not on its own network'];
        }
        $gw = $this->arp($seg, $dev['gateway']);
        if ($gw === null) {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($dev['gateway']) . ' does not answer (no router interface with that address is up and connected)'];
        }
        if (($this->net->devices[$gw]['type'] ?? '') !== 'router') {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($dev['gateway']) . ' is not a router'];
        }

        return [true, $gw];
    }

    /**
     * @param Device $dev
     * @return array{0: bool, 1: string} [true, next device id] or [false, why the packet is dropped]
     */
    private function routerNextHop(array $dev, int $dst): array
    {
        $best = null;
        $bestPrefix = -1;
        foreach ($this->interfaces($dev) as $port => [$ip, $mask]) {
            $p = Topology::prefixOf($mask);
            if (($dst & $mask) === ($ip & $mask) && $p > $bestPrefix) {
                $best = ['connected', $port];
                $bestPrefix = $p;
            }
        }
        foreach ($dev['routes'] as $r) {
            $p = Topology::prefixOf($r['mask']);
            if (($dst & $r['mask']) === $r['net'] && $p > $bestPrefix) {
                $best = ['via', $r['via']];
                $bestPrefix = $p;
            }
        }
        if ($best === null) {
            return [false, $dev['name'] . ' has no route to ' . long2ip($dst)];
        }
        if ($best[0] === 'connected') {
            $seg = $this->segmentOf($dev['id'], (string) $best[1]);
            $owner = $seg === null ? null : $this->arp($seg, $dst);

            return $owner !== null ? [true, $owner] : [false, long2ip($dst) . ' does not answer on ' . $dev['name'] . ' ' . $best[1] . '’s network'];
        }
        $via = (int) $best[1];
        foreach ($this->interfaces($dev) as $port => [$ip, $mask]) {
            if (($via & $mask) === ($ip & $mask)) {
                $seg = $this->segmentOf($dev['id'], $port);
                $next = $seg === null ? null : $this->arp($seg, $via);
                if ($next === null) {
                    return [false, $dev['name'] . '’s next hop ' . long2ip($via) . ' does not answer'];
                }

                return [true, $next];
            }
        }

        return [false, $dev['name'] . '’s next hop ' . long2ip($via) . ' is not on any of its connected networks'];
    }

    /** Who answers for an address on a segment (the first, if two devices claim it). */
    private function arp(string $segment, int $ip): ?string
    {
        foreach ($this->onSegment[$segment] ?? [] as $i) {
            if ($i['ip'] === $ip) {
                return $i['dev'];
            }
        }

        return null;
    }

    /**
     * The address a device sends from towards dst (hosts: their one address; routers: the outgoing interface's).
     *
     * @param Device $dev
     */
    private function sourceFor(array $dev, int $dst): ?int
    {
        $ifs = $this->interfaces($dev);
        if ($ifs === []) {
            return null;
        }
        foreach ($ifs as [$ip, $mask]) {
            if (($dst & $mask) === ($ip & $mask)) {
                return $ip;
            }
        }

        return reset($ifs)[0];
    }

    /** @param Device $dev */
    private function describe(array $dev): string
    {
        return (string) $dev['name'];
    }

    private function find(string $x): string
    {
        $this->parent[$x] ??= $x;
        while ($this->parent[$x] !== $x) {
            $this->parent[$x] = $this->parent[$this->parent[$x]] ?? $this->parent[$x];
            $x = $this->parent[$x];
        }

        return $x;
    }

    private function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra !== $rb) {
            $this->parent[$ra] = $rb;
        }
    }

    /**
     * Addresses that two working interfaces share (a conflict), as "a.b.c.d".
     *
     * @return list<string>
     */
    public function duplicates(): array
    {
        $seen = [];
        $dupes = [];
        foreach ($this->net->devices as $d) {
            foreach ($this->interfaces($d) as [$ip]) {
                if (isset($seen[$ip])) {
                    $dupes[Topology::show($ip)] = true;
                }
                $seen[$ip] = true;
            }
        }

        return array_map('strval', array_keys($dupes));
    }
}
