<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Network;

/**
 * @phpstan-type Device array{id: string, type: string, name: string, x: int, y: int, ip: ?int, mask: ?int, gateway: ?int,
 *   ifaces: array<string, array{ip: ?int, mask: ?int, up: bool}>, routes: list<array{net: int, mask: int, via: int}>}
 *
 * A network a learner built in the network builder (ADR 0035), read from the
 * compact JSON the browser sends. Everything is checked and limited here: the
 * browser's own verdict ("ping worked") is never trusted; Simulator works it
 * out again from this.
 *
 * JSON: {"d": [device…], "l": [[deviceId, port, deviceId, port]…]}
 *   host:   {"i": id, "t": "pc"|"laptop"|"server", "n": name, "x": 0, "y": 0, "ip": "", "m": "", "g": ""}
 *   switch: {"i": id, "t": "switch", "n": name, "x", "y"}
 *   router: {"i": id, "t": "router", "n": name, "x", "y", "if": {port: {"ip", "m", "u": 0|1}}, "rt": [[network, mask, next hop]…]}
 */
final class Topology
{
    public const MAX_DEVICES = 30;
    public const MAX_LINKS = 60;
    public const MAX_ROUTES = 12;

    /** Ports each kind of device has, in order. */
    public const PORTS = [
        'pc' => ['Fa0'],
        'laptop' => ['Fa0'],
        'server' => ['Fa0'],
        'switch' => ['Fa0/1', 'Fa0/2', 'Fa0/3', 'Fa0/4', 'Fa0/5', 'Fa0/6', 'Fa0/7', 'Fa0/8', 'Gi0/1', 'Gi0/2'],
        'router' => ['Gi0/0', 'Gi0/1', 'Gi0/2'],
    ];

    public const HOSTS = ['pc', 'laptop', 'server'];

    /**
     * @param array<string, Device> $devices
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $links
     */
    private function __construct(public readonly array $devices, public readonly array $links)
    {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 40000) {
            return self::empty();
        }
        $data = json_decode($json, true);

        return is_array($data) ? self::fromArray($data) : self::empty();
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $devices = [];
        $names = [];
        foreach (array_slice(is_array($data['d'] ?? null) ? $data['d'] : [], 0, self::MAX_DEVICES) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $id = self::str($raw['i'] ?? '', 12);
            $type = self::str($raw['t'] ?? '', 10);
            if (preg_match('/^[a-z0-9]{1,12}$/', $id) !== 1 || !isset(self::PORTS[$type]) || isset($devices[$id])) {
                continue;
            }
            $name = self::str($raw['n'] ?? '', 20);
            $name = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $name) === 1 && !isset($names[strtolower($name)]) ? $name : strtoupper($id);
            $names[strtolower($name)] = true;
            $ifaces = [];
            $routes = [];
            if ($type === 'router') {
                $rawIf = is_array($raw['if'] ?? null) ? $raw['if'] : [];
                foreach (self::PORTS['router'] as $port) {
                    $cfg = is_array($rawIf[$port] ?? null) ? $rawIf[$port] : [];
                    $ifaces[$port] = ['ip' => self::ip($cfg['ip'] ?? null), 'mask' => self::mask($cfg['m'] ?? null), 'up' => ($cfg['u'] ?? 0) == 1];
                }
                foreach (array_slice(is_array($raw['rt'] ?? null) ? $raw['rt'] : [], 0, self::MAX_ROUTES) as $r) {
                    if (!is_array($r) || count($r) < 3) {
                        continue;
                    }
                    $net = self::ip($r[0] ?? null);
                    $mask = self::mask($r[1] ?? null);
                    $via = self::ip($r[2] ?? null);
                    if ($net !== null && $mask !== null && $via !== null) {
                        $routes[] = ['net' => $net & $mask, 'mask' => $mask, 'via' => $via];
                    }
                }
            }
            $isHost = in_array($type, self::HOSTS, true);
            $devices[$id] = [
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'x' => max(0, min(2000, (int) ($raw['x'] ?? 0))),
                'y' => max(0, min(2000, (int) ($raw['y'] ?? 0))),
                'ip' => $isHost ? self::ip($raw['ip'] ?? null) : null,
                'mask' => $isHost ? self::mask($raw['m'] ?? null) : null,
                'gateway' => $isHost ? self::ip($raw['g'] ?? null) : null,
                'ifaces' => $ifaces,
                'routes' => $routes,
            ];
        }

        $links = [];
        $used = [];
        foreach (array_slice(is_array($data['l'] ?? null) ? $data['l'] : [], 0, self::MAX_LINKS) as $l) {
            if (!is_array($l) || count($l) !== 4) {
                continue;
            }
            [$a, $pa, $b, $pb] = array_map(static fn ($v): string => self::str($v, 12), array_values($l));
            $fits = $a !== $b && isset($devices[$a], $devices[$b])
                && in_array($pa, self::PORTS[$devices[$a]['type']], true) && in_array($pb, self::PORTS[$devices[$b]['type']], true);
            if (!$fits || isset($used[$a . ':' . $pa]) || isset($used[$b . ':' . $pb])) {
                continue; // real ports only, one cable per port
            }
            $used[$a . ':' . $pa] = $used[$b . ':' . $pb] = true;
            $links[] = [$a, $pa, $b, $pb];
        }

        return new self($devices, $links);
    }

    /** @return list<Device> */
    public function ofType(string ...$types): array
    {
        return array_values(array_filter($this->devices, static fn (array $d): bool => in_array($d['type'], $types, true)));
    }

    /**
     * The compact JSON again, with only what was accepted (for keeping as evidence).
     *
     * @return array{d: list<array<string, mixed>>, l: list<array{0: string, 1: string, 2: string, 3: string}>}
     */
    public function toArray(): array
    {
        $out = ['d' => [], 'l' => $this->links];
        foreach ($this->devices as $d) {
            $row = ['i' => $d['id'], 't' => $d['type'], 'n' => $d['name'], 'x' => $d['x'], 'y' => $d['y']];
            if (in_array($d['type'], self::HOSTS, true)) {
                $row += ['ip' => self::show($d['ip']), 'm' => self::show($d['mask']), 'g' => self::show($d['gateway'])];
            } elseif ($d['type'] === 'router') {
                $row['if'] = array_map(static fn (array $i): array => ['ip' => self::show($i['ip']), 'm' => self::show($i['mask']), 'u' => $i['up'] ? 1 : 0], $d['ifaces']);
                $row['rt'] = array_map(static fn (array $r): array => [self::show($r['net']), self::show($r['mask']), self::show($r['via'])], $d['routes']);
            }
            $out['d'][] = $row;
        }

        return $out;
    }

    public static function show(?int $ip): string
    {
        return $ip === null ? '' : (long2ip($ip) ?: '');
    }

    /** "192.168.1.10" → integer; anything else → null. */
    public static function ip(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', trim($value), $m) !== 1) {
            return null;
        }
        $n = 0;
        for ($i = 1; $i <= 4; $i++) {
            $o = (int) $m[$i];
            if ($o > 255) {
                return null;
            }
            $n = ($n << 8) | $o;
        }

        return $n;
    }

    /** A dotted mask ("255.255.255.0") or a prefix ("/24", "24") → integer; non-contiguous masks → null. */
    public static function mask(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if (preg_match('/^\/?(\d{1,2})$/', $v, $m) === 1) {
            $bits = (int) $m[1];

            return $bits >= 1 && $bits <= 32 ? self::prefixMask($bits) : null;
        }
        $n = self::ip($v);
        if ($n === null || $n === 0) {
            return null;
        }
        $inverted = (~$n) & 0xFFFFFFFF;

        return ($inverted & ($inverted + 1)) === 0 ? $n : null;
    }

    public static function prefixMask(int $bits): int
    {
        return $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
    }

    public static function prefixOf(int $mask): int
    {
        return 32 - (int) round(log(((~$mask) & 0xFFFFFFFF) + 1, 2));
    }

    /** A usable host address in a network: not the network or broadcast address (except /31 and /32). */
    public static function isHostAddress(int $ip, int $mask): bool
    {
        if (self::prefixOf($mask) >= 31) {
            return true;
        }
        $host = $ip & ((~$mask) & 0xFFFFFFFF);

        return $host !== 0 && $host !== ((~$mask) & 0xFFFFFFFF);
    }

    private static function str(mixed $value, int $max): string
    {
        return is_string($value) || is_int($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }
}
