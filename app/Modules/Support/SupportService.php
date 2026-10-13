<?php

declare(strict_types=1);

namespace App\Modules\Support;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\UploadedFile;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Files\FileService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;

/**
 * Help requests (SUP-001). Anyone signed in asks; the school's support staff
 * (support.manage) answer. Each person sees only their own requests; staff
 * see their school's. Attachments go through the same type, size, quota and
 * virus checks as every other upload, and download only for those two sides.
 */
final class SupportService
{
    public const MAX_UNRESOLVED = 5;
    public const MAX_FILES = 3;
    public const MAX_BODY = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly SupportRepository $requests,
        private readonly FileService $files,
        private readonly AuditLog $audit,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    public function isStaff(CurrentUser $user): bool
    {
        return $user->can(Permission::SupportManage);
    }

    /** The request, if this person may see it; "not found" otherwise. */
    public function requestFor(Tenant $tenant, CurrentUser $user, int $id): SupportRequest
    {
        $request = $this->requests->find($tenant->id, $id);
        if ($request === null || ($request->userId !== $user->user->id && !$this->isStaff($user))) {
            throw HttpException::notFound();
        }

        return $request;
    }

    /** @param list<UploadedFile> $uploads */
    public function open(Tenant $tenant, CurrentUser $user, string $category, string $subject, string $body, array $uploads, string $clientIp): int
    {
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));
        $body = trim(str_replace("\r\n", "\n", $body));
        $errors = [];
        if (!isset(SupportRequest::CATEGORIES[$category])) {
            $errors['category'] = 'Choose what your question is about.';
        }
        if (mb_strlen($subject) < 4 || mb_strlen($subject) > 160) {
            $errors['subject'] = 'Sum it up in a few words, for example "Cannot open the subnet lab".';
        }
        $errors += $this->bodyErrors($body, $uploads);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->requests->openCountFor($tenant->id, $user->user->id) >= self::MAX_UNRESOLVED) {
            throw ValidationException::single('subject', sprintf('You already have %d requests waiting. Add to one of those instead, or mark one resolved.', self::MAX_UNRESOLVED));
        }
        $prepared = $this->files->prepare($tenant->id, $uploads, 'attachments');

        $id = $this->db->transaction(function () use ($tenant, $user, $category, $subject, $body, $prepared): int {
            $id = $this->requests->create($tenant->id, $user->user->id, $category, $subject);
            $message = $this->requests->addMessage($tenant->id, $id, $user->user->id, false, $body);
            if ($prepared !== []) {
                $this->files->persist($tenant->id, $user->user->id, $prepared, null, null, $message);
            }
            $staff = array_values(array_filter($this->requests->staffIds($tenant->id), static fn (int $s): bool => $s !== $user->user->id));
            $this->notifier?->notifyMany($tenant->id, $staff, 'support.opened', 'New help request: ' . $subject, sprintf('From %s.', $user->user->displayName), '/help/' . $id);

            return $id;
        });
        $this->audit->record($tenant->id, $user->user->id, 'support.opened', 'support_request', $id, ['category' => $category], $clientIp);

        return $id;
    }

    /** @param list<UploadedFile> $uploads */
    public function reply(Tenant $tenant, CurrentUser $user, int $requestId, string $body, array $uploads, string $clientIp): void
    {
        $request = $this->requestFor($tenant, $user, $requestId);
        $body = trim(str_replace("\r\n", "\n", $body));
        $errors = $this->bodyErrors($body, $uploads);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $asStaff = $this->isStaff($user) && $request->userId !== $user->user->id;
        $prepared = $this->files->prepare($tenant->id, $uploads, 'attachments');

        $this->db->transaction(function () use ($tenant, $user, $request, $body, $prepared, $asStaff): void {
            $this->requests->find($tenant->id, $request->id, true);
            $message = $this->requests->addMessage($tenant->id, $request->id, $user->user->id, $asStaff, $body);
            if ($prepared !== []) {
                $this->files->persist($tenant->id, $user->user->id, $prepared, null, null, $message);
            }
            $this->requests->setStatus($tenant->id, $request->id, $asStaff ? SupportRequest::ANSWERED : SupportRequest::OPEN);
            if ($asStaff) {
                $this->notifier?->notify($tenant->id, $request->userId, 'support.answered', 'The school answered: ' . $request->subject, null, '/help/' . $request->id);
            } else {
                $staff = array_values(array_filter($this->requests->staffIds($tenant->id), static fn (int $s): bool => $s !== $user->user->id));
                $this->notifier?->notifyMany($tenant->id, $staff, 'support.reply', 'New reply: ' . $request->subject, sprintf('From %s.', $user->user->displayName), '/help/' . $request->id);
            }
        });
        $this->audit->record($tenant->id, $user->user->id, 'support.replied', 'support_request', $request->id, ['staff' => $asStaff], $clientIp);
    }

    public function setResolved(Tenant $tenant, CurrentUser $user, int $requestId, bool $resolved, string $clientIp): void
    {
        $request = $this->requestFor($tenant, $user, $requestId);
        $this->requests->setStatus($tenant->id, $request->id, $resolved ? SupportRequest::RESOLVED : SupportRequest::OPEN);
        if ($resolved && $request->userId !== $user->user->id) {
            $this->notifier?->notify(
                $tenant->id,
                $request->userId,
                'support.resolved',
                'Marked resolved: ' . $request->subject,
                'Reply on the request if you still need help.',
                '/help/' . $request->id,
            );
        }
        $this->audit->record($tenant->id, $user->user->id, $resolved ? 'support.resolved' : 'support.reopened', 'support_request', $request->id, [], $clientIp);
    }

    /** May this person download a file attached to this help message? */
    public function mayReadMessageFile(int $tenantId, CurrentUser $user, int $messageId): bool
    {
        $request = $this->requests->requestOfMessage($tenantId, $messageId);

        return $request !== null && ($request->userId === $user->user->id || $this->isStaff($user));
    }

    /**
     * @param list<UploadedFile> $uploads
     * @return array<string, string>
     */
    private function bodyErrors(string $body, array $uploads): array
    {
        $errors = [];
        if (mb_strlen($body) < 2) {
            $errors['body'] = 'Write what happened, or what you need.';
        } elseif (mb_strlen($body) > self::MAX_BODY) {
            $errors['body'] = 'Keep it under 5,000 characters.';
        }
        if (count($uploads) > self::MAX_FILES) {
            $errors['attachments'] = sprintf('Attach at most %d files, for example screenshots.', self::MAX_FILES);
        }

        return $errors;
    }
}
