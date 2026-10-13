<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

/**
 * Puts a line in someone's notification centre (NTF-001). Callers pass a
 * general title and a link inside the site; the link is kept only if it is
 * a same-site path, so a notification can never send anyone elsewhere.
 * Runs inside the caller's transaction: no event, no notification.
 */
final class Notifier
{
    public const ASSIGNMENT_SUBMITTED = 'assignment.submitted';
    public const ASSIGNMENT_REVIEWED = 'assignment.reviewed';
    public const COURSE_SET = 'group.course_set';
    public const GROUP_JOINED = 'group.joined';
    public const GROUP_TEACHING = 'group.teaching';
    public const ANNOUNCEMENT = 'group.announcement';
    public const PAYMENT_PAID = 'payment.paid';
    public const CERTIFICATE = 'certificate.issued';

    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function notify(int $tenantId, int $userId, string $kind, string $title, ?string $body = null, ?string $link = null): void
    {
        $this->notifications->add(
            $tenantId,
            $userId,
            $kind,
            mb_strimwidth(trim($title), 0, 200, '…'),
            $body === null || trim($body) === '' ? null : mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $body)), 0, 500, '…'),
            self::safeLink($link),
        );
    }

    /** @param list<int> $userIds */
    public function notifyMany(int $tenantId, array $userIds, string $kind, string $title, ?string $body = null, ?string $link = null): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->notify($tenantId, $userId, $kind, $title, $body, $link);
        }
    }

    public static function safeLink(?string $link): ?string
    {
        if ($link === null || $link === '' || !str_starts_with($link, '/') || str_starts_with($link, '//') || str_contains($link, '\\') || mb_strlen($link) > 300) {
            return null;
        }

        return $link;
    }
}
