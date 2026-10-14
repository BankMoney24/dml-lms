<?php

declare(strict_types=1);

namespace App\Modules\Study\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Study\CalendarFeed;
use App\Modules\Study\Deadlines;
use App\Modules\Study\NoteRepository;

/** Saved lessons and private notes, and the private calendar link (ADR 0018). */
final class StudyController
{
    public const MAX_NOTE = 5000;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly LearningService $learning,
        private readonly NoteRepository $notes,
        private readonly CalendarFeed $calendar,
        private readonly Deadlines $deadlines,
    ) {
    }

    public function saved(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $show = $request->query('show', 'all') ?? 'all';
        $show = in_array($show, ['all', 'saved', 'notes'], true) ? $show : 'all';
        $all = $this->notes->forUser($tenant->id, $user->user->id);
        $rows = array_values(array_filter($all, static fn (array $r): bool => match ($show) {
            'saved' => $r['saved'],
            'notes' => $r['body'] !== '',
            default => true,
        }));
        $byCourse = [];
        foreach ($rows as $r) {
            $byCourse[$r['course_id']]['title'] ??= $r['course_title'];
            $byCourse[$r['course_id']]['slug'] ??= $r['course_slug'];
            $byCourse[$r['course_id']]['items'][] = $r;
        }

        return Response::html($this->view->render('study/saved', [
            'pageTitle' => 'Saved and notes',
            'show' => $show,
            'courses' => $byCourse,
            'counts' => [
                'all' => count($all),
                'saved' => count(array_filter($all, static fn (array $r): bool => $r['saved'])),
                'notes' => count(array_filter($all, static fn (array $r): bool => $r['body'] !== '')),
            ],
        ]));
    }

    public function toggleSave(Request $request): Response
    {
        [$course, $lesson] = $this->lessonFor($request);
        $userId = RequestContext::user($request)->user->id;
        $current = $this->notes->find($course->tenantId, $userId, $lesson->id);
        $saved = $request->input('saved', '1') === '1';
        $this->notes->save($course->tenantId, $userId, $lesson->id, $saved, $current['body'] ?? '');
        $this->session->flash('success', $saved ? 'Saved. Find it again under Saved and notes.' : 'Removed from your saved lessons.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id);
    }

    public function saveNote(Request $request): Response
    {
        [$course, $lesson] = $this->lessonFor($request);
        $userId = RequestContext::user($request)->user->id;
        $body = trim(str_replace("\r\n", "\n", $request->input('note', '') ?? ''));
        if (mb_strlen($body) > self::MAX_NOTE) {
            $this->session->flash('error', 'Notes can be up to 5,000 characters. Shorten it and save again.');

            return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id . '#notes');
        }
        $current = $this->notes->find($course->tenantId, $userId, $lesson->id);
        $this->notes->save($course->tenantId, $userId, $lesson->id, $current['saved'] ?? false, $body);
        $this->session->flash('success', $body === '' ? 'Note deleted.' : 'Note saved. Only you can see it.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id . '#notes');
    }

    public function calendar(Request $request): Response
    {
        return $this->calendarPage($request, null);
    }

    public function issueCalendar(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $token = $this->calendar->issue($tenant->id, RequestContext::user($request)->user->id);

        // Shown on this response only; it is not stored anywhere we can read it back.
        return $this->calendarPage($request, RequestContext::baseUrl($request) . '/calendar/' . $token . '.ics')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function revokeCalendar(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $this->calendar->revoke($tenant->id, RequestContext::user($request)->user->id);
        $this->session->flash('success', 'Calendar link turned off. Calendars using it will stop updating.');

        return Response::redirect('/account/calendar');
    }

    /** No session or cookies: calendar apps fetch this on their own, with the secret in the address. */
    public function feed(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof \App\Modules\Tenancy\Tenant) {
            throw HttpException::notFound();
        }
        $userId = $this->calendar->userFor($tenant->id, $request->routeParam('token'));
        if ($userId === null) {
            throw HttpException::notFound();
        }

        return new Response($this->calendar->render($tenant, $userId, RequestContext::baseUrl($request)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="due-dates.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function calendarPage(Request $request, ?string $newLink): Response
    {
        $tenant = RequestContext::tenant($request);
        $userId = RequestContext::user($request)->user->id;

        return Response::html($this->view->render('study/calendar', [
            'pageTitle' => 'Calendar link',
            'newLink' => $newLink,
            'hasLink' => $newLink !== null || $this->calendar->hasLink($tenant->id, $userId),
            'upcoming' => $this->deadlines->forLearner($tenant->id, $userId, null, 20),
        ]));
    }

    /** @return array{Course, Lesson} */
    private function lessonFor(Request $request): array
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }
        $this->learning->requireEnrolment($course, RequestContext::user($request));
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson'));
        if ($lesson === null) {
            throw HttpException::notFound();
        }

        return [$course, $lesson];
    }
}
