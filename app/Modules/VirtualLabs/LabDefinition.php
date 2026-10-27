<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/**
 * What a lab is (LAB-001): versioned, with its objective, prerequisites,
 * the tasks learners carry out, its time limit and pass mark. The version
 * goes up whenever the numbers or the marking change; a session started on
 * an older version cannot be submitted against a newer one.
 */
final class LabDefinition
{
    public const TRACKS = ['school' => 'School subject', 'skills' => 'Skills'];

    /** @param list<string> $tasks */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $title,
        public readonly string $subject,
        public readonly string $track,
        public readonly string $level,
        public readonly string $summary,
        public readonly string $objective,
        public readonly array $tasks,
        public readonly string $prerequisites,
        public readonly int $minutes,
        public readonly int $passMark = 70,
        public readonly bool $runsLearnerCode = false,
        /** Simulation script name when several labs share one (public/assets/labs/<engine>.js). */
        public readonly ?string $engine = null,
    ) {
    }

    public function trackLabel(): string
    {
        return self::TRACKS[$this->track] ?? $this->track;
    }

    /** The browser simulation: public/assets/labs/<key>.js. */
    public function script(): string
    {
        return 'labs/' . ($this->engine ?? $this->key) . '.js';
    }
}
