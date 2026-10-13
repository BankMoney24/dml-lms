<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/** One answer the learner types or chooses; rendered as an ordinary labelled form field. */
final class LabField
{
    /** @param array<string, string> $options for select fields */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'number',
        public readonly string $unit = '',
        public readonly string $hint = '',
        public readonly array $options = [],
        public readonly int $rows = 4,
    ) {
    }
}
