<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Network\Topology;
use App\Modules\VirtualLabs\Seed;

/**
 * Security: write the firewall rules for a school server from a policy written
 * in plain words (ADR 0035). Rules are checked in order and the first match
 * wins; anything no rule allows is blocked. The browser shows how sample
 * traffic fares; the server tests the rules against its own, unseen traffic.
 */
final class FirewallLab implements Lab
{
    public const MAX_RULES = 15;

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'firewall-rules',
            version: 1,
            title: 'Firewall rules for the school server',
            subject: 'Cybersecurity',
            track: 'skills',
            level: 'Intermediate',
            summary: 'Turn a security policy into ordered firewall rules, watch sample traffic pass or get blocked, and make sure only what the policy allows gets through.',
            objective: 'Write least-privilege firewall rules: allow exactly what the policy needs, in the right order, and block everything else.',
            tasks: [
                'Read the policy and note each service, who may use it and on which port.',
                'Add one allow rule per need, with the narrowest source that works (one address, a network, or any).',
                'Put rules in the right order: the first rule that matches decides.',
                'Check the sample traffic: allowed and blocked should match the policy.',
            ],
            prerequisites: 'IP addresses and networks (CIDR), and what a port number is.',
            minutes: 30,
        );
    }

    public function params(Seed $seed): array
    {
        $server = sprintf('10.%d.%d.10', $seed->int(1, 250), $seed->int(0, 250));
        $base = substr($server, 0, (int) strrpos($server, '.'));
        $app = $base . '.20';
        $admin = sprintf('192.168.%d.0/24', $seed->int(1, 250));
        $adminBase = substr($admin, 0, (int) strrpos($admin, '.'));
        $ssh = $seed->pick([22, 22, 2222]);
        [$dbName, $db] = $seed->pick([['MySQL', 3306], ['PostgreSQL', 5432]]);
        $internet = static fn (Seed $s): string => sprintf('%d.%d.%d.%d', $s->pick([41, 102, 105, 154, 196, 197]), $s->int(0, 255), $s->int(0, 255), $s->int(1, 254));

        $sample = [
            ['tcp', $internet($seed), 443, 'A parent opens the results website'],
            ['tcp', $adminBase . '.' . $seed->int(10, 99), $ssh, 'The IT officer logs in to manage the server'],
            ['tcp', $internet($seed), $ssh, 'Someone on the internet tries to log in'],
            ['tcp', $app, $db, 'The app server reads the database'],
            ['tcp', $internet($seed), $db, 'Someone on the internet tries the database'],
            ['tcp', $adminBase . '.' . $seed->int(100, 199), 3389, 'Remote desktop from the admin office'],
            ['udp', $internet($seed), 53, 'A DNS question from the internet'],
            ['tcp', $internet($seed), 23, 'Telnet from the internet'],
        ];
        $tests = [
            'web' => [['tcp', $internet($seed), 443], ['tcp', $internet($seed), 443], ['tcp', $adminBase . '.' . $seed->int(10, 250), 443]],
            'ssh_admin' => [['tcp', $adminBase . '.' . $seed->int(2, 250), $ssh], ['tcp', $adminBase . '.' . $seed->int(2, 250), $ssh]],
            'ssh_other' => [['tcp', $internet($seed), $ssh], ['tcp', $app, $ssh], ['tcp', sprintf('192.168.%d.%d', ($seed->int(1, 250) + 0), $seed->int(2, 250)), $ssh]],
            'db_app' => [['tcp', $app, $db]],
            'db_other' => [['tcp', $internet($seed), $db], ['tcp', $adminBase . '.' . $seed->int(2, 250), $db], ['tcp', $base . '.' . $seed->int(30, 99), $db]],
            'rest' => [
                ['tcp', $internet($seed), 80], ['tcp', $internet($seed), 3389], ['udp', $internet($seed), 443],
                ['tcp', $adminBase . '.' . $seed->int(2, 250), 21], ['udp', $internet($seed), 161],
            ],
        ];
        // The "other 192.168" address must not fall in the admin network.
        foreach ($tests['ssh_other'] as $i => $t) {
            if (str_starts_with($t[1], '192.168.') && self::inCidr($t[1], $admin)) {
                $tests['ssh_other'][$i][1] = '172.20.' . $seed->int(0, 250) . '.' . $seed->int(2, 250);
            }
        }

        return [
            'public' => [
                'server' => $server,
                'policy' => [
                    'Anyone may open the website over HTTPS (TCP port 443).',
                    'Staff on the admin network ' . $admin . ' may log in with SSH (TCP port ' . $ssh . '). Nobody else may.',
                    'Only the app server ' . $app . ' may reach the ' . $dbName . ' database (TCP port ' . $db . ').',
                    'Everything else is blocked.',
                ],
                'sample' => $sample,
            ],
            'secret' => ['tests' => $tests],
        ];
    }

    public function fields(array $public): array
    {
        return [new LabField('rules', 'Your rules', 'hidden')];
    }

    public function check(array $params, array $answers): LabResult
    {
        $rules = self::rules((string) ($answers['rules'] ?? ''));
        $t = $params['secret']['tests'];
        $count = static function (array $packets, bool $want) use ($rules): array {
            $packets = array_map(static fn (array $p): array => [(string) $p[0], (string) $p[1], (int) $p[2]], $packets);
            $wrong = array_values(array_filter($packets, static fn (array $p): bool => self::decide($rules, $p) !== $want));

            return [$wrong === [], $wrong];
        };
        $describe = static fn (array $p): string => strtoupper($p[0]) . ' ' . $p[2] . ' from ' . $p[1];

        // Blocking only earns marks alongside the matching allow: an empty rule list blocks everything, but serves nobody.
        $parts = [];
        $passed = [];
        $checks = [
            ['web', true, null, 'Website for everyone', 20, 'HTTPS (TCP 443) is allowed from anywhere.', 'HTTPS from everyone should be allowed; still blocked: '],
            ['ssh_admin', true, null, 'SSH from the admin network', 15, 'Staff on the admin network can log in.', 'Staff on the admin network should be able to log in; still blocked: '],
            ['ssh_other', false, 'ssh_admin', 'SSH from anywhere else', 15, 'Logins from outside the admin network are blocked.', 'Logins from outside the admin network must be blocked; allowed: '],
            ['db_app', true, null, 'Database for the app server', 15, 'The app server reaches the database.', 'The app server must reach the database; blocked: '],
            ['db_other', false, 'db_app', 'Database for anyone else', 15, 'Nobody else reaches the database.', 'Only the app server may use the database; allowed: '],
            ['rest', false, 'web', 'Everything else blocked', 20, 'Other services and ports are blocked.', 'Traffic the policy does not mention must be blocked; allowed: '],
        ];
        foreach ($checks as [$key, $want, $needs, $label, $marks, $good, $bad]) {
            [$ok, $wrong] = $count($t[$key], $want);
            $passed[$key] = $ok;
            if ($ok && $needs !== null && !($passed[$needs] ?? false)) {
                $parts[] = ['label' => $label, 'ok' => false, 'marks' => $marks,
                    'detail' => 'Blocked, but only because the service itself is not working yet. Fix the allow rule first.'];
                continue;
            }
            $parts[] = ['label' => $label, 'ok' => $ok, 'marks' => $marks, 'detail' => $ok ? $good : $bad . implode(', ', array_map($describe, array_slice($wrong, 0, 2))) . '.'];
        }

        return LabResult::fromParts($parts, ['rules' => $rules]);
    }

    /**
     * The learner's rules, cleaned: [action, protocol, source, port] with source "any" or a CIDR and port "any" or 1–65535.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function rules(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? array_slice($data, 0, self::MAX_RULES) : [] as $r) {
            if (!is_array($r) || count($r) !== 4) {
                continue;
            }
            [$action, $proto, $src, $port] = array_map(static fn ($v): string => strtolower(trim((string) (is_scalar($v) ? $v : ''))), array_values($r));
            if (!in_array($action, ['allow', 'deny'], true) || !in_array($proto, ['tcp', 'udp', 'any'], true)) {
                continue;
            }
            if ($src !== 'any') {
                $src = str_contains($src, '/') ? $src : $src . '/32';
                [$ip, $bits] = explode('/', $src, 2);
                if (Topology::ip($ip) === null || !ctype_digit($bits) || (int) $bits > 32) {
                    continue;
                }
            }
            if ($port !== 'any' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
                continue;
            }
            $out[] = [$action, $proto, $src, $port];
        }

        return $out;
    }

    /**
     * First matching rule decides; no match means blocked.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rules
     * @param array{0: string, 1: string, 2: int} $packet [protocol, source address, port]
     */
    public static function decide(array $rules, array $packet): bool
    {
        foreach ($rules as [$action, $proto, $src, $port]) {
            $matches = ($proto === 'any' || $proto === $packet[0])
                && ($src === 'any' || self::inCidr($packet[1], $src))
                && ($port === 'any' || (int) $port === $packet[2]);
            if ($matches) {
                return $action === 'allow';
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $a = Topology::ip($ip);
        $n = Topology::ip($net);
        if ($a === null || $n === null) {
            return false;
        }
        $mask = Topology::prefixMask(max(0, min(32, (int) $bits)));

        return ($a & $mask) === ($n & $mask);
    }
}
