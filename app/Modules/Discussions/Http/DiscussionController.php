<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Discussions\Discussions;
use App\Modules\Discussions\Question;
use App\Modules\Discussions\QuestionRepository;
use App\Modules\Identity\Permission;

/** Asking and answering under lessons, and the teachers' question inbox (ADR 0022). */
final class DiscussionController
{
    /** A question or answer that failed validation, kept so nothing typed is lost. */
    public const DRAFT_FLASH = 'qa_draft';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly QuestionRepository $questions,
        private readonly Discussions $discussions,
    ) {
    }

    public function ask(Request $request): Response
    {
        $course = $this->course($request);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $request->routeInt('lesson')) ?? throw HttpException::notFound();
        $body = $request->input('question', '') ?? '';
        try {
            $id = $this->discussions->ask($course, $lesson, RequestContext::user($request), $body, $request->clientIp);
        } catch (ValidationException $e) {
            $this->keepDraft('question', $lesson->id, $body, $e);

            return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id . '#ask');
        }
        $this->session->flash('success', 'Question posted. You will get a notification when someone answers.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id . '#q' . $id);
    }

    public function answer(Request $request): Response
    {
        $course = $this->course($request);
        $question = $this->question($request, $course);
        $body = $request->input('answer', '') ?? '';
        try {
            $this->discussions->answer($course, $question, RequestContext::user($request), $body, $request->clientIp);
            $this->session->flash('success', 'Answer posted.');
        } catch (ValidationException $e) {
            $this->keepDraft('answer', $question->id, $body, $e);
        }

        return Response::redirect($this->back($request, $course, $question));
    }

    public function accept(Request $request): Response
    {
        $course = $this->course($request);
        $question = $this->question($request, $course);
        $answerId = (int) ($request->input('answer', '0') ?? '0');
        $this->discussions->accept($course, $question, RequestContext::user($request), $answerId > 0 ? $answerId : null);
        $this->session->flash('success', $answerId > 0 ? 'Marked as the answer.' : 'No answer is marked now.');

        return Response::redirect($this->back($request, $course, $question));
    }

    public function deleteQuestion(Request $request): Response
    {
        $course = $this->course($request);
        $question = $this->question($request, $course);
        $this->discussions->deleteQuestion($course, $question, RequestContext::user($request), $request->clientIp);
        $this->session->flash('success', 'Your question was deleted.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $question->lessonId . '#questions');
    }

    public function deleteAnswer(Request $request): Response
    {
        $course = $this->course($request);
        $answer = $this->questions->findAnswer($course->tenantId, $request->routeInt('answer')) ?? throw HttpException::notFound();
        $question = $this->questions->find($course->tenantId, $answer->questionId);
        if ($question === null || $question->courseId !== $course->id) {
            throw HttpException::notFound();
        }
        $this->discussions->deleteAnswer($course, $answer, RequestContext::user($request), $request->clientIp);
        $this->session->flash('success', 'Your answer was deleted.');

        return Response::redirect($this->back($request, $course, $question));
    }

    /** Teachers: hide a question or answer, or show it again. */
    public function moderate(Request $request): Response
    {
        $course = $this->course($request);
        $kind = $request->input('kind') === 'answer' ? 'answer' : 'question';
        $hide = $request->input('hide') === '1';
        $id = (int) ($request->input('id', '0') ?? '0');
        $this->discussions->moderate($course, RequestContext::user($request), $kind, $id, $hide, $request->clientIp);
        $this->session->flash('success', ($kind === 'answer' ? 'Answer' : 'Question') . ($hide ? ' hidden. Learners no longer see it.' : ' shown again.'));
        $show = $request->input('show', 'waiting') ?? 'waiting';

        return Response::redirect(in_array($show, ['all', 'hidden'], true) ? '/teach/questions?show=' . $show : '/teach/questions');
    }

    /** Course builders: every question in the school, waiting ones first. */
    public function inbox(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        RequestContext::user($request)->authorise(Permission::CourseManage);
        $show = $request->query('show', 'waiting') ?? 'waiting';
        $show = in_array($show, ['waiting', 'all', 'hidden'], true) ? $show : 'waiting';

        return Response::html($this->view->render('teach/questions', [
            'pageTitle' => 'Questions',
            'show' => $show,
            'questions' => $this->questions->inbox($tenant->id, $show),
            'waiting' => $this->questions->waitingCount($tenant->id),
            'drafts' => self::draftsFrom($this->session),
        ]));
    }

    /** @return array<string, string> "answer-12" => text kept from a failed post */
    public static function draftsFrom(Session $session): array
    {
        $raw = $session->pullFlash(self::DRAFT_FLASH);
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    private function keepDraft(string $kind, int $id, string $body, ValidationException $e): void
    {
        $this->session->flash('error', implode(' ', $e->errors));
        $this->session->flash(self::DRAFT_FLASH, (string) json_encode([$kind . '-' . $id => mb_substr($body, 0, 6000)]));
    }

    private function back(Request $request, Course $course, Question $question): string
    {
        return $request->input('back') === 'teach'
            ? '/teach/questions#q' . $question->id
            : '/courses/' . $course->slug . '/lessons/' . $question->lessonId . '#q' . $question->id;
    }

    private function course(Request $request): Course
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        return $course;
    }

    private function question(Request $request, Course $course): Question
    {
        $question = $this->questions->find($course->tenantId, $request->routeInt('question'));
        if ($question === null || $question->courseId !== $course->id || $question->status === Question::DELETED) {
            throw HttpException::notFound();
        }

        return $question;
    }
}
