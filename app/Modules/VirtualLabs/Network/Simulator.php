<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

/**
 * Works out what a built network does (ADR 0035, VLANs and DHCP in ADR 0038).
 * The same rules run in the browser (public/assets/labs/network-builder.js) for
 * the learner's own tests; marking always uses this copy.
 *
 * Model:
 *   - Cables join ports. Inside a switch, ports in the same VLAN are one
 *     broadcast domain. An access port carries its VLAN untagged; a trunk carries
 *     its native VLAN untagged and every other VLAN tagged. Hosts and router
 *     ports send untagged frames; a router subinterface (Gi0/0.10) sends frames
 *     tagged with its VLAN. Two ends of a cable meet where they carry the same
 *     kind of frame. A "segment" is everything joined that way.
 *   - A host or router interface takes part only with a valid address and mask;
 *     a router interface (and its subinterfaces) only when it is up.
 *   - DHCP: a host set to DHCP asks on its segment. A server or router with an
 *     interface there and a pool for that network answers; otherwise a router
 *     interface there with a helper address relays to the server at that address
 *     (if the router can reach it and the server can answer back), which uses its
 *     pool for the router interface's network. Leases go in device order, skipping
 *     addresses already in use and the router's excluded ranges.
 *   - A host sends to its own network directly (ARP on its segment) and to
 *     anything else through its default gateway, which must answer on the segment.
 *   - A router delivers to itself, or forwards by longest prefix match over its
 *     connected networks and static routes; TTL stops loops.
 *   - A ping works only if the request arrives AND the reply gets back.
 */
/**
 * @phpstan-import-type Device from Topology
 * @phpstan-import-type Pool from Topology
 */
final class Simulator
{
    /** @var array<string, string> node → parent, for union-find. Nodes: "dev:port" (hosts, router ports and subinterfaces) and "dev#vlan" (switches). */
    private array $parent = [];

    /** @var array<string, list<array{dev: string, port: string, ip: int, mask: int}>> segment → L3 interfaces on it */
    private array $onSegment = [];

    /** @var array<string, string> node → its segment, only for nodes behind a cabled port */
    private array $cabled = [];

    /** @var array<string, array{ip: int, mask: int, gateway: ?int, server: string}|string> host id → its DHCP lease, or why it has none */
    private array $leases = [];

    public function __construct(public readonly Topology $net)
    {
        $vlans = [1 => true];
        foreach ($net->devices as $d) {
            foreach ($d['ports'] as $cfg) {
                $vlans[$cfg['vlan']] = true;
            }
            foreach ($d['subs'] as $sub) {
                $vlans[$sub['vlan']] = true;
            }
        }
        $vlanIds = array_keys($vlans);
        sort($vlanIds);
        $nodes = [];
        foreach ($net->links as [$a, $pa, $b, $pb]) {
            $ea = $this->ends($a, $pa, $vlanIds);
            $eb = $this->ends($b, $pb, $vlanIds);
            foreach ([...array_values($ea), ...array_values($eb)] as $node) {
                $nodes[$node] = true;
                $this->find($node);
            }
            foreach ($ea as $kind => $node) {
                if (isset($eb[$kind])) {
                    $this->union($node, $eb[$kind]);
                }
            }
        }
        foreach (array_keys($nodes) as $node) {
            $this->cabled[$node] = $this->find($node);
        }
        foreach ($net->devices as $d) {
            if ($d['dhcp']) {
                continue;
            }
            foreach ($this->interfaces($d) as $port => [$ip, $mask]) {
                if (isset($this->cabled[$d['id'] . ':' . $port])) {
                    $this->onSegment[$this->cabled[$d['id'] . ':' . $port]][] = ['dev' => $d['id'], 'port' => $port, 'ip' => $ip, 'mask' => $mask];
                }
            }
        }
        $this->leaseAll();
    }

    /**
     * What one end of a cable carries: frame kind ("u" untagged, "t10" tagged VLAN 10) → node.
     *
     * @param list<int> $vlanIds
     * @return array<string, string>
     */
    private function ends(string $id, string $port, array $vlanIds): array
    {
        $d = $this->net->devices[$id];
        if ($d['type'] === 'switch') {
            $cfg = $d['ports'][$port] ?? ['trunk' => false, 'vlan' => 1];
            if (!$cfg['trunk']) {
                return ['u' => $id . '#' . $cfg['vlan']];
            }
            $out = ['u' => $id . '#' . $cfg['vlan']];
            foreach ($vlanIds as $v) {
                if ($v !== $cfg['vlan']) {
                    $out['t' . $v] = $id . '#' . $v;
                }
            }

            return $out;
        }
        $out = ['u' => $id . ':' . $port];
        foreach ($d['subs'] as $name => $sub) {
            if ($sub['parent'] === $port) {
                $out['t' . $sub['vlan']] ??= $id . ':' . $name;
            }
        }

        return $out;
    }

    /** Hands out DHCP leases, in device order. */
    private function leaseAll(): void
    {
        $used = [];
        foreach ($this->net->devices as $d) {
            if ($d['dhcp']) {
                continue;
            }
            foreach ($this->interfaces($d) as [$ip]) {
                $used[$ip] = true;
            }
        }
        foreach ($this->net->devices as $d) {
            if (!$d['dhcp']) {
                continue;
            }
            $seg = $this->segmentOf($d['id'], 'Fa0');
            if ($seg === null) {
                $this->leases[$d['id']] = 'it has no cable plugged in, so its DHCP request goes nowhere';
                continue;
            }
            $found = $this->dhcpServer($seg);
            if (is_string($found)) {
                $this->leases[$d['id']] = $found;
                continue;
            }
            [$server, $pool] = $found;
            $excluded = $this->net->devices[$server]['excluded'];
            $lease = null;
            for ($a = $pool['start'], $last = $pool['start'] + $pool['count'] - 1; $a <= $last; $a++) {
                if (isset($used[$a]) || !Topology::isHostAddress($a, $pool['mask']) || self::inRanges($a, $excluded)) {
                    continue;
                }
                $lease = $a;
                break;
            }
            if ($lease === null) {
                $this->leases[$d['id']] = 'the DHCP pool ' . $pool['name'] . ' on ' . $this->net->devices[$server]['name'] . ' has no free addresses left';
                continue;
            }
            $used[$lease] = true;
            $this->leases[$d['id']] = ['ip' => $lease, 'mask' => $pool['mask'], 'gateway' => $pool['gateway'], 'server' => $server];
            $this->onSegment[$seg][] = ['dev' => $d['id'], 'port' => 'Fa0', 'ip' => $lease, 'mask' => $pool['mask']];
        }
    }

    /**
     * Who answers a DHCP request on a segment: [server id, pool], or why nobody does.
     *
     * @return array{0: string, 1: Pool}|string
     */
    private function dhcpServer(string $seg): array|string
    {
        $here = $this->onSegment[$seg] ?? [];
        foreach ($here as $i) {
            foreach ($this->net->devices[$i['dev']]['pools'] as $pool) {
                if (($i['ip'] & $pool['mask']) === ($pool['start'] & $pool['mask'])) {
                    return [$i['dev'], $pool];
                }
            }
        }
        $why = 'no DHCP server answers on its network';
        foreach ($here as $i) {
            $router = $this->net->devices[$i['dev']];
            $helper = $router['type'] === 'router' ? $this->helperOf($router, $i['port']) : null;
            if ($helper === null) {
                continue;
            }
            $there = $this->walk($router['id'], $helper);
            if (!$there['ok']) {
                $why = 'the DHCP request is relayed by ' . $router['name'] . ' but cannot reach ' . long2ip($helper) . ': ' . $there['why'];
                continue;
            }
            if (!$this->walk($there['at'], $i['ip'])['ok']) {
                $why = 'the DHCP server at ' . long2ip($helper) . ' cannot send its answer back to ' . $router['name'];
                continue;
            }
            foreach ($this->net->devices[$there['at']]['pools'] as $pool) {
                if (($i['ip'] & $pool['mask']) === ($pool['start'] & $pool['mask'])) {
                    return [$there['at'], $pool];
                }
            }
            $why = 'the DHCP server at ' . long2ip($helper) . ' has no pool for ' . long2ip($i['ip'] & $i['mask']);
        }

        return $why;
    }

    /** @param Device $router */
    private function helperOf(array $router, string $port): ?int
    {
        return $router['subs'][$port]['helper'] ?? ($router['ifaces'][$port]['helper'] ?? null);
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private static function inRanges(int $ip, array $ranges): bool
    {
        foreach ($ranges as [$lo, $hi]) {
            if ($ip >= $lo && $ip <= $hi) {
                return true;
            }
        }

        return false;
    }

    /**
     * A host's working address, mask and gateway: its own, or its DHCP lease.
     *
     * @param Device $d
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    public function addressing(array $d): array
    {
        if (!$d['dhcp']) {
            return [$d['ip'], $d['mask'], $d['gateway']];
        }
        $lease = $this->leases[$d['id']] ?? null;

        return is_array($lease) ? [$lease['ip'], $lease['mask'], $lease['gateway']] : [null, null, null];
    }

    /**
     * The DHCP lease a host got, or why it has none (null for a host with fixed settings).
     *
     * @return array{ip: int, mask: int, gateway: ?int, server: string}|string|null
     */
    public function lease(string $hostId): array|string|null
    {
        return $this->leases[$hostId] ?? null;
    }

    /**
     * Working layer-3 interfaces of a device: port (or subinterface) → [ip, mask].
     *
     * @param Device $d
     * @return array<string, array{0: int, 1: int}>
     */
    public function interfaces(array $d): array
    {
        if (in_array($d['type'], Topology::HOSTS, true)) {
            [$ip, $mask] = $this->addressing($d);

            return $ip !== null && $mask !== null ? ['Fa0' => [$ip, $mask]] : [];
        }
        $out = [];
        foreach ($d['ifaces'] as $port => $i) {
            if ($i['up'] && $i['ip'] !== null && $i['mask'] !== null) {
                $out[$port] = [$i['ip'], $i['mask']];
            }
        }
        foreach ($d['subs'] as $name => $sub) {
            if (($d['ifaces'][$sub['parent']]['up'] ?? false) && $sub['ip'] !== null && $sub['mask'] !== null) {
                $out[$name] = [$sub['ip'], $sub['mask']];
            }
        }

        return $out;
    }

    /** The segment a port (or subinterface) is in, or null when nothing is plugged in. */
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
                $out[(string) preg_replace('/[:#].*$/', '', $node)] = true;
            }
        }

        return array_map('strval', array_keys($out));
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
            $lease = $this->leases[$fromId] ?? null;

            return ['ok' => false, 'why' => is_string($lease)
                ? $from['name'] . ' got no address from DHCP: ' . $lease
                : $from['name'] . ' has no working address to send from'];
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
        [$ip, $mask, $gateway] = $this->addressing($dev);
        if ($ip === null || $mask === null) {
            return [false, $this->describe($dev) . ' has no IP address and mask'];
        }
        $seg = $this->segmentOf($dev['id'], 'Fa0');
        if ($seg === null) {
            return [false, $this->describe($dev) . ' has no cable plugged in'];
        }
        if (($dst & $mask) === ($ip & $mask)) {
            $owner = $this->arp($seg, $dst);

            return $owner !== null ? [true, $owner] : [false, long2ip($dst) . ' does not answer on ' . $dev['name'] . '’s network (nothing with that address is connected there)'];
        }
        if ($gateway === null) {
            return [false, $this->describe($dev) . ' has no default gateway, so it cannot reach other networks'];
        }
        if (($gateway & $mask) !== ($ip & $mask)) {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($gateway) . ' is not on its own network'];
        }
        $gw = $this->arp($seg, $gateway);
        if ($gw === null) {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($gateway) . ' does not answer (no router interface with that address is up and connected)'];
        }
        if (($this->net->devices[$gw]['type'] ?? '') !== 'router') {
            return [false, $this->describe($dev) . '’s default gateway ' . long2ip($gateway) . ' is not a router'];
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
                $best = ['connected', (string) $port];
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
                $seg = $this->segmentOf($dev['id'], (string) $port);
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
