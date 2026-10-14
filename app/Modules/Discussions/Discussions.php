<?php

declare(strict_types=1);

namespace App\Modules\Discussions;

use App\Core\Http\HttpException;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\Notifier;

/**
 * Questions and answers under lessons (ADR 0022).
 *  - People enrolled in the course and course builders can read, ask and answer.
 *  - The person who asked, or a teacher, can mark one answer as the answer.
 *  - Authors can delete their own posts; teachers can hide any post (and show it again).
 *  - The course's author and the learner's class teachers hear about new questions;
 *    the person who asked hears about answers.
 */
final class Discussions
{
    public const QUESTION_MIN = 10;
    public const QUESTION_MAX = 3000;
    public const ANSWER_MAX = 5000;

    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly CourseRepository $courses,
        private readonly LearningService $learning,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly ?GroupRepository $groups = null,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /** Whether this person takes part in the course's questions: enrolled, or builds courses here. */
    public function mayTakePart(Course $course, CurrentUser $user): bool
    {
        return $course->isPublished()
            && ($user->can(Permission::CourseManage) || $this->learning->findEnrolment($course, $user) !== null);
    }

    public function ask(Course $course, Lesson $lesson, CurrentUser $user, string $body, string $clientIp): int
    {
        $this->requirePart($course, $user);
        $body = self::clean($body);
        $length = mb_strlen($body);
        if ($length < self::QUESTION_MIN || $length > self::QUESTION_MAX) {
            throw ValidationException::single('question', $length < self::QUESTION_MIN
                ? 'Write your question in a sentence or two, so others can help (at least 10 characters).'
                : 'Keep your question under 3,000 characters. Paste only the part of your code that matters.');
        }
        if ($this->questions->recentSameBody($course->tenantId, $lesson->id, $user->user->id, $body)) {
            throw ValidationException::single('question', 'You have already asked this. Answers will appear under it.');
        }
        if (!$this->limiter->attempt('qa-ask|' . $course->tenantId . '|' . $user->user->id, 10, 3600)) {
            throw ValidationException::single('question', 'You have asked a lot of questions in the last hour. Try again a little later.');
        }
        $id = $this->questions->create($course->tenantId, $course->id, $lesson->id, $user->user->id, $body);
        $this->audit->record($course->tenantId, $user->user->id, 'qa.asked', 'lesson_question', $id, ['lesson' => $lesson->id], $clientIp);

        $recipients = $this->groups?->teachersFor($course->tenantId, $user->user->id, $course->id) ?? [];
        $author = $this->courses->authorId($course->tenantId, $course->id);
        if ($author !== null) {
            $recipients[] = $author;
        }
        $recipients = array_values(array_diff(array_unique($recipients), [$user->user->id]));
        $this->notifier?->notifyMany(
            $course->tenantId,
            $recipients,
            'qa.question',
            'New question on "' . $lesson->title . '"',
            $user->user->displayName . ': ' . mb_strimwidth($body, 0, 140, '…'),
            '/teach/questions#q' . $id,
        );

        return $id;
    }

    public function answer(Course $course, Question $question, CurrentUser $user, string $body, string $clientIp): int
    {
        $this->requirePart($course, $user);
        $this->requireVisible($question);
        $body = self::clean($body);
        if (mb_strlen($body) < 2 || mb_strlen($body) > self::ANSWER_MAX) {
            throw ValidationException::single('answer', mb_strlen($body) < 2 ? 'Write your answer first.' : 'Keep your answer under 5,000 characters.');
        }
        if (!$this->limiter->attempt('qa-answer|' . $course->tenantId . '|' . $user->user->id, 30, 3600)) {
            throw ValidationException::single('answer', 'You have posted a lot of answers in the last hour. Try again a little later.');
        }
        $staff = $user->can(Permission::CourseManage);
        $id = $this->questions->addAnswer($course->tenantId, $question->id, $user->user->id, $body, $staff);
        $this->audit->record($course->tenantId, $user->user->id, 'qa.answered', 'lesson_question', $question->id, ['answer' => $id], $clientIp);
        if ($question->userId !== $user->user->id) {
            $this->notifier?->notify(
                $course->tenantId,
                $question->userId,
                'qa.answer',
                ($staff ? 'Your teacher' : $user->user->displayName) . ' answered your question',
                mb_strimwidth($body, 0, 140, '…'),
                '/courses/' . $course->slug . '/lessons/' . $question->lessonId . '#q' . $question->id,
            );
        }

        return $id;
    }

    /** The person who asked, or a teacher, marks one answer as the one that helped (or clears it). */
    public function accept(Course $course, Question $question, CurrentUser $user, ?int $answerId): void
    {
        $this->requirePart($course, $user);
        $this->requireVisible($question);
        if ($question->userId !== $user->user->id && !$user->can(Permission::CourseManage)) {
            throw HttpException::forbidden();
        }
        $answer = null;
        if ($answerId !== null) {
            $answer = $this->questions->findAnswer($course->tenantId, $answerId);
            if ($answer === null || $answer->questionId !== $question->id || !$answer->isVisible()) {
                throw HttpException::notFound();
            }
        }
        $this->questions->accept($course->tenantId, $question->id, $answer?->id);
        if ($answer !== null && $answer->userId !== $user->user->id && $answer->id !== $question->acceptedAnswerId) {
            $this->notifier?->notify(
                $course->tenantId,
                $answer->userId,
                'qa.accepted',
                'Your answer helped',
                'It was marked as the answer to a question on ' . $course->title . '.',
                '/courses/' . $course->slug . '/lessons/' . $question->lessonId . '#q' . $question->id,
            );
        }
    }

    /** Authors delete their own question or answer. */
    public function deleteQuestion(Course $course, Question $question, CurrentUser $user, string $clientIp): void
    {
        if ($question->userId !== $user->user->id) {
            throw HttpException::forbidden();
        }
        $this->questions->setQuestionStatus($course->tenantId, $question->id, Question::DELETED);
        $this->audit->record($course->tenantId, $user->user->id, 'qa.question_deleted', 'lesson_question', $question->id, [], $clientIp);
    }

    public function deleteAnswer(Course $course, Answer $answer, CurrentUser $user, string $clientIp): void
    {
        if ($answer->userId !== $user->user->id) {
            throw HttpException::forbidden();
        }
        $this->questions->setAnswerStatus($course->tenantId, $answer->id, Answer::DELETED);
        $this->audit->record($course->tenantId, $user->user->id, 'qa.answer_deleted', 'lesson_answer', $answer->id, [], $clientIp);
    }

    /** Teachers hide (or show again) a question or an answer. */
    public function moderate(Course $course, CurrentUser $staff, string $kind, int $id, bool $hide, string $clientIp): void
    {
        $staff->authorise(Permission::CourseManage);
        if ($kind === 'question') {
            $question = $this->questions->find($course->tenantId, $id);
            if ($question === null || $question->courseId !== $course->id || $question->status === Question::DELETED) {
                throw HttpException::notFound();
            }
            $this->questions->setQuestionStatus($course->tenantId, $id, $hide ? Question::HIDDEN : Question::OPEN);
        } else {
            $answer = $this->questions->findAnswer($course->tenantId, $id);
            $question = $answer === null ? null : $this->questions->find($course->tenantId, $answer->questionId);
            if ($answer === null || $question === null || $question->courseId !== $course->id || $answer->status === Answer::DELETED) {
                throw HttpException::notFound();
            }
            $this->questions->setAnswerStatus($course->tenantId, $id, $hide ? Answer::HIDDEN : Answer::VISIBLE);
        }
        $this->audit->record($course->tenantId, $staff->user->id, $hide ? 'qa.hidden' : 'qa.shown', 'lesson_' . $kind, $id, [], $clientIp);
    }

    private function requirePart(Course $course, CurrentUser $user): void
    {
        if (!$this->mayTakePart($course, $user)) {
            throw HttpException::forbidden();
        }
    }

    private function requireVisible(Question $question): void
    {
        if (!$question->isOpen()) {
            throw HttpException::notFound();
        }
    }

    private static function clean(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);

        return trim((string) preg_replace("/\n{4,}/", "\n\n\n", $text));
    }
}
