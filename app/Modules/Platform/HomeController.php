<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Assignments\SubmissionRepository;
use App\Modules\Certificates\CertificateService;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Permission;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Tenancy\Tenant;

/** "/" — platform landing on platform hosts; welcome or dashboard on tenant hosts. */
final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly EnrolmentRepository $enrolments,
        private readonly CertificateService $certificates,
        private readonly SubmissionRepository $submissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            return Response::html($this->view->render('platform/home', ['pageTitle' => 'DML Learning Platform']));
        }

        $user = RequestContext::optionalUser($request);
        if ($user === null) {
            return Response::html($this->view->render('tenant/welcome', [
                'pageTitle' => $tenant->name,
                'courses' => $this->courses->listPublished($tenant->id, 6),
            ]));
        }

        $enrolments = $this->enrolments->dashboardFor($tenant->id, $user->user->id);
        foreach ($enrolments as $row) {
            if ($row['status'] === 'completed') {
                // Issues certificates for courses finished before certificates existed.
                $this->certificates->forEnrolment($this->enrolments->find($tenant->id, $row['course_id'], $user->user->id));
            }
        }

        return Response::html($this->view->render('dashboard/index', [
            'pageTitle' => 'Home',
            'enrolments' => $enrolments,
            'certificates' => $this->certificates->forUser($tenant->id, $user->user->id),
            'pendingReviews' => $user->can(Permission::CourseManage) ? $this->submissions->pendingCount($tenant->id) : 0,
            'suggested' => $user->can(Permission::CourseLearn) ? $this->courses->listPublished($tenant->id, 6) : [],
        ]));
    }
}
