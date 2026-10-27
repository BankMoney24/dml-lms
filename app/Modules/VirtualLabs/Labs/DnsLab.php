<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Networking: be the DNS resolver (ADR 0035). Starting from a root server, the
 * learner follows referrals with dig, one server at a time, through the .test
 * top-level domain to the school's own name server, then follows a CNAME into
 * another zone. Names use the reserved .test domain and addresses come from
 * the documentation ranges, so nothing here points at a real system (apart
 * from the root server's real address).
 */
final class DnsLab implements Lab
{
    public const SCHOOLS = ['greenfield', 'riverside', 'sunrise', 'hilltop', 'unity', 'royalcrest', 'goldengate', 'brightstar'];
    public const HOSTS = ['dnsfarm', 'zonehost', 'nameworks', 'webnest'];
    public const CLOUDS = ['cloudhost', 'skyrack', 'appnimbus'];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'dns-lookup',
            version: 1,
            title: 'Follow a DNS lookup from the root',
            subject: 'Networking',
            track: 'skills',
            level: 'Intermediate',
            summary: 'Act as the DNS resolver: ask the root, then each server it refers you to, follow a CNAME into another zone, and find the address and mail server.',
            objective: 'Explain how a name becomes an address by following referrals from the root to the authoritative server, and read A, NS, CNAME and MX records.',
            tasks: [
                'Ask the root server about the name with dig @<server> <name>. Read the AUTHORITY and ADDITIONAL sections: they say whom to ask next.',
                'Keep asking the server you were referred to until an answer comes back with the aa (authoritative) flag.',
                'If the answer is a CNAME, look up the new name the same way, from the root.',
                'Ask the school’s name server for its MX records to find where its email goes.',
            ],
            prerequisites: 'What a domain name and an IP address are.',
            minutes: 30,
        );
    }

    public function params(Seed $seed): array
    {
        $school = $seed->pick(self::SCHOOLS) . $seed->pick(['academy', 'college', 'school']);
        $host = $seed->pick(self::HOSTS);
        $cloud = $seed->pick(self::CLOUDS);
        $zone = $school . '.edu.test';
        $used = ['192.0.2.53' => true, '192.0.2.54' => true, '198.51.100.20' => true];
        $ip = static function (string $range, Seed $s) use (&$used): string {
            do {
                $a = $range . '.' . $s->int(2, 250);
            } while (isset($used[$a]));
            $used[$a] = true;

            return $a;
        };
        $nsA = 'ns1.' . $host . '.test';
        $nsB = 'ns2.' . $host . '.test';
        $cnameTarget = $school . '-' . $seed->pick(['lms', 'portal', 'app']) . '.' . $cloud . '.test';
        $answer = $ip('203.0.113', $seed);
        $mail = $seed->shuffle(['mail1.' . $zone, 'mx.' . $host . '.test']);

        // server address => what it knows: zone => records. Referrals are NS records with glue (A) in the same zone list.
        $servers = [
            '198.41.0.4' => ['name' => 'a.root-servers.net', 'zone' => '.', 'records' => [
                ['test', 'NS', 'a.nic.test', 172800], ['test', 'NS', 'b.nic.test', 172800],
                ['a.nic.test', 'A', '192.0.2.53', 172800], ['b.nic.test', 'A', '192.0.2.54', 172800],
            ]],
            '192.0.2.53' => ['name' => 'a.nic.test', 'zone' => 'test', 'records' => $tld = [
                ['edu.test', 'NS', 'ns.edu.test', 86400], ['ns.edu.test', 'A', '198.51.100.20', 86400],
                [$host . '.test', 'NS', 'ns.' . $host . '.test', 86400], ['ns.' . $host . '.test', 'A', $nsHostIp = $ip('198.51.100', $seed), 86400],
                [$cloud . '.test', 'NS', 'ns1.' . $cloud . '.test', 86400], ['ns1.' . $cloud . '.test', 'A', $cloudNs = $ip('192.0.2', $seed), 86400],
            ]],
            '192.0.2.54' => ['name' => 'b.nic.test', 'zone' => 'test', 'records' => $tld],
            '198.51.100.20' => ['name' => 'ns.edu.test', 'zone' => 'edu.test', 'records' => [
                [$zone, 'NS', $nsA, 3600], [$zone, 'NS', $nsB, 3600],
                [$nsA, 'A', $nsAIp = $ip('198.51.100', $seed), 3600], [$nsB, 'A', $nsBIp = $ip('192.0.2', $seed), 3600],
            ]],
            $nsHostIp => ['name' => 'ns.' . $host . '.test', 'zone' => $host . '.test', 'records' => [
                [$nsA, 'A', $nsAIp, 3600], ['mx.' . $host . '.test', 'A', $ip('203.0.113', $seed), 3600],
            ]],
            $nsAIp => ['name' => $nsA, 'zone' => $zone, 'records' => $zoneRecords = [
                [$zone, 'NS', $nsA, 3600], [$zone, 'NS', $nsB, 3600],
                ['www.' . $zone, 'A', $ip('203.0.113', $seed), 3600],
                ['portal.' . $zone, 'CNAME', $cnameTarget, 300],
                [$zone, 'MX', '10 ' . $mail[0], 3600], [$zone, 'MX', '20 ' . $mail[1], 3600],
                ['mail1.' . $zone, 'A', $ip('203.0.113', $seed), 3600],
            ]],
            $nsBIp => ['name' => $nsB, 'zone' => $zone, 'records' => $zoneRecords],
            $cloudNs => ['name' => 'ns1.' . $cloud . '.test', 'zone' => $cloud . '.test', 'records' => [
                [$cnameTarget, 'A', $answer, 60],
            ]],
        ];

        return [
            'public' => ['name' => 'portal.' . $zone, 'zone' => $zone, 'root' => '198.41.0.4', 'servers' => $servers],
            'secret' => ['answer' => $answer, 'ns' => [$nsA, $nsB], 'cname' => $cnameTarget, 'mx' => $mail[0]],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('address', 'The IPv4 address of ' . $public['name'], 'text', '', 'For example 203.0.113.7'),
            new LabField('cname', 'The name ' . $public['name'] . ' is an alias (CNAME) for', 'text', '', 'A full name, for example app.example.test'),
            new LabField('ns', 'A name server that is authoritative for ' . $public['zone'], 'text'),
            new LabField('mx', 'The mail server tried first for email to ' . $public['zone'], 'text', '', 'The MX record with the lowest preference number'),
            new LabField('log', 'Your queries', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $s = $params['secret'];
        $clean = static fn (?string $v): string => rtrim(strtolower(trim((string) $v)), '.');
        $log = json_decode((string) ($answers['log'] ?? ''), true);
        $asked = is_array($log) ? array_values(array_unique(array_filter(array_map(static fn ($q): string => is_array($q) ? (string) ($q[0] ?? '') : '', array_slice($log, 0, 200))))) : [];

        return LabResult::fromParts([
            ['label' => 'Address', 'ok' => $clean($answers['address'] ?? '') === $s['answer'], 'marks' => 35,
                'detail' => sprintf('%s is an alias; %s answers with %s.', $params['public']['name'], $s['cname'], $s['answer'])],
            ['label' => 'Alias', 'ok' => $clean($answers['cname'] ?? '') === $s['cname'], 'marks' => 20,
                'detail' => sprintf('The school’s server returns CNAME %s.', $s['cname'])],
            ['label' => 'Authoritative server', 'ok' => in_array($clean($answers['ns'] ?? ''), $s['ns'], true), 'marks' => 20,
                'detail' => sprintf('%s is served by %s.', $params['public']['zone'], implode(' and ', $s['ns']))],
            ['label' => 'Mail server', 'ok' => $clean($answers['mx'] ?? '') === $s['mx'], 'marks' => 15,
                'detail' => sprintf('MX 10 %s has the lowest preference number, so it is tried first.', $s['mx'])],
            ['label' => 'Followed the referrals', 'ok' => count($asked) >= 4, 'marks' => 10,
                'detail' => count($asked) >= 4
                    ? sprintf('You asked %d different servers, as a resolver does.', count($asked))
                    : 'Ask each server in turn, from the root down (at least four servers).'],
        ], array_intersect_key($answers, array_flip(['address', 'cname', 'ns', 'mx'])) + ['servers_asked' => $asked]);
    }
}
