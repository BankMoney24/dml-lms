<?php

declare(strict_types=1);

namespace App\Modules\Assignments;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Mail\MailSettings;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Core\Http\UploadedFile;
use App\Modules\Courses\Course;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\Enrolment;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\EmailComposer;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Tenancy\Tenant;

/**
 * Assignment lessons (ASM-003): learners submit text and an optional link;
 * a teacher approves it (the lesson is then complete) or asks for changes
 * (the learner submits again). Every submission is kept as history.
 */
final class AssignmentService
{
    public const MAX_BODY = 20000;
    public const MAX_FEEDBACK = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly SubmissionRepository $submissions,
        private readonly LessonRepository $lessons,
        private readonly CourseRepository $courses,
        private readonly EnrolmentRepository $enrolments,
        private readonly LearningService $learning,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly MailSettings $mail,
        private readonly MailOutbox $outbox,
        private readonly EmailComposer $composer,
        private readonly FileService $files,
        private readonly FileRepository $fileRecords,
    ) {
    }

    /**
     * Records the learner's work. While the previous submission is still
     * waiting for review it is replaced (a correction, or a double click);
     * after a review a new submission is added.
     */
    /** @param list<UploadedFile> $uploads files attached to this submission */
    public function submit(Enrolment $enrolment, Lesson $lesson, string $body, string $link, string $clientIp, array $uploads = []): Submission
    {
        if (!$lesson->isAssignment() || $lesson->tenantId !== $enrolment->tenantId || $lesson->courseId !== $enrolment->courseId) {
            throw HttpException::notFound();
        }
        $body = trim(str_replace("\r\n", "\n", $body));
        $link = trim($link);
        $errors = [];
        if ($body === '' && $uploads === []) {
            $errors['answer'] = 'Write your answer, or attach your work, before submitting.';
        } elseif (mb_strlen($body) > self::MAX_BODY) {
            $errors['answer'] = 'Keep your answer under 20,000 characters. Put long work in a document and share the link.';
        }
        if ($link !== '' && (mb_strlen($link) > 500 || preg_match('#^https?://[^\s<>"]+\.[^\s<>"]+$#i', $link) !== 1)) {
            $errors['link'] = 'Enter a full web address starting with https://, or leave it empty.';
        }
        if (count($uploads) > FileService::MAX_PER_SUBMISSION) {
            $errors['attachments'] = sprintf('Attach at most %d files. Put more in a zip archive.', FileService::MAX_PER_SUBMISSION);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $linkOrNull = $link === '' ? null : $link;
        // Type, size, quota and virus checks happen before the transaction.
        $prepared = $this->files->prepare($enrolment->tenantId, $uploads, 'attachments');

        return $this->db->transaction(function () use ($enrolment, $lesson, $body, $linkOrNull, $clientIp, $prepared): Submission {
            $latest = $this->submissions->latest($enrolment->tenantId, $enrolment->id, $lesson->id, true);
            if ($latest !== null && $latest->isApproved()) {
                throw ValidationException::single('answer', 'This assignment has already been approved.');
            }
            if ($latest !== null && $latest->isPending()) {
                $already = $this->fileRecords->countForSubmission($enrolment->tenantId, $latest->id);
                if ($already + count($prepared) > FileService::MAX_PER_SUBMISSION) {
                    throw ValidationException::single('attachments', sprintf(
                        'This submission already has %d of %d files. Attach fewer.',
                        $already,
                        FileService::MAX_PER_SUBMISSION,
                    ));
                }
                $this->submissions->replacePending($enrolment->tenantId, $latest->id, $body, $linkOrNull);
                $id = $latest->id;
            } else {
                $id = $this->submissions->insert($enrolment->tenantId, $lesson->id, $enrolment->id, $enrolment->userId, $body, $linkOrNull);
            }
            if ($prepared !== []) {
                $this->files->persist($enrolment->tenantId, $enrolment->userId, $prepared, null, $id);
            }
            $this->audit->record($enrolment->tenantId, $enrolment->userId, 'assignment.submitted', 'assignment_submission', $id, [
                'lesson_id' => $lesson->id,
            ], $clientIp);

            return $this->submissions->find($enrolment->tenantId, $id) ?? throw new \LogicException('Submission not stored.');
        });
    }

    /**
     * @param 'approve'|'changes'|string $decision
     */
    public function review(Tenant $tenant, CurrentUser $reviewer, int $submissionId, string $decision, string $feedback, string $baseUrl, string $clientIp): Submission
    {
        $reviewer->authorise(Permission::CourseManage);
        $feedback = trim(str_replace("\r\n", "\n", $feedback));
        if (!in_array($decision, ['approve', 'changes'], true)) {
            throw ValidationException::single('decision', 'Choose to approve the work or ask for changes.');
        }
        if ($decision === 'changes' && mb_strlen($feedback) < 3) {
            throw ValidationException::single('feedback', 'Tell the learner what to change.');
        }
        if (mb_strlen($feedback) > self::MAX_FEEDBACK) {
            throw ValidationException::single('feedback', 'Keep feedback under 5,000 characters.');
        }

        $tenantId = $tenant->id;

        return $this->db->transaction(function () use ($tenant, $tenantId, $reviewer, $submissionId, $decision, $feedback, $baseUrl, $clientIp): Submission {
            $submission = $this->submissions->find($tenantId, $submissionId, true) ?? throw HttpException::notFound();
            if ($submission->userId === $reviewer->user->id) {
                throw ValidationException::single('decision', 'You cannot review your own work. Ask another teacher.');
            }
            $latest = $this->submissions->latest($tenantId, $submission->enrolmentId, $submission->lessonId);
            if (!$submission->isPending() || $latest === null || $latest->id !== $submission->id) {
                throw ValidationException::single('decision', 'This submission has already been reviewed or replaced by a newer one.');
            }
            $lesson = $this->lessons->findById($tenantId, $submission->lessonId) ?? throw HttpException::notFound();
            $enrolment = $this->enrolments->findById($tenantId, $submission->enrolmentId) ?? throw HttpException::notFound();
            $course = $this->courses->findById($tenantId, $lesson->courseId) ?? throw HttpException::notFound();

            $status = $decision === 'approve' ? Submission::APPROVED : Submission::CHANGES_REQUESTED;
            $this->submissions->review($tenantId, $submission->id, $status, $feedback, $reviewer->user->id);
            if ($status === Submission::APPROVED) {
                $this->learning->recordLessonCompleted($enrolment, $lesson);
            }
            $this->audit->record($tenantId, $reviewer->user->id, 'assignment.reviewed', 'assignment_submission', $submission->id, [
                'decision' => $status,
            ], $clientIp);
            $this->notify($tenant, $course, $lesson, $submission->userId, $status, $feedback, $reviewer, $baseUrl);

            return $this->submissions->find($tenantId, $submission->id) ?? throw new \LogicException('Submission vanished.');
        });
    }

    /** Queued in the same transaction as the review, so the email exists only if the review does. */
    private function notify(Tenant $tenant, Course $course, Lesson $lesson, int $learnerId, string $status, string $feedback, CurrentUser $reviewer, string $baseUrl): void
    {
        if (!$this->mail->deliveryAvailable()) {
            return;
        }
        $learner = $this->users->findById($learnerId);
        if ($learner === null) {
            return;
        }
        $approved = $status === Submission::APPROVED;
        $this->outbox->queue($course->tenantId, $this->composer->compose(
            'assignment_reviewed',
            $learner->email,
            $learner->displayName,
            ($approved ? 'Approved: ' : 'Changes requested: ') . $lesson->title,
            [
                'schoolName' => $tenant->name,
                'name' => $learner->displayName,
                'approved' => $approved,
                'lessonTitle' => $lesson->title,
                'courseTitle' => $course->title,
                'reviewer' => $reviewer->user->displayName,
                'feedback' => $feedback,
                'link' => $baseUrl . '/courses/' . $course->slug . '/lessons/' . $lesson->id,
            ],
        ));
    }
}
