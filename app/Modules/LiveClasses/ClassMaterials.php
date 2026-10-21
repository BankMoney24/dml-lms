<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses;

use App\Core\Http\HttpException;
use App\Core\Http\UploadedFile;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Files\StoredFile;
use App\Modules\Files\UploadPolicy;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Tenancy\Tenant;

/**
 * Files that go with a live class (ADR 0029): slides, notes and worksheets
 * shared before the class, so learners can download them on a better
 * connection, and the recording afterwards. Only the class's teachers add or
 * remove them; its learners and teachers can open them.
 */
final class ClassMaterials
{
    public const MAX_PER_CLASS = 20;

    public function __construct(
        private readonly LiveClasses $live,
        private readonly ClassSessionRepository $sessions,
        private readonly GroupRepository $groups,
        private readonly FileService $fileService,
        private readonly FileRepository $files,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<StoredFile> */
    public function list(Tenant $tenant, ClassSession $session): array
    {
        return $this->files->forClassSession($tenant->id, $session->id);
    }

    public function recording(Tenant $tenant, ClassSession $session): ?StoredFile
    {
        if ($session->recordingFileId === null) {
            return null;
        }
        $file = $this->files->find($tenant->id, $session->recordingFileId);

        return $file !== null && $file->classSessionId === $session->id ? $file : null;
    }

    /** Adds a file; as the recording, it must be video or audio and replaces any earlier recording. */
    public function add(Tenant $tenant, ClassSession $session, CurrentUser $teacher, UploadedFile $upload, bool $asRecording, string $clientIp): StoredFile
    {
        $this->authorise($tenant, $session, $teacher);
        if (count($this->files->forClassSession($tenant->id, $session->id)) >= self::MAX_PER_CLASS) {
            throw ValidationException::single('material', sprintf('A class can have up to %d files. Remove one first.', self::MAX_PER_CLASS));
        }
        if ($asRecording && $upload->isOk() && UploadPolicy::mediaKind(UploadPolicy::extensionOf(FileService::cleanName($upload->clientName))) === null) {
            throw ValidationException::single('material', 'A recording must be a video (MP4, WebM) or audio file (MP3, M4A, OGG, WAV).');
        }
        $prepared = $this->fileService->prepare($tenant->id, [$upload], 'material');
        $file = $this->fileService->persist($tenant->id, $teacher->user->id, $prepared, null, null, null, $session->id)[0];
        if ($asRecording) {
            $previous = $this->recording($tenant, $session);
            $this->sessions->setRecordingFile($tenant->id, $session->id, $file->id);
            if ($previous !== null) {
                $this->files->delete($tenant->id, $previous->id);
                $this->fileService->deleteBlobs([$previous->storageKey]);
            }
        }
        $this->audit->record($tenant->id, $teacher->user->id, $asRecording ? 'class.recording_added' : 'class.material_added', 'class_session', $session->id, [
            'file' => $file->originalName,
            'bytes' => $file->sizeBytes,
        ], $clientIp);

        return $file;
    }

    public function remove(Tenant $tenant, ClassSession $session, CurrentUser $teacher, int $fileId, string $clientIp): StoredFile
    {
        $this->authorise($tenant, $session, $teacher);
        $file = $this->files->find($tenant->id, $fileId);
        if ($file === null || $file->classSessionId !== $session->id) {
            throw HttpException::notFound();
        }
        if ($session->recordingFileId === $file->id) {
            $this->sessions->setRecordingFile($tenant->id, $session->id, null);
        }
        $this->files->delete($tenant->id, $file->id);
        $this->fileService->deleteBlobs([$file->storageKey]);
        $this->audit->record($tenant->id, $teacher->user->id, 'class.material_removed', 'class_session', $session->id, ['file' => $file->originalName], $clientIp);

        return $file;
    }

    private function authorise(Tenant $tenant, ClassSession $session, CurrentUser $teacher): void
    {
        $group = $this->groups->find($tenant->id, $session->groupId) ?? throw HttpException::notFound();
        if (!$this->live->mayTeach($tenant, $group, $teacher)) {
            throw HttpException::notFound();
        }
    }
}
