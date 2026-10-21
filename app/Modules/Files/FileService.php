<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Core\Http\HttpException;
use App\Core\Http\UploadedFile;
use App\Core\Log\Logger;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use RuntimeException;
use Throwable;

/**
 * Stores uploaded files outside the web root, after checking type, size,
 * the school's storage quota and (when configured) viruses. Work happens in
 * two steps so slow checks run before any database transaction:
 *   prepare()  validates and scans, touching nothing;
 *   persist()  moves the file into storage and records it.
 */
final class FileService
{
    public const MAX_PER_LESSON = 20;
    public const MAX_PER_SUBMISSION = 3;

    public function __construct(
        private readonly FileRepository $files,
        private readonly LessonRepository $lessons,
        private readonly UploadPolicy $policy,
        private readonly VirusScanner $scanner,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly string $uploadRoot,
        private readonly int $tenantQuotaBytes,
    ) {
    }

    public function policy(): UploadPolicy
    {
        return $this->policy;
    }

    public function quotaBytes(): int
    {
        return $this->tenantQuotaBytes;
    }

    public function usageBytes(int $tenantId): int
    {
        return $this->files->tenantUsage($tenantId);
    }

    /**
     * Validates and scans files before anything is stored.
     *
     * @param list<UploadedFile> $uploads
     * @param bool $media whether video and audio are welcome here (not in help messages)
     * @return list<PreparedUpload>
     * @throws ValidationException with key $field
     */
    public function prepare(int $tenantId, array $uploads, string $field = 'file', bool $media = true): array
    {
        $prepared = [];
        $total = 0;
        foreach ($uploads as $upload) {
            $extension = UploadPolicy::extensionOf(self::cleanName($upload->clientName));
            if (!$upload->isOk()) {
                throw ValidationException::single($field, $upload->errorMessage($this->policy->webMegabytes(UploadPolicy::mediaKind($extension) !== null)));
            }
            $name = self::cleanName($upload->clientName);
            if (!$media && UploadPolicy::mediaKind($extension) !== null) {
                throw ValidationException::single($field, sprintf('“%s”: video and audio cannot be attached here.', $name));
            }
            $problem = $this->policy->problemWith($upload->tmpPath, $extension);
            if ($problem !== null) {
                throw ValidationException::single($field, sprintf('“%s”: %s', $name, $problem));
            }
            $size = (int) filesize($upload->tmpPath);
            $total += $size;
            try {
                $scan = $this->scanner->scan($upload->tmpPath);
            } catch (InfectedFile $e) {
                $this->audit->record($tenantId, null, 'file.rejected_infected', null, null, ['name' => $name, 'signature' => $e->getMessage()]);
                throw ValidationException::single($field, sprintf('“%s” was refused: the virus scanner flagged it as unsafe.', $name));
            } catch (ScanTooLarge $e) {
                $this->logger->error('Upload refused: larger than the virus scanner accepts', ['error' => $e->getMessage(), 'bytes' => $upload->size]);
                throw ValidationException::single($field, sprintf(
                    '“%s” is bigger than the virus scanner is set to check. Ask an administrator to raise StreamMaxLength in clamd, or upload a smaller file.',
                    $name,
                ));
            } catch (ScannerUnavailable $e) {
                $this->logger->error('Upload refused: virus scanner unavailable', ['error' => $e->getMessage()]);
                throw ValidationException::single($field, 'Files cannot be checked for viruses right now, so they cannot be accepted. Please try again later.');
            }
            $prepared[] = new PreparedUpload($upload, $name, $extension, $size, $scan);
        }
        if ($total > 0 && $this->files->tenantUsage($tenantId) + $total > $this->tenantQuotaBytes) {
            throw ValidationException::single($field, 'Your school has used all of its file storage. Ask an administrator to remove old files or raise the limit.');
        }

        return $prepared;
    }

    /**
     * Moves prepared files into storage and records them. If anything fails,
     * files already moved are removed again, so nothing is left half-stored.
     *
     * @param list<PreparedUpload> $prepared
     * @return list<StoredFile>
     */
    public function persist(
        int $tenantId,
        int $userId,
        array $prepared,
        ?int $lessonId,
        ?int $submissionId,
        ?int $supportMessageId = null,
        ?int $classSessionId = null,
    ): array {
        if (count(array_filter([$lessonId, $submissionId, $supportMessageId, $classSessionId], static fn (?int $id): bool => $id !== null)) !== 1) {
            throw new \LogicException('A file belongs to exactly one lesson, submission, help message or class.');
        }
        $stored = [];
        $movedPaths = [];
        try {
            foreach ($prepared as $p) {
                $key = sprintf('%d/%s/%s', $tenantId, gmdate('Y/m'), bin2hex(random_bytes(16)));
                $path = $this->uploadRoot . '/' . $key;
                $this->ensureDirectory(dirname($path));
                $p->upload->moveTo($path);
                $movedPaths[] = $path;
                @chmod($path, 0640);
                $id = $this->files->insert($tenantId, [
                    'lesson_id' => $lessonId,
                    'submission_id' => $submissionId,
                    'support_message_id' => $supportMessageId,
                    'class_session_id' => $classSessionId,
                    'uploaded_by' => $userId,
                    'original_name' => $p->name,
                    'extension' => $p->extension,
                    'mime_type' => UploadPolicy::servedType($p->extension),
                    'size_bytes' => $p->size,
                    'sha256' => (string) hash_file('sha256', $path),
                    'storage_key' => $key,
                    'scan_status' => $p->scanStatus,
                ]);
                $stored[] = $this->files->find($tenantId, $id) ?? throw new RuntimeException('File record missing.');
            }
        } catch (Throwable $e) {
            foreach ($movedPaths as $path) {
                @unlink($path);
            }
            throw $e;
        }

        return $stored;
    }

    public function attachToLesson(Course $course, CurrentUser $author, int $lessonId, UploadedFile $upload): StoredFile
    {
        $this->authorise($course, $author);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId) ?? throw HttpException::notFound();
        if (count($this->files->forLesson($course->tenantId, $lesson->id)) >= self::MAX_PER_LESSON) {
            throw ValidationException::single('file', sprintf('A lesson can have up to %d files. Remove one first.', self::MAX_PER_LESSON));
        }
        $prepared = $this->prepare($course->tenantId, [$upload]);
        $file = $this->persist($course->tenantId, $author->user->id, $prepared, $lesson->id, null)[0];
        $this->audit->record($course->tenantId, $author->user->id, 'lesson.file_added', 'lesson', $lesson->id, ['file' => $file->originalName]);

        return $file;
    }

    public function removeFromLesson(Course $course, CurrentUser $author, int $lessonId, int $fileId): StoredFile
    {
        $this->authorise($course, $author);
        $file = $this->files->find($course->tenantId, $fileId);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId);
        if ($file === null || $lesson === null || $file->lessonId !== $lesson->id) {
            throw HttpException::notFound();
        }
        $this->files->delete($course->tenantId, $file->id);
        $this->lessons->forgetMediaFile($course->tenantId, $file->id);
        $this->deleteBlobs([$file->storageKey]);
        $this->audit->record($course->tenantId, $author->user->id, 'lesson.file_removed', 'lesson', $lesson->id, ['file' => $file->originalName]);

        return $file;
    }

    /** Absolute path of a stored file, refusing anything that would point outside the upload folder. */
    public function pathFor(StoredFile $file): string
    {
        if (preg_match('#^\d+/\d{4}/\d{2}/[a-f0-9]{32}$#', $file->storageKey) !== 1) {
            throw new RuntimeException('Invalid storage key.');
        }
        $path = $this->uploadRoot . '/' . $file->storageKey;
        if (!is_file($path)) {
            throw HttpException::notFound();
        }

        return $path;
    }

    /** @param list<string> $keys */
    public function deleteBlobs(array $keys): void
    {
        foreach ($keys as $key) {
            if (preg_match('#^\d+/\d{4}/\d{2}/[a-f0-9]{32}$#', $key) === 1) {
                @unlink($this->uploadRoot . '/' . $key);
            }
        }
    }

    /**
     * Creates missing folders one level at a time, group-writable: the web app
     * and console commands (course imports) run as different users who share
     * a group, and either may be the first to create a month's folder.
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $this->ensureDirectory(dirname($dir));
        if (!@mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the upload folder.');
        }
        @chmod($dir, 02770);
    }

    /** Keeps the visible name, drops paths, control characters and anything risky in a header. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .");
        if (mb_strlen($name) > 150) {
            $extension = UploadPolicy::extensionOf($name);
            $name = mb_substr($name, 0, 140) . ($extension === '' ? '' : '.' . $extension);
        }

        return $name === '' ? 'file' : $name;
    }

    private function authorise(Course $course, CurrentUser $author): void
    {
        $author->authorise(Permission::CourseManage);
        if ($author->membership === null || $author->membership->tenantId !== $course->tenantId) {
            throw HttpException::forbidden();
        }
    }
}
