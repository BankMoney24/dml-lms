<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Certificates\CertificateService;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\CourseSchema;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\CourseCards;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Learning\ProgressRepository;
use App\Modules\Payments\PaymentService;

final class CatalogueController
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly EnrolmentRepository $enrolments,
        private readonly ProgressRepository $progress,
        private readonly CertificateService $certificates,
        private readonly CourseCards $cards,
        private readonly LearningService $learning,
        private readonly PaymentService $payments,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$tenant->publicCatalogue && RequestContext::optionalUser($request) === null) {
            return Response::redirect('/login');
        }
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $track = $request->query('track');
        $track = $track !== null && isset(\App\Modules\Courses\Course::TRACKS[$track]) ? $track : null;
        $courses = $this->courses->listPublished($tenant->id, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE, $track);

        return Response::html($this->view->render('courses/index', [
            'pageTitle' => 'Courses',
            'cards' => $this->cards->build($tenant->id, array_slice($courses, 0, self::PER_PAGE), RequestContext::optionalUser($request)?->user->id),
            'page' => $page,
            'hasMore' => count($courses) > self::PER_PAGE,
            'track' => $track,
            'trackCounts' => $this->courses->publishedTrackCounts($tenant->id),
            'indexable' => $tenant->publicCatalogue && $page === 1 && $track === null,
            'metaDescription' => sprintf('Courses from %s: practical lessons, hands-on labs and quizzes you can follow on a phone.', $tenant->name),
        ]));
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$tenant->publicCatalogue && RequestContext::optionalUser($request) === null) {
            return Response::redirect('/login');
        }
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        $user = RequestContext::optionalUser($request);
        $enrolment = $user === null ? null : $this->enrolments->find($tenant->id, $course->id, $user->user->id);
        $mustPay = $user !== null && $enrolment === null && $this->learning->needsPayment($course, $user);

        return Response::html($this->view->render('courses/show', [
            'pageTitle' => $course->title,
            'course' => $course,
            'outline' => $this->lessons->outline($tenant->id, $course->id),
            'enrolment' => $enrolment,
            'certificate' => $this->certificates->forEnrolment($enrolment),
            'completedIds' => $enrolment === null ? [] : $this->progress->completedLessonIds($tenant->id, $enrolment->id),
            'indexable' => $tenant->publicCatalogue,
            'metaDescription' => $course->summary !== '' ? $course->summary : sprintf('%s, a course from %s.', $course->title, $tenant->name),
            'jsonLd' => CourseSchema::course($course, $tenant, $request->baseUrl()),
            'mustPay' => $mustPay,
            'payOnline' => $mustPay && $this->payments->isConnected($tenant),
            'waiting' => $mustPay && $user !== null ? $this->payments->waiting($tenant, $user->user->id, $course) : null,
        ]));
    }

    /**
     * A free preview lesson (CRS-003): readings and videos the author opened to
     * everyone, so visitors can try a course before joining or paying.
     */
    public function preview(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$tenant->publicCatalogue && RequestContext::optionalUser($request) === null) {
            return Response::redirect('/login');
        }
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson'));
        if ($lesson === null || !$lesson->isOpenPreview()) {
            throw HttpException::notFound();
        }
        $user = RequestContext::optionalUser($request);
        if ($user !== null && $this->enrolments->find($tenant->id, $course->id, $user->user->id) !== null) {
            return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id);
        }
        $mustPay = $user !== null && $this->learning->needsPayment($course, $user);
        $ordered = $this->lessons->orderedLessons($tenant->id, $course->id);
        $position = 1;
        foreach ($ordered as $i => $l) {
            if ($l->id === $lesson->id) {
                $position = $i + 1;
            }
        }

        return Response::html($this->view->render('learn/preview', [
            'pageTitle' => $lesson->title . ': free preview',
            'course' => $course,
            'lesson' => $lesson,
            'position' => $position,
            'total' => count($ordered),
            'otherPreviews' => array_values(array_filter($ordered, static fn ($l): bool => $l->isOpenPreview() && $l->id !== $lesson->id)),
            'signedIn' => $user !== null,
            'canLearn' => $user === null || $user->can(\App\Modules\Identity\Permission::CourseLearn),
            'mustPay' => $mustPay,
            'payOnline' => $mustPay && $this->payments->isConnected($tenant),
            'indexable' => $tenant->publicCatalogue,
            'metaDescription' => sprintf('A free lesson from %s, a course from %s.', $course->title, $tenant->name),
        ]));
    }
}
