<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/** Networking: from an address and prefix, work out the network, broadcast address and size. */
final class SubnetLab implements Lab
{
    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'subnetting',
            version: 1,
            title: 'Subnetting: where does this network start and end?',
            subject: 'Networking',
            track: 'skills',
            level: 'Beginner',
            summary: 'Work out a subnet’s network address, broadcast address and size, and whether two devices share it.',
            objective: 'Apply a subnet mask to an IPv4 address in binary to find the network it belongs to.',
            tasks: [
                'Write the address in binary and keep the first “prefix” bits: that gives the network address.',
                'Set all the remaining bits to 1 to get the broadcast address.',
                'Count the usable host addresses: 2^(32 − prefix) − 2.',
                'Decide whether the second device is on the same network.',
            ],
            prerequisites: 'Binary numbers and the parts of an IPv4 address.',
            minutes: 25,
        );
    }

    public function params(Seed $seed): array
    {
        $prefix = $seed->int(20, 29);
        $block = $seed->int(0, 2);
        $base = match ($block) {
            0 => (10 << 24),
            1 => (172 << 24) | ($seed->int(16, 31) << 16),
            default => (192 << 24) | (168 << 16),
        };
        $hostSpace = match ($block) {
            0 => 1 << 24,
            1 => 1 << 16,
            default => 1 << 16,
        };
        $size = 1 << (32 - $prefix);
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = ($base + $seed->int(0, intdiv($hostSpace, $size) - 1) * $size) & $mask;
        $address = $network + $seed->int(1, $size - 2);
        $same = $seed->chance();
        $other = $same
            ? $network + $seed->int(1, $size - 2)
            : ($network + ($seed->chance() || $network - $size < $base ? $size : -$size)) + $seed->int(1, $size - 2);
        if ($same && $other === $address) {
            $other = $network + (($address - $network) % ($size - 2)) + 1;
        }

        return [
            'public' => ['ip' => long2ip($address), 'prefix' => $prefix, 'other' => long2ip($other)],
            'secret' => [
                'network' => long2ip($network),
                'broadcast' => long2ip($network + $size - 1),
                'hosts' => $size - 2,
                'same' => ($other & $mask) === $network,
            ],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('network', 'Network address', 'text', '', 'For example 192.168.4.0'),
            new LabField('broadcast', 'Broadcast address', 'text'),
            new LabField('hosts', 'Number of usable host addresses'),
            new LabField('same', sprintf('Is %s on the same network?', (string) $public['other']), 'select', '', '', ['' => 'Choose', 'yes' => 'Yes', 'no' => 'No']),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $s = $params['secret'];
        $ip = static fn (?string $value): string => (string) preg_replace('/\s+/', '', (string) $value);
        $same = (bool) $s['same'];
        $prefix = (int) $params['public']['prefix'];

        return LabResult::fromParts([
            [
                'label' => 'Network address',
                'ok' => $ip($answers['network'] ?? '') === $s['network'],
                'detail' => sprintf('The network address is %s/%d.', $s['network'], $prefix),
                'marks' => 25,
            ],
            ['label' => 'Broadcast address', 'ok' => $ip($answers['broadcast'] ?? '') === $s['broadcast'], 'detail' => sprintf('The broadcast address is %s.', $s['broadcast']), 'marks' => 25],
            [
                'label' => 'Usable hosts',
                'ok' => Answers::number($answers['hosts'] ?? null) === (float) $s['hosts'],
                'detail' => sprintf('2^%d − 2 = %d usable addresses.', 32 - $prefix, (int) $s['hosts']),
                'marks' => 25,
            ],
            [
                'label' => 'Same network?',
                'ok' => ($answers['same'] ?? '') === ($same ? 'yes' : 'no'),
                'detail' => sprintf('%s is %s the network %s.', (string) $params['public']['other'], $same ? 'inside' : 'outside', $s['network']),
                'marks' => 25,
            ],
        ], array_intersect_key($answers, array_flip(['network', 'broadcast', 'hosts', 'same'])));
    }
}
