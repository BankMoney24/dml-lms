<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

// Packet descriptions and marking lines read best on one line each.
// phpcs:disable Generic.Files.LineLength

/**
 * Networking: read a packet capture the way a technician reads Wireshark.
 * A laptop finds its gateway (ARP), looks up a name (DNS), opens a TCP
 * connection, asks for a web page (HTTP) and closes the connection, with a
 * little unrelated traffic mixed in. Every session has its own addresses,
 * ports, page and outcome. The packets are built byte by byte, so the hex
 * view is a real Ethernet/IPv4/TCP/UDP/ARP layout (checksums left at zero).
 */
final class PacketCaptureLab implements Lab
{
    /** Invented hosts on reserved example domains; addresses from the documentation ranges. */
    private const SITES = [
        ['learn.example.org', '/timetable'],
        ['results.example.net', '/ss2/term-1'],
        ['library.example.com', '/catalogue?q=physics'],
        ['portal.example.org', '/login'],
        ['news.example.net', '/sports'],
        ['shop.example.com', '/cart'],
    ];
    private const DNS_SERVERS = ['8.8.8.8', '1.1.1.1', '9.9.9.9'];
    private const STATUSES = [200 => 'OK', 301 => 'Moved Permanently', 404 => 'Not Found', 403 => 'Forbidden', 500 => 'Internal Server Error'];
    private const VENDORS = ['3c:52:82', 'f0:9f:c2', '00:1a:2b', 'b8:27:eb', 'a4:5e:60', 'd8:3a:dd'];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'packet-capture',
            version: 1,
            title: 'Packet capture: follow a web visit packet by packet',
            subject: 'Networking',
            track: 'skills',
            level: 'Intermediate',
            summary: 'Read a real-looking capture of a laptop visiting a website: ARP, DNS, the TCP handshake, HTTP and the goodbye.',
            objective: 'Read a packet capture to find who talked to whom, on which ports, and what happened.',
            tasks: [
                'Find the ARP reply: it tells you the default gateway’s MAC address.',
                'Find the DNS query and its answer: which server answered, and what address the name resolved to.',
                'Follow the TCP three-way handshake (SYN, SYN-ACK, ACK) and note the client’s port.',
                'Read the HTTP response to see what the server said.',
            ],
            prerequisites: 'IP addresses and ports; what DNS and HTTP are for.',
            minutes: 30,
        );
    }

    public function params(Seed $seed): array
    {
        $lan = $seed->int(0, 2) === 0 ? [10, 0, $seed->int(1, 50)] : [192, 168, $seed->int(0, 20)];
        $clientIp = implode('.', [...$lan, $seed->int(20, 199)]);
        $gatewayIp = implode('.', [...$lan, 1]);
        $clientMac = self::mac($seed);
        $gatewayMac = self::mac($seed);
        $dnsIp = $seed->pick(self::DNS_SERVERS);
        [$host, $path] = $seed->pick(self::SITES);
        $serverIp = $seed->pick(['203.0.113', '198.51.100', '192.0.2']) . '.' . $seed->int(10, 250);
        $clientPort = $seed->int(49200, 64900);
        $dnsPort = $seed->int(49200, 64900);
        $status = $seed->pick(array_keys(self::STATUSES));
        $dnsId = $seed->int(0x1000, 0xFFFE);
        $isn = $seed->int(1000000, 3999999999);
        $srvIsn = $seed->int(1000000, 3999999999);
        $noiseAt = $seed->int(0, 2);
        $noiseHost = $seed->pick(['time.example.net', 'update.example.com', 'mail.example.org']);
        $noiseIp = $seed->pick(['203.0.113', '198.51.100']) . '.' . $seed->int(10, 250);
        $noisePort = $seed->int(49200, 64900);
        $noiseId = $seed->int(0x1000, 0xFFFE);

        $request = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUser-Agent: Mozilla/5.0 (school laptop)\r\nAccept: text/html\r\n\r\n";
        $reason = self::STATUSES[$status];
        $body = $status === 200 ? '<!doctype html><title>' . $host . '</title><p>Welcome</p>' : '<p>' . $status . ' ' . $reason . '</p>';
        $extra = $status === 301 ? "Location: https://{$host}{$path}\r\n" : '';
        $response = "HTTP/1.1 {$status} {$reason}\r\nServer: nginx\r\nContent-Type: text/html\r\n{$extra}Content-Length: " . strlen($body) . "\r\n\r\n" . $body;

        $c2s = ['smac' => $clientMac, 'dmac' => $gatewayMac];
        $s2c = ['smac' => $gatewayMac, 'dmac' => $clientMac];
        $list = [];
        $list[] = self::arp($clientMac, 'ff:ff:ff:ff:ff:ff', 1, $clientMac, $clientIp, '00:00:00:00:00:00', $gatewayIp);
        $list[] = self::arp($gatewayMac, $clientMac, 2, $gatewayMac, $gatewayIp, $clientMac, $clientIp);
        $noise = [
            self::dns($c2s, $clientIp, $dnsIp, $noisePort, $noiseId, $noiseHost, null),
            self::dns($s2c, $dnsIp, $clientIp, $noisePort, $noiseId, $noiseHost, $noiseIp),
        ];
        $main = [
            self::dns($c2s, $clientIp, $dnsIp, $dnsPort, $dnsId, $host, null),
            self::dns($s2c, $dnsIp, $clientIp, $dnsPort, $dnsId, $host, $serverIp),
        ];
        // The unrelated lookup lands before, between or after the one that matters.
        $lookups = match ($noiseAt) {
            0 => [...$noise, ...$main],
            1 => [$main[0], $noise[0], $main[1], $noise[1]],
            default => [...$main, ...$noise],
        };
        array_push($list, ...$lookups);
        $cs = $isn;
        $ss = $srvIsn;
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs, 0, ['SYN'], '');
        $list[] = self::tcp($s2c, $serverIp, $clientIp, 80, $clientPort, $ss, $cs + 1, ['SYN', 'ACK'], '');
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs + 1, $ss + 1, ['ACK'], '');
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs + 1, $ss + 1, ['PSH', 'ACK'], $request);
        $list[] = self::tcp($s2c, $serverIp, $clientIp, 80, $clientPort, $ss + 1, $cs + 1 + strlen($request), ['ACK'], '');
        $list[] = self::tcp($s2c, $serverIp, $clientIp, 80, $clientPort, $ss + 1, $cs + 1 + strlen($request), ['PSH', 'ACK'], $response);
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs + 1 + strlen($request), $ss + 1 + strlen($response), ['ACK'], '');
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs + 1 + strlen($request), $ss + 1 + strlen($response), ['FIN', 'ACK'], '');
        $list[] = self::tcp($s2c, $serverIp, $clientIp, 80, $clientPort, $ss + 1 + strlen($response), $cs + 2 + strlen($request), ['FIN', 'ACK'], '');
        $list[] = self::tcp($c2s, $clientIp, $serverIp, $clientPort, 80, $cs + 2 + strlen($request), $ss + 2 + strlen($response), ['ACK'], '');

        $packets = [];
        $t = 0.0;
        $handshakeDone = 0;
        foreach ($list as $i => $p) {
            $t += $i === 0 ? 0.0 : $seed->int(1, 90) / 1000;
            $p['no'] = $i + 1;
            $p['time'] = number_format($t, 6, '.', '');
            $packets[] = $p;
        }
        foreach ($packets as $i => $p) {
            // The ACK right after the SYN-ACK completes the handshake.
            if ($p['info'] !== '' && str_contains($p['info'], '[SYN, ACK]') && isset($packets[$i + 1])) {
                $handshakeDone = $packets[$i + 1]['no'];
            }
        }

        return [
            'public' => ['packets' => $packets, 'host' => $host, 'client' => $clientIp],
            'secret' => [
                'dns_ip' => $dnsIp,
                'server_ip' => $serverIp,
                'port' => $clientPort,
                'handshake_no' => $handshakeDone,
                'code' => $status,
                'gw_mac' => $gatewayMac,
            ],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('gateway_mac', 'MAC address of the default gateway', 'text', '', 'Six pairs of hex digits, for example 00:1a:2b:3c:4d:5e'),
            new LabField('dns_server', 'IP address of the DNS server that answered', 'text'),
            new LabField('resolved', sprintf('Address %s resolved to', (string) $public['host']), 'text'),
            new LabField('client_port', 'Source port the laptop used for the web connection'),
            new LabField('handshake', 'Number of the packet that completes the TCP three-way handshake'),
            new LabField('status', 'HTTP status code the server sent back'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $s = $params['secret'];
        $ip = static fn (?string $v): string => (string) preg_replace('/\s+/', '', (string) $v);
        $mac = static fn (?string $v): string => strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $v));

        return LabResult::fromParts([
            ['label' => 'Gateway MAC address', 'ok' => $mac($answers['gateway_mac'] ?? '') === $mac((string) $s['gw_mac']), 'detail' => sprintf('The ARP reply says the gateway is at %s.', $s['gw_mac']), 'marks' => 15],
            ['label' => 'DNS server', 'ok' => $ip($answers['dns_server'] ?? '') === $s['dns_ip'], 'detail' => sprintf('The queries go to %s on UDP port 53, and the answers come from it.', $s['dns_ip']), 'marks' => 15],
            [
                'label' => 'Resolved address',
                'ok' => $ip($answers['resolved'] ?? '') === $s['server_ip'],
                'detail' => sprintf('The DNS answer for %s is %s; the laptop then connects to it.', (string) $params['public']['host'], $s['server_ip']),
                'marks' => 20,
            ],
            ['label' => 'Client port', 'ok' => Answers::number($answers['client_port'] ?? null) === (float) $s['port'], 'detail' => sprintf('The SYN goes from port %d to port 80.', (int) $s['port']), 'marks' => 15],
            [
                'label' => 'Handshake',
                'ok' => Answers::number($answers['handshake'] ?? null) === (float) $s['handshake_no'],
                'detail' => sprintf('SYN, then SYN-ACK, then the laptop’s ACK in packet %d.', (int) $s['handshake_no']),
                'marks' => 20,
            ],
            ['label' => 'HTTP status', 'ok' => Answers::number($answers['status'] ?? null) === (float) $s['code'], 'detail' => sprintf('The server answered %d %s.', (int) $s['code'], self::STATUSES[(int) $s['code']] ?? ''), 'marks' => 15],
        ], array_intersect_key($answers, array_flip(['gateway_mac', 'dns_server', 'resolved', 'client_port', 'handshake', 'status'])));
    }

    private static function mac(Seed $seed): string
    {
        $tail = [];
        for ($i = 0; $i < 3; $i++) {
            $tail[] = sprintf('%02x', $seed->int(0, 255));
        }

        return $seed->pick(self::VENDORS) . ':' . implode(':', $tail);
    }

    /** @return array<string, mixed> */
    private static function arp(string $smac, string $dmac, int $op, string $sha, string $spa, string $tha, string $tpa): array
    {
        $frame = self::eth($dmac, $smac, 0x0806)
            . pack('nnCCn', 1, 0x0800, 6, 4, $op) . self::macBytes($sha) . self::ipBytes($spa) . self::macBytes($tha) . self::ipBytes($tpa);

        return [
            'src' => $smac,
            'dst' => $op === 1 ? 'Broadcast' : $dmac,
            'proto' => 'ARP',
            'len' => strlen($frame),
            'info' => $op === 1 ? "Who has {$tpa}? Tell {$spa}" : "{$spa} is at {$sha}",
            'layers' => [
                self::ethLayer($smac, $dmac, 'ARP (0x0806)'),
                ['name' => 'Address Resolution Protocol (' . ($op === 1 ? 'request' : 'reply') . ')', 'fields' => [
                    ['Opcode', $op === 1 ? 'request (1)' : 'reply (2)'],
                    ['Sender MAC address', $sha],
                    ['Sender IP address', $spa],
                    ['Target MAC address', $tha],
                    ['Target IP address', $tpa],
                ]],
            ],
            'hex' => bin2hex($frame),
        ];
    }

    /**
     * @param array{smac: string, dmac: string} $l2
     * @return array<string, mixed>
     */
    private static function dns(array $l2, string $src, string $dst, int $clientPort, int $id, string $name, ?string $answer): array
    {
        $qname = '';
        foreach (explode('.', $name) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\0";
        $isReply = $answer !== null;
        $payload = pack('nnnnnn', $id, $isReply ? 0x8180 : 0x0100, 1, $isReply ? 1 : 0, 0, 0) . $qname . pack('nn', 1, 1);
        if ($isReply) {
            $payload .= pack('nnnNn', 0xC00C, 1, 1, 300, 4) . self::ipBytes($answer);
        }
        [$sport, $dport] = $isReply ? [53, $clientPort] : [$clientPort, 53];
        $udp = pack('nnnn', $sport, $dport, 8 + strlen($payload), 0) . $payload;
        $frame = self::eth($l2['dmac'], $l2['smac'], 0x0800) . self::ipv4($src, $dst, 17, strlen($udp)) . $udp;
        $fields = [['Transaction ID', sprintf('0x%04x', $id)], ['Flags', $isReply ? '0x8180 Standard query response, No error' : '0x0100 Standard query'], ['Questions', '1'], ['Answer RRs', $isReply ? '1' : '0'], ['Query', $name . ': type A, class IN']];
        if ($isReply) {
            $fields[] = ['Answer', $name . ': type A, class IN, addr ' . $answer . ', TTL 300'];
        }

        return [
            'src' => $src,
            'dst' => $dst,
            'proto' => 'DNS',
            'len' => strlen($frame),
            'info' => sprintf('Standard query%s 0x%04x A %s%s', $isReply ? ' response' : '', $id, $name, $isReply ? ' A ' . $answer : ''),
            'layers' => [
                self::ethLayer($l2['smac'], $l2['dmac'], 'IPv4 (0x0800)'),
                self::ipLayer($src, $dst, 'UDP (17)'),
                ['name' => 'User Datagram Protocol, Src Port: ' . $sport . ', Dst Port: ' . $dport, 'fields' => [['Source port', (string) $sport], ['Destination port', (string) $dport], ['Length', (string) (8 + strlen($payload))]]],
                ['name' => 'Domain Name System (' . ($isReply ? 'response' : 'query') . ')', 'fields' => $fields],
            ],
            'hex' => bin2hex($frame),
        ];
    }

    /**
     * @param array{smac: string, dmac: string} $l2
     * @param list<string>                      $flags
     * @return array<string, mixed>
     */
    private static function tcp(array $l2, string $src, string $dst, int $sport, int $dport, int $seq, int $ack, array $flags, string $payload): array
    {
        $bits = ['FIN' => 1, 'SYN' => 2, 'RST' => 4, 'PSH' => 8, 'ACK' => 16];
        $flagValue = 0;
        foreach ($flags as $f) {
            $flagValue |= $bits[$f];
        }
        $seq &= 0xFFFFFFFF;
        $ack &= 0xFFFFFFFF;
        $segment = pack('nnNNCCnnn', $sport, $dport, $seq, $ack, 5 << 4, $flagValue, 64240, 0, 0) . $payload;
        $frame = self::eth($l2['dmac'], $l2['smac'], 0x0800) . self::ipv4($src, $dst, 6, strlen($segment)) . $segment;
        $isHttp = $payload !== '';
        $firstLine = $isHttp ? strtok($payload, "\r\n") : '';
        $flagText = '[' . implode(', ', $flags) . ']';
        $layers = [
            self::ethLayer($l2['smac'], $l2['dmac'], 'IPv4 (0x0800)'),
            self::ipLayer($src, $dst, 'TCP (6)'),
            ['name' => 'Transmission Control Protocol, Src Port: ' . $sport . ', Dst Port: ' . $dport, 'fields' => [
                ['Source port', (string) $sport],
                ['Destination port', (string) $dport],
                ['Sequence number (raw)', (string) $seq],
                ['Acknowledgment number (raw)', in_array('ACK', $flags, true) ? (string) $ack : '0'],
                ['Flags', sprintf('0x%03x %s', $flagValue, $flagText)],
                ['Window', '64240'],
                ['Payload length', (string) strlen($payload)],
            ]],
        ];
        if ($isHttp) {
            $lines = array_values(array_filter(explode("\r\n", explode("\r\n\r\n", $payload)[0]), static fn (string $l): bool => $l !== ''));
            $layers[] = ['name' => 'Hypertext Transfer Protocol', 'fields' => array_map(static fn (string $l): array => ['', $l], $lines)];
        }

        return [
            'src' => $src,
            'dst' => $dst,
            'proto' => $isHttp ? 'HTTP' : 'TCP',
            'len' => strlen($frame),
            'info' => $isHttp ? (string) $firstLine : sprintf('%d → %d %s Seq=%d%s Win=64240 Len=0', $sport, $dport, $flagText, $seq, in_array('ACK', $flags, true) ? ' Ack=' . $ack : ''),
            'layers' => $layers,
            'hex' => bin2hex($frame),
        ];
    }

    private static function eth(string $dst, string $src, int $type): string
    {
        return self::macBytes($dst) . self::macBytes($src) . pack('n', $type);
    }

    private static function ipv4(string $src, string $dst, int $proto, int $payloadLength): string
    {
        return pack('CCnnnCCn', 0x45, 0, 20 + $payloadLength, 0, 0x4000, 64, $proto, 0) . self::ipBytes($src) . self::ipBytes($dst);
    }

    /** @return array{name: string, fields: list<array{0: string, 1: string}>} */
    private static function ethLayer(string $src, string $dst, string $type): array
    {
        return ['name' => 'Ethernet II, Src: ' . $src . ', Dst: ' . $dst, 'fields' => [['Destination', $dst], ['Source', $src], ['Type', $type]]];
    }

    /** @return array{name: string, fields: list<array{0: string, 1: string}>} */
    private static function ipLayer(string $src, string $dst, string $proto): array
    {
        return ['name' => 'Internet Protocol Version 4, Src: ' . $src . ', Dst: ' . $dst, 'fields' => [['Version', '4'], ['Time to live', '64'], ['Protocol', $proto], ['Source address', $src], ['Destination address', $dst]]];
    }

    private static function macBytes(string $mac): string
    {
        return (string) hex2bin(str_replace(':', '', $mac));
    }

    private static function ipBytes(string $ip): string
    {
        return pack('N', (int) ip2long($ip));
    }
}
