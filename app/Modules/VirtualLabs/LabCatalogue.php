<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/** The labs this release offers. Adding one means adding a Lab class and its simulation script. */
final class LabCatalogue
{
    /** @var array<string, Lab> */
    private array $labs = [];

    /** @param list<Lab> $labs */
    public function __construct(array $labs)
    {
        foreach ($labs as $lab) {
            $this->labs[$lab->definition()->key] = $lab;
        }
    }

    public static function builtIn(): self
    {
        return new self([
            new Labs\OhmsLawLab(),
            new Labs\TitrationLab(),
            new Labs\StraightLineLab(),
            new Labs\SubnetLab(),
            new Labs\JsGradeLab(),
            new Labs\PhishingLab(),
            new Labs\PacketCaptureLab(),
            new Labs\NetworkTraceLab(),
            new Labs\PyLeapLab(),
            new Labs\NetworkBuildLab(),
            new Labs\NetworkRepairLab(),
            new Labs\NetworkVlanLab(),
            new Labs\NetworkOspfLab(),
            new Labs\FirewallLab(),
            new Labs\DnsLab(),
            new Labs\PendulumLab(),
            new Labs\OsmosisLab(),
        ]);
    }

    public function find(?string $key): ?Lab
    {
        return $key === null ? null : ($this->labs[$key] ?? null);
    }

    /** @return list<LabDefinition> school subjects first, then skills, by subject */
    public function definitions(): array
    {
        $definitions = array_map(static fn (Lab $lab): LabDefinition => $lab->definition(), array_values($this->labs));
        usort($definitions, static fn (LabDefinition $a, LabDefinition $b): int => [$a->track === 'school' ? 0 : 1, $a->subject, $a->title]
            <=> [$b->track === 'school' ? 0 : 1, $b->subject, $b->title]);

        return $definitions;
    }

    /** @return array<string, string> key => "Subject: title", for choosing a lab */
    public function options(): array
    {
        $options = [];
        foreach ($this->definitions() as $d) {
            $options[$d->key] = $d->subject . ': ' . $d->title;
        }

        return $options;
    }
}
