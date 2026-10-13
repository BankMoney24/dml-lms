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
use App\Modules\Courses\CourseSchema;
use App\Modules\Groups\GroupProgress;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\Permission;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\CourseCards;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\ProgressRepository;
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
        private readonly CourseCards $cards,
        private readonly LessonRepository $lessons,
        private readonly ProgressRepository $progress,
        private readonly ?GroupRepository $groups = null,
        private readonly ?GroupProgress $groupProgress = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            return Response::html($this->view->render('platform/home', [
                'pageTitle' => 'DML Learning Platform',
                'indexable' => true,
                'metaDescription' => 'DML Technologies gives schools, universities and training centres their own learning space: '
                    . 'courses, virtual labs, quizzes and certificates that work on a slow connection.',
                'jsonLd' => CourseSchema::organisation('DML Technologies', $request->baseUrl(), 'Developing leaders through specialised education', $request->baseUrl() . '/assets/brand/dml-logo.png'),
            ]));
        }

        $user = RequestContext::optionalUser($request);
        if ($user === null) {
            return Response::html($this->view->render('tenant/welcome', [
                'pageTitle' => $tenant->name,
                'cards' => $tenant->publicCatalogue ? $this->cards->build($tenant->id, $this->courses->listPublished($tenant->id, 6)) : [],
                'indexable' => true,
                'jsonLd' => CourseSchema::organisation(
                    $tenant->name,
                    $request->baseUrl(),
                    $tenant->tagline,
                    $tenant->hasLogo() ? $request->baseUrl() . '/logo?v=' . $tenant->logoVersion() : null,
                    $tenant->contactEmail,
                    $tenant->contactPhone,
                    $tenant->website,
                ),
            ]));
        }

        $enrolments = $this->enrolments->dashboardFor($tenant->id, $user->user->id);
        foreach ($enrolments as $row) {
            if ($row['status'] === 'completed') {
                // Issues certificates for courses finished before certificates existed.
                $this->certificates->forEnrolment($this->enrolments->find($tenant->id, $row['course_id'], $user->user->id));
            }
        }
        $enrolled = array_values(array_filter(
            $this->courses->findMany($tenant->id, array_column($enrolments, 'course_id')),
            static fn ($course): bool => $course->isPublished(),
        ));
        $myCards = $this->cards->build($tenant->id, $enrolled, $user->user->id);

        // The course to continue: the most recently active one that is not finished.
        $continue = null;
        foreach ($myCards as $card) {
            if (!$card['completed']) {
                $enrolment = $this->enrolments->find($tenant->id, $card['course']->id, $user->user->id);
                $done = $enrolment === null ? [] : $this->progress->completedLessonIds($tenant->id, $enrolment->id);
                $next = null;
                foreach ($this->lessons->orderedLessons($tenant->id, $card['course']->id) as $lesson) {
                    if (!in_array($lesson->id, $done, true)) {
                        $next = $lesson;
                        break;
                    }
                }
                $continue = ['card' => $card, 'next' => $next];
                break;
            }
        }

        $enrolledIds = array_map(static fn ($c): int => $c->id, $enrolled);
        $suggested = $user->can(Permission::CourseLearn)
            ? array_values(array_filter($this->courses->listPublished($tenant->id, 9), static fn ($c): bool => !in_array($c->id, $enrolledIds, true)))
            : [];

        $teaching = null;
        if ($user->can(Permission::CourseManage)) {
            $all = $this->courses->listAllWithCounts($tenant->id);
            $stats = $this->enrolments->courseStats($tenant->id);
            $none = ['enrolled' => 0, 'completed' => 0, 'active' => 0];
            $teaching = [
                'published' => count(array_filter($all, static fn (array $r): bool => $r['course']->isPublished())),
                'drafts' => count(array_filter($all, static fn (array $r): bool => !$r['course']->isPublished())),
                'reviews' => $this->submissions->pendingCount($tenant->id),
                'courses' => array_map(
                    static fn (array $r): array => ['course' => $r['course'], 'lessons' => $r['lesson_count']] + ($stats[$r['course']->id] ?? $none),
                    array_slice($all, 0, 8),
                ),
            ];
        }

        // The learner's class or cohort: what the school set for them, with due dates.
        $myGroups = [];
        foreach ($this->groups?->forLearner($tenant->id, $user->user->id) ?? [] as $g) {
            $ids = array_map(static fn (array $c): int => $c['course']->id, $g['courses']);
            $matrix = $this->groupProgress?->matrix($tenant->id, [$user->user->id], $ids) ?? ['lessons' => [], 'cells' => [], 'last_active' => []];
            $g['progress'] = ['lessons' => $matrix['lessons'], 'cells' => $matrix['cells'][$user->user->id] ?? []];
            $myGroups[] = $g;
        }
        if ($teaching !== null) {
            $teaching['groups'] = $this->groups?->overview($tenant->id, $user->can(Permission::GroupsManage) ? null : $user->user->id) ?? [];
        }

        return Response::html($this->view->render('dashboard/index', [
            'myGroups' => $myGroups,
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone($tenant->timezone)))->format('Y-m-d'),
            'pageTitle' => 'Home',
            'continue' => $continue,
            'myCards' => array_values(array_filter($myCards, static fn (array $c): bool => $continue === null || $c['course']->id !== $continue['card']['course']->id)),
            'suggestedCards' => $this->cards->build($tenant->id, array_slice($suggested, 0, 6), $user->user->id),
            'certificates' => $this->certificates->forUser($tenant->id, $user->user->id),
            'teaching' => $teaching,
            'setupPending' => $user->can(Permission::SchoolManage) && !$tenant->isSetUp(),
        ]));
    }
}
