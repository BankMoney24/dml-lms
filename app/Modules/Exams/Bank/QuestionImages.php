<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

use App\Core\Database\Connection;
use App\Core\Http\UploadedFile;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Files\UploadPolicy;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * One picture per bank question (ADR 0044): a diagram, graph, map or circuit.
 *
 * The picture is checked and scanned like any upload, stored outside the web
 * root, and served through /files only to teachers and to learners who have
 * the question in one of their papers (or can practise from its bank). Every
 * picture needs a written description for candidates who cannot see it.
 */
final class QuestionImages
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    public const MAX_BYTES = 2_000_000;

    public function __construct(
        private readonly BankRepository $banks,
        private readonly FileService $files,
        private readonly FileRepository $fileRows,
        private readonly Connection $db,
        private readonly AuditLog $audit,
    ) {
    }

    /** Adds or replaces the picture. */
    public function attach(Tenant $tenant, CurrentUser $by, BankQuestion $question, ?UploadedFile $upload, string $alt): void
    {
        $by->authorise(Permission::CourseManage);
        $alt = self::alt($alt);
        if ($upload === null || !$upload->wasSent()) {
            throw ValidationException::single('image', 'Choose a picture to add.');
        }
        $extension = UploadPolicy::extensionOf($upload->clientName);
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::single('image', 'Choose a PNG, JPEG, WebP or GIF picture.');
        }
        if ($upload->isOk() && $upload->size > self::MAX_BYTES) {
            throw ValidationException::single('image', 'Choose a picture under 2 MB. Crop it, or save it again at a smaller size: candidates on mobile data will thank you.');
        }
        $prepared = $this->files->prepare($tenant->id, [$upload], 'image', false);
        $old = $question->imageFileId === null ? null : $this->fileRows->find($tenant->id, $question->imageFileId);
        $this->db->transaction(function () use ($tenant, $by, $question, $prepared, $alt, $old): void {
            $file = $this->files->persist($tenant->id, $by->user->id, $prepared, null, null, null, null, $question->id)[0];
            $this->banks->setImage($tenant->id, $question->id, $file->id, $alt);
            if ($old !== null) {
                $this->fileRows->delete($tenant->id, $old->id);
            }
            $this->audit->record($tenant->id, $by->user->id, 'bank.question_image', 'bank_question', $question->id, ['file' => $file->originalName]);
        });
        if ($old !== null) {
            $this->files->deleteBlobs([$old->storageKey]);
        }
    }

    /** Changes only the description. */
    public function describe(Tenant $tenant, CurrentUser $by, BankQuestion $question, string $alt): void
    {
        $by->authorise(Permission::CourseManage);
        if ($question->imageFileId === null) {
            throw ValidationException::single('image', 'This question has no picture yet.');
        }
        $this->banks->setImage($tenant->id, $question->id, $question->imageFileId, self::alt($alt));
    }

    public function remove(Tenant $tenant, CurrentUser $by, BankQuestion $question): void
    {
        $by->authorise(Permission::CourseManage);
        if ($question->imageFileId === null) {
            return;
        }
        $file = $this->fileRows->find($tenant->id, $question->imageFileId);
        $this->db->transaction(function () use ($tenant, $by, $question, $file): void {
            $this->banks->setImage($tenant->id, $question->id, null, null);
            if ($file !== null) {
                $this->fileRows->delete($tenant->id, $file->id);
            }
            $this->audit->record($tenant->id, $by->user->id, 'bank.question_image_removed', 'bank_question', $question->id);
        });
        if ($file !== null) {
            $this->files->deleteBlobs([$file->storageKey]);
        }
    }

    /** Teachers; learners who have the question in a paper; learners who may practise from its bank. */
    public function mayRead(Tenant $tenant, CurrentUser $user, int $questionId): bool
    {
        if ($user->can(Permission::CourseManage)) {
            return true;
        }
        $question = $this->banks->findQuestion($tenant->id, $questionId);
        if ($question === null) {
            return false;
        }
        $bank = $this->banks->findBank($tenant->id, $question->bankId);
        if ($bank !== null && $bank->practice && $bank->archivedAt === null && $user->can(Permission::CourseLearn)) {
            return true;
        }

        return $this->banks->inPaperOf($tenant->id, $user->user->id, $questionId);
    }

    private static function alt(string $alt): string
    {
        $alt = trim((string) preg_replace('/\s+/u', ' ', $alt));
        if (mb_strlen($alt) < 3 || mb_strlen($alt) > 300) {
            throw ValidationException::single('image_alt', 'Describe the picture in a sentence (up to 300 characters), for candidates who use a screen reader or whose phone cannot load it. '
                . 'For example: “A circuit: a 6 V battery and two 3 Ω resistors in series.”');
        }

        return $alt;
    }
}
