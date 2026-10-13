<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

/** The wizards this installation offers, by URL id. */
final class WizardRegistry
{
    /** @var array<string, Wizard> */
    private array $wizards = [];

    /** @param list<Wizard> $wizards */
    public function __construct(array $wizards)
    {
        foreach ($wizards as $wizard) {
            $this->wizards[$wizard->id()] = $wizard;
        }
    }

    public function find(string $id): ?Wizard
    {
        return $this->wizards[$id] ?? null;
    }
}
