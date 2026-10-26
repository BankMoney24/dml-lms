<?php

declare(strict_types=1);

namespace App\Modules\Joining;

/** What a learner told the school when they started (ADR 0020). */
final class LearnerProfile
{
    /** key => [label, one line] */
    public const GOALS = [
        'code' => ['Learn to code', 'Programming, websites and software'],
        'networks' => ['Networks and IT', 'How computers connect, servers and IT support'],
        'security' => ['Cybersecurity', 'Staying safe online and protecting systems'],
        'data' => ['Data and AI', 'Working with data, spreadsheets and artificial intelligence'],
        'school' => ['School subjects', 'Practicals and exam preparation'],
        'career' => ['A job in tech', 'Practical skills employers ask for'],
        'everyday' => ['Everyday digital skills', 'Your phone, money online, selling and staying safe'],
    ];

    /** key => [label, one line] */
    public const EXPERIENCE = [
        'new' => ['New to this', 'Start from the very beginning.'],
        'some' => ['I know the basics', 'Skip the first steps.'],
        'pro' => ['I already work with it', 'Programmers, technicians and other professionals: show me the harder courses and labs.'],
    ];

    public const WEEKLY_GOALS = [1, 2, 3, 5, 7];

    /**
     * @param list<string> $goals
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly array $goals,
        public readonly ?string $experience,
        public readonly ?int $weeklyGoal,
        public readonly ?string $github,
        public readonly ?string $portfolio,
        public readonly string $joinedVia,
        public readonly ?string $onboardedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $goals = array_values(array_filter(explode(',', (string) ($row['goals'] ?? '')), static fn (string $g): bool => isset(self::GOALS[$g])));

        return new self(
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            $goals,
            isset($row['experience']) && isset(self::EXPERIENCE[(string) $row['experience']]) ? (string) $row['experience'] : null,
            isset($row['weekly_goal']) ? (int) $row['weekly_goal'] : null,
            isset($row['github']) && $row['github'] !== '' ? (string) $row['github'] : null,
            isset($row['portfolio']) && $row['portfolio'] !== '' ? (string) $row['portfolio'] : null,
            (string) ($row['joined_via'] ?? 'school'),
            isset($row['onboarded_at']) ? (string) $row['onboarded_at'] : null,
        );
    }

    public function isOnboarded(): bool
    {
        return $this->onboardedAt !== null;
    }

    public function githubUrl(): ?string
    {
        return $this->github === null ? null : 'https://github.com/' . rawurlencode($this->github);
    }

    /** "github.com/ada" style handle check: letters, digits and single hyphens, up to 39 characters. */
    public static function validGithub(string $handle): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $handle) === 1;
    }

    /** Accepts "ada", "@ada" or "https://github.com/ada". */
    public static function githubHandle(string $input): string
    {
        $input = trim($input);
        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([^/?\#\s]+)/?$#i', $input, $m) === 1) {
            return $m[1];
        }

        return ltrim($input, '@');
    }
}
