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
        private readonly ?\App\Modules\Guardians\GuardianRepository $guardians = null,
        private readonly ?\App\Modules\Study\ActivityReport $activity = null,
        private readonly ?\App\Modules\Study\Deadlines $deadlines = null,
        private readonly ?\App\Modules\Study\NoteRepository $notes = null,
        private readonly ?\App\Modules\Site\SitePageRepository $sitePages = null,
        private readonly ?\App\Modules\Joining\ProfileRepository $profiles = null,
        private readonly ?\App\Core\Mail\MailSettings $mail = null,
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
                'jsonLd' => [
                    '@type' => 'Organization',
                    '@id' => RequestContext::baseUrl($request) . '/#organization',
                    'name' => 'DML Technologies',
                    'url' => RequestContext::baseUrl($request) . '/',
                    'slogan' => 'Developing leaders through specialised education',
                    'logo' => RequestContext::baseUrl($request) . '/assets/brand/dml-logo.png',
                ],
            ]));
        }

        $user = RequestContext::optionalUser($request);
        if ($user === null) {
            return Response::html($this->view->render('tenant/welcome', [
                'pageTitle' => $tenant->name,
                'cards' => $tenant->publicCatalogue ? $this->cards->build($tenant->id, $this->courses->listPublished($tenant->id, 6)) : [],
                'sitePages' => $this->sitePages?->published($tenant->id) ?? [],
                'indexable' => true,
                'jsonLd' => [
                    CourseSchema::organisation($tenant, RequestContext::baseUrl($request), $tenant->hasLogo() ? RequestContext::baseUrl($request) . '/logo?v=' . $tenant->logoVersion() : null),
                    CourseSchema::website($tenant, RequestContext::baseUrl($request)),
                ],
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
            ? array_values(array_filter($this->courses->listPublished($tenant->id, 60), static fn ($c): bool => !in_array($c->id, $enrolledIds, true)))
            : [];
        // Suggestions follow what the learner said they want (ADR 0020); otherwise the newest courses.
        $profile = $this->profiles?->find($tenant->id, $user->user->id);
        $reasons = [];
        if ($profile !== null && $profile->goals !== []) {
            $ranked = \App\Modules\Joining\Recommendations::rank($profile, $suggested, 6);
            foreach ($ranked as $r) {
                $reasons[$r['course']->id] = $r['reasons'];
            }
            $rankedIds = array_keys($reasons);
            $rest = array_values(array_filter($suggested, static fn ($c): bool => !in_array($c->id, $rankedIds, true)));
            $suggested = [...array_map(static fn (array $r) => $r['course'], $ranked), ...$rest];
        }

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

        // Study panels for learners: active days and what is due soon (ADR 0018).
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($tenant->timezone)))->format('Y-m-d');
        $learns = $user->can(Permission::CourseLearn);
        $activity = $learns && ($enrolments !== [] || $myGroups !== []) ? $this->activity?->for($tenant->id, $user->user->id, $tenant->timezone) : null;
        $soon = (new \DateTimeImmutable($today))->modify('+21 days')->format('Y-m-d');
        $comingUp = $learns ? array_values(array_filter(
            $this->deadlines?->forLearner($tenant->id, $user->user->id, (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d'), 20) ?? [],
            static fn (array $d): bool => $d['due_on'] <= $soon,
        )) : [];
        $recentNotes = $learns ? array_slice($this->notes?->forUser($tenant->id, $user->user->id, 3) ?? [], 0, 3) : [];

        return Response::html($this->view->render('dashboard/index', [
            'activity' => $activity,
            'comingUp' => array_slice($comingUp, 0, 5),
            'recentNotes' => $recentNotes,
            'myGroups' => $myGroups,
            'children' => $this->guardians?->childrenOf($tenant->id, $user->user->id) ?? [],
            'today' => $today,
            'pageTitle' => 'Home',
            'continue' => $continue,
            'myCards' => array_values(array_filter($myCards, static fn (array $c): bool => $continue === null || $c['course']->id !== $continue['card']['course']->id)),
            'suggestedCards' => $this->cards->build($tenant->id, array_slice($suggested, 0, 6), $user->user->id),
            'cardReasons' => $reasons,
            'profile' => $profile,
            'showOnboarding' => $user->can(Permission::CourseLearn) && !$user->can(Permission::CourseManage) && ($profile === null || !$profile->isOnboarded()),
            'justOnboarded' => $request->query('welcome') === '1',
            'confirmEmail' => ($this->mail?->deliveryAvailable() ?? false) && !$user->user->emailVerified ? $user->user->email : null,
            'certificates' => $this->certificates->forUser($tenant->id, $user->user->id),
            'teaching' => $teaching,
            'setupPending' => $user->can(Permission::SchoolManage) && !$tenant->isSetUp(),
        ]));
    }
}
