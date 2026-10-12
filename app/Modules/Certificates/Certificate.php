<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

final class Certificate
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $enrolmentId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly string $code,
        public readonly string $learnerName,
        public readonly string $courseTitle,
        public readonly string $issuedAt,
        public readonly ?string $revokedAt,
        public readonly ?string $revokedReason,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['enrolment_id'],
            (int) $row['course_id'],
            (int) $row['user_id'],
            (string) $row['code'],
            (string) $row['learner_name'],
            (string) $row['course_title'],
            (string) $row['issued_at'],
            $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
            $row['revoked_reason'] === null ? null : (string) $row['revoked_reason'],
        );
    }

    public function isValid(): bool
    {
        return $this->revokedAt === null;
    }

    /** "ABCD-EFGH-JKLM", easier to read out and type. */
    public function displayCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }
}
