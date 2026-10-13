<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Modules\Audit\AuditLog;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\Enrolment;
use App\Modules\Tenancy\TenantRepository;
use RuntimeException;

/**
 * Certificates of completion (ASM-005). One per completed enrolment, with a
 * 12-character code (60 random bits) anyone can check on the school's
 * /verify page. Learner name and course title are copied at issue time.
 */
final class CertificateService
{
    /** No 0/O, 1/I: codes are read aloud and typed from paper. */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly TenantRepository $tenants,
    ) {
    }

    /**
     * Issues the certificate for a completed enrolment; returns the existing
     * one if already issued, or null when the school has switched
     * certificates off (certificates issued earlier stay valid).
     */
    public function issueFor(Enrolment $enrolment): ?Certificate
    {
        $existing = $this->certificates->findForEnrolment($enrolment->tenantId, $enrolment->id);
        if ($existing !== null) {
            return $existing;
        }
        $tenant = $this->tenants->findById($enrolment->tenantId);
        if ($tenant === null || !$tenant->certificatesEnabled) {
            return null;
        }
        $course = $this->courses->findById($enrolment->tenantId, $enrolment->courseId);
        $user = $this->users->findById($enrolment->userId);
        if ($course === null || $user === null) {
            throw new RuntimeException('Cannot issue a certificate without its course and learner.');
        }
        for ($try = 0; $try < 5; $try++) {
            if ($this->certificates->insert($enrolment->tenantId, $enrolment->id, $course->id, $user->id, self::newCode(), $user->displayName, $course->title)) {
                $certificate = $this->certificates->findForEnrolment($enrolment->tenantId, $enrolment->id)
                    ?? throw new RuntimeException('Certificate not stored.');
                $this->audit->record($enrolment->tenantId, $enrolment->userId, 'certificate.issued', 'certificate', $certificate->id);

                return $certificate;
            }
        }
        throw new RuntimeException('Could not find a free certificate code.');
    }

    /**
     * The certificate for this enrolment, issuing it if the course is complete
     * (covers learners who finished before certificates existed).
     */
    public function forEnrolment(?Enrolment $enrolment): ?Certificate
    {
        if ($enrolment === null) {
            return null;
        }
        $existing = $this->certificates->findForEnrolment($enrolment->tenantId, $enrolment->id);
        if ($existing !== null || !$enrolment->isCompleted()) {
            return $existing;
        }

        return $this->issueFor($enrolment);
    }

    public function find(int $tenantId, string $rawCode): ?Certificate
    {
        $code = self::normalise($rawCode);

        return $code === null ? null : $this->certificates->findByCode($tenantId, $code);
    }

    /** @return list<Certificate> */
    public function forUser(int $tenantId, int $userId): array
    {
        return $this->certificates->forUser($tenantId, $userId);
    }

    public function revoke(string $rawCode, string $reason): Certificate
    {
        $code = self::normalise($rawCode);
        $certificate = $code === null ? null : $this->certificates->findByCodeAnyTenant($code);
        if ($certificate === null) {
            throw new RuntimeException('No certificate with that code.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give a reason (up to 500 characters); it is shown on the verification page.');
        }
        $this->certificates->revoke($certificate->id, $reason);
        $this->audit->record($certificate->tenantId, null, 'certificate.revoked', 'certificate', $certificate->id, ['reason' => $reason]);

        return $this->certificates->findByCodeAnyTenant($certificate->code) ?? $certificate;
    }

    /** Accepts "abcd-efgh-jklm", spaces, any case. Null when it cannot be a code. */
    public static function normalise(string $raw): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));

        return preg_match('/^[' . self::ALPHABET . ']{12}$/', $code) === 1 ? $code : null;
    }

    private static function newCode(): string
    {
        $code = '';
        foreach (str_split(random_bytes(12)) as $byte) {
            $code .= self::ALPHABET[ord($byte) & 31];
        }

        return $code;
    }
}
