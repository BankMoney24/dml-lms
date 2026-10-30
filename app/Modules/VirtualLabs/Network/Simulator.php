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
 *     connected networks, static routes and OSPF routes; at the same prefix,
 *     connected beats static beats OSPF (administrative distance). TTL stops loops.
 *   - OSPF (ADR 0041), one link-state database for the whole network:
 *     a router interface runs OSPF when it is working and the first network
 *     statement that matches it ((address AND NOT wildcard) = network) gives its
 *     area. Two routers become neighbours across a segment when both interfaces
 *     run OSPF, are not passive, share the network and mask, and are in the same
 *     area. Every OSPF interface's network (passive ones too) is advertised. Each
 *     router finds the shortest path to every other by hops (every link costs 1),
 *     neighbours tried in interface order then device order; a network's metric
 *     is the path cost plus 1 for the advertising router's own interface; ties
 *     keep the first found. A router with "default-information originate" and a
 *     static default route advertises 0.0.0.0/0 (metric 1, external).
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

    /** @var array<string, list<array{port: string, ip: int, mask: int, area: int, passive: bool}>> router id → its OSPF interfaces */
    private array $ospfIfs = [];

    /** @var array<string, list<array{dev: string, port: string, ip: int, theirPort: string, area: int}>> router id → OSPF neighbours */
    private array $neighbours = [];

    /** @var array<string, list<array{net: int, mask: int, via: int, port: string, metric: int, external: bool}>> router id → routes learned by OSPF */
    private array $ospfRoutes = [];

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
        $this->runOspf();
        $this->leaseAll();
    }

    /** Works out OSPF interfaces, neighbours and learned routes (ADR 0041). */
    private function runOspf(): void
    {
        foreach ($this->net->devices as $d) {
            if ($d['type'] !== 'router' || $d['ospf'] === null) {
                continue;
            }
            $list = [];
            foreach ($this->interfaces($d) as $port => [$ip, $mask]) {
                foreach ($d['ospf']['networks'] as $n) {
                    if (($ip & ((~$n['wildcard']) & 0xFFFFFFFF)) === $n['net']) {
                        $list[] = ['port' => (string) $port, 'ip' => $ip, 'mask' => $mask, 'area' => $n['area'], 'passive' => in_array((string) $port, $d['ospf']['passive'], true)];
                        break;
                    }
                }
            }
            $this->ospfIfs[$d['id']] = $list;
        }
        foreach ($this->ospfIfs as $a => $mine) {
            $this->neighbours[$a] = [];
            foreach ($mine as $ia) {
                $seg = $ia['passive'] ? null : $this->segmentOf($a, $ia['port']);
                if ($seg === null) {
                    continue;
                }
                foreach ($this->ospfIfs as $b => $theirs) {
                    if ($b === $a) {
                        continue;
                    }
                    foreach ($theirs as $ib) {
                        if (
                            !$ib['passive'] && $ib['ip'] !== $ia['ip'] && $ib['mask'] === $ia['mask'] && $ib['area'] === $ia['area']
                            && ($ib['ip'] & $ib['mask']) === ($ia['ip'] & $ia['mask']) && $this->segmentOf($b, $ib['port']) === $seg
                        ) {
                            $this->neighbours[$a][] = ['dev' => $b, 'port' => $ia['port'], 'ip' => $ib['ip'], 'theirPort' => $ib['port'], 'area' => $ia['area']];
                        }
                    }
                }
            }
        }
        foreach (array_keys($this->ospfIfs) as $x) {
            $dist = [$x => 0];
            $first = [];
            $queue = [$x];
            while ($queue !== []) {
                $u = array_shift($queue);
                foreach ($this->neighbours[$u] as $nb) {
                    if (isset($dist[$nb['dev']])) {
                        continue;
                    }
                    $dist[$nb['dev']] = $dist[$u] + 1;
                    $first[$nb['dev']] = $u === $x ? ['via' => $nb['ip'], 'port' => $nb['port']] : $first[$u];
                    $queue[] = $nb['dev'];
                }
            }
            $connected = [];
            foreach ($this->interfaces($this->net->devices[$x]) as [$ip, $mask]) {
                $connected[($ip & $mask) . '/' . $mask] = true;
            }
            $best = [];
            foreach ($dist as $r => $hops) {
                if ($r === $x) {
                    continue;
                }
                foreach ($this->ospfIfs[$r] as $i) {
                    $key = ($i['ip'] & $i['mask']) . '/' . $i['mask'];
                    if (isset($connected[$key]) || (isset($best[$key]) && $best[$key]['metric'] <= $hops + 1)) {
                        continue;
                    }
                    $best[$key] = ['net' => $i['ip'] & $i['mask'], 'mask' => $i['mask'], 'via' => $first[$r]['via'], 'port' => $first[$r]['port'], 'metric' => $hops + 1, 'external' => false];
                }
                $rd = $this->net->devices[$r];
                if (!isset($best['0/0']) && ($rd['ospf']['default'] ?? false) && $this->hasStaticDefault($rd)) {
                    $best['0/0'] = ['net' => 0, 'mask' => 0, 'via' => $first[$r]['via'], 'port' => $first[$r]['port'], 'metric' => 1, 'external' => true];
                }
            }
            $this->ospfRoutes[$x] = array_values($best);
        }
    }

    /** @param Device $d */
    private function hasStaticDefault(array $d): bool
    {
        foreach ($d['routes'] as $r) {
            if ($r['mask'] === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A router's OSPF neighbours (empty when it does not run OSPF).
     *
     * @return list<array{dev: string, port: string, ip: int, theirPort: string, area: int}>
     */
    public function ospfNeighbours(string $id): array
    {
        return $this->neighbours[$id] ?? [];
    }

    /**
     * A router's interfaces that run OSPF.
     *
     * @return list<array{port: string, ip: int, mask: int, area: int, passive: bool}>
     */
    public function ospfInterfaces(string $id): array
    {
        return $this->ospfIfs[$id] ?? [];
    }

    /**
     * Routes a router learned by OSPF.
     *
     * @return list<array{net: int, mask: int, via: int, port: string, metric: int, external: bool}>
     */
    public function ospfRoutes(string $id): array
    {
        return $this->ospfRoutes[$id] ?? [];
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
        foreach ([...$dev['routes'], ...($this->ospfRoutes[$dev['id']] ?? [])] as $r) {
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
