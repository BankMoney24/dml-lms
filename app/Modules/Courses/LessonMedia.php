<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Http\HttpException;
use App\Core\Http\UploadedFile;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Files\StoredFile;
use App\Modules\Files\UploadPolicy;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;

/**
 * The uploaded video or recording that a video or audio lesson is built
 * around (ADR 0029). It is stored as one of the lesson's files, so the usual
 * rules decide who may play it, and the lesson points at it. Uploading a new
 * one replaces the old one.
 */
final class LessonMedia
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly FileRepository $files,
        private readonly LessonRepository $lessons,
        private readonly CourseRepository $courses,
        private readonly AuditLog $audit,
    ) {
    }

    public function upload(Course $course, CurrentUser $author, int $lessonId, UploadedFile $upload): StoredFile
    {
        $this->authorise($course, $author);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId) ?? throw HttpException::notFound();
        if (!$lesson->takesMedia()) {
            throw HttpException::notFound();
        }
        $want = $lesson->isVideo() ? 'video' : 'audio';
        if ($upload->isOk() && UploadPolicy::mediaKind(UploadPolicy::extensionOf(FileService::cleanName($upload->clientName))) !== $want) {
            throw ValidationException::single('media', $lesson->isVideo()
                ? 'Choose a video file: MP4 or WebM. Export other formats (such as .mov) as MP4 first.'
                : 'Choose an audio file: MP3, M4A, OGG, Opus or WAV.');
        }
        $prepared = $this->fileService->prepare($course->tenantId, [$upload], 'media');
        $file = $this->fileService->persist($course->tenantId, $author->user->id, $prepared, $lesson->id, null)[0];
        $this->lessons->setMediaFile($course->tenantId, $lesson->id, $file->id);
        if ($lesson->mediaFileId !== null) {
            $this->discard($course->tenantId, $lesson->mediaFileId);
        }
        $this->courses->touch($course->tenantId, $course->id);
        $this->audit->record($course->tenantId, $author->user->id, 'lesson.media_added', 'lesson', $lesson->id, ['file' => $file->originalName, 'bytes' => $file->sizeBytes]);

        return $file;
    }

    public function remove(Course $course, CurrentUser $author, int $lessonId): void
    {
        $this->authorise($course, $author);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId) ?? throw HttpException::notFound();
        if ($lesson->mediaFileId === null) {
            return;
        }
        $this->lessons->setMediaFile($course->tenantId, $lesson->id, null);
        $this->discard($course->tenantId, $lesson->mediaFileId);
        $this->courses->touch($course->tenantId, $course->id);
        $this->audit->record($course->tenantId, $author->user->id, 'lesson.media_removed', 'lesson', $lesson->id);
    }

    /** The lesson's media file, if it has one and it still exists. */
    public function fileFor(Lesson $lesson): ?StoredFile
    {
        if ($lesson->mediaFileId === null) {
            return null;
        }
        $file = $this->files->find($lesson->tenantId, $lesson->mediaFileId);

        return $file !== null && $file->lessonId === $lesson->id ? $file : null;
    }

    private function discard(int $tenantId, int $fileId): void
    {
        $old = $this->files->find($tenantId, $fileId);
        if ($old === null) {
            return;
        }
        $this->files->delete($tenantId, $old->id);
        $this->fileService->deleteBlobs([$old->storageKey]);
    }

    private function authorise(Course $course, CurrentUser $author): void
    {
        $author->authorise(Permission::CourseManage);
        if ($author->membership === null || $author->membership->tenantId !== $course->tenantId) {
            throw HttpException::forbidden();
        }
    }
}
