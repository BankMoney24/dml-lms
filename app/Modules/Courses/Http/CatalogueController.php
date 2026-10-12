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
use App\Modules\Learning\ProgressRepository;

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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $courses = $this->courses->listPublished($tenant->id, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('courses/index', [
            'pageTitle' => 'Courses',
            'cards' => $this->cards->build($tenant->id, array_slice($courses, 0, self::PER_PAGE), RequestContext::optionalUser($request)?->user->id),
            'page' => $page,
            'hasMore' => count($courses) > self::PER_PAGE,
        ]));
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        $user = RequestContext::optionalUser($request);
        $enrolment = $user === null ? null : $this->enrolments->find($tenant->id, $course->id, $user->user->id);

        return Response::html($this->view->render('courses/show', [
            'pageTitle' => $course->title,
            'course' => $course,
            'outline' => $this->lessons->outline($tenant->id, $course->id),
            'enrolment' => $enrolment,
            'certificate' => $this->certificates->forEnrolment($enrolment),
            'completedIds' => $enrolment === null ? [] : $this->progress->completedLessonIds($tenant->id, $enrolment->id),
        ]));
    }
}
