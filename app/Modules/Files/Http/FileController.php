<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Modules\Assignments\SubmissionRepository;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Files\StoredFile;
use App\Modules\Files\UploadPolicy;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Learning\EnrolmentRepository;

/**
 * Every download goes through here: files are never reachable by URL from
 * the web server. Anyone not allowed to see a file gets "not found", so ids
 * reveal nothing.
 */
final class FileController
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly FileService $service,
        private readonly LessonRepository $lessons,
        private readonly CourseRepository $courses,
        private readonly EnrolmentRepository $enrolments,
        private readonly SubmissionRepository $submissions,
        private readonly ?\App\Modules\Support\SupportService $support = null,
    ) {
    }

    public function download(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $file = $this->files->find($tenant->id, $request->routeInt('file'));
        if ($file === null || !$this->mayRead($file, $user)) {
            throw HttpException::notFound();
        }

        $inline = UploadPolicy::opensInBrowser($file->extension) && $request->query('download') !== '1';
        $response = Response::file($this->service->pathFor($file), $file->mimeType, $file->originalName, $inline);
        if ($file->extension !== 'pdf') {
            // Shown as an isolated document: no scripts, no access to this site, even if a browser misreads it.
            $response = $response->withHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }

        return $response->withHeader('X-Robots-Tag', 'noindex');
    }

    private function mayRead(StoredFile $file, CurrentUser $user): bool
    {
        if ($file->lessonId !== null) {
            $lesson = $this->lessons->findById($file->tenantId, $file->lessonId);
            $course = $lesson === null ? null : $this->courses->findById($file->tenantId, $lesson->courseId);
            if ($course === null) {
                return false;
            }
            if ($user->can(Permission::CourseManage)) {
                return true;
            }

            return $course->isPublished() && $this->enrolments->find($file->tenantId, $course->id, $user->user->id) !== null;
        }
        if ($file->submissionId !== null) {
            $submission = $this->submissions->find($file->tenantId, $file->submissionId);

            return $submission !== null && ($submission->userId === $user->user->id || $user->can(Permission::CourseManage));
        }
        if ($file->supportMessageId !== null) {
            return $this->support?->mayReadMessageFile($file->tenantId, $user, $file->supportMessageId) ?? false;
        }

        return false;
    }
}
