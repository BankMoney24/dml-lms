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
            'mustPay' => $mustPay,
            'payOnline' => $mustPay && $this->payments->isConnected($tenant),
            'waiting' => $mustPay && $user !== null ? $this->payments->waiting($tenant, $user->user->id, $course) : null,
        ]));
    }
}
