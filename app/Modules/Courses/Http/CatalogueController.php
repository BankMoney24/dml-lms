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
        private readonly ?\App\Modules\Paths\PathRepository $paths = null,
        private readonly ?\App\Core\Session\Session $session = null,
        private readonly ?\App\Modules\Applications\ApplicationRepository $applications = null,
    ) {
    }

    /** Filters on the course list (ADR 0021): what each "show" value means to a learner. */
    public const SHOW = ['free' => 'Free', 'open' => 'Open to read', 'paid' => 'Paid'];

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        // Visitors see everything when the course list is public; otherwise only open courses.
        $openOnly = !$tenant->publicCatalogue && $user === null;
        if ($openOnly && !$this->courses->hasOpen($tenant->id)) {
            return Response::redirect('/login');
        }
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $filters = [
            'track' => self::pick($request->query('track'), \App\Modules\Courses\Course::TRACKS),
            'show' => self::pick($request->query('show'), self::SHOW),
            'level' => self::pick($request->query('level'), \App\Modules\Courses\Course::LEVELS),
            'q' => mb_substr(trim((string) ($request->query('q', '') ?? '')), 0, 80),
        ];
        $courses = $this->courses->browse($tenant->id, $filters + ['openOnly' => $openOnly], self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);
        $filtered = array_filter($filters, static fn (?string $f): bool => $f !== null && $f !== '') !== [];
        $onlyShow = $filters['show'] !== null && $filters['track'] === null && $filters['level'] === null && $filters['q'] === '';

        return Response::html($this->view->render('courses/index', [
            'pageTitle' => $filters['show'] === 'open' ? 'Open courses' : ($filters['show'] === 'free' ? 'Free courses' : 'Courses'),
            'cards' => $this->cards->build($tenant->id, array_slice($courses, 0, self::PER_PAGE), $user?->user->id),
            'page' => $page,
            'hasMore' => count($courses) > self::PER_PAGE,
            'track' => $filters['track'],
            'filters' => $filters,
            'counts' => $this->courses->browseCounts($tenant->id, $openOnly),
            // Learning paths lead the unfiltered first page (ADR 0023).
            'paths' => !$openOnly && !$filtered && $page === 1 ? ($this->paths?->published($tenant->id) ?? []) : [],
            'openOnly' => $openOnly,
            'trackCounts' => $this->courses->publishedTrackCounts($tenant->id),
            'indexable' => ($tenant->publicCatalogue || $openOnly) && $page === 1 && (!$filtered || $onlyShow),
            'canonicalPath' => $onlyShow ? '/courses?show=' . $filters['show'] : '/courses',
            'seoCrumbs' => ['Courses' => '/courses'],
            'metaDescription' => $filters['show'] === 'open'
                ? sprintf('Free courses from %s that anyone can read without an account: lessons and videos you can follow on a phone.', $tenant->name)
                : sprintf('Courses from %s: practical lessons, hands-on labs and quizzes you can follow on a phone.', $tenant->name),
        ]));
    }

    /**
     * @template T
     * @param array<string, T> $allowed
     */
    private static function pick(?string $value, array $allowed): ?string
    {
        return $value !== null && isset($allowed[$value]) ? $value : null;
    }

    /**
     * "1 quiz and 2 labs": the lessons that need joining, in words.
     *
     * @param list<\App\Modules\Courses\Lesson> $lessons
     */
    private static function kinds(array $lessons): string
    {
        $counts = [];
        foreach ($lessons as $l) {
            $label = strtolower($l->typeLabel());
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $n . ' ' . ($n === 1 ? $label : (str_ends_with($label, 'z') ? $label . 'zes' : $label . 's'));
        }
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * Where this person stands with applying (ADR 0025).
     *
     * @return array{gated: bool, mustApply: bool, application: ?\App\Modules\Applications\Application, position: ?int, placesLeft: ?int,
     *                form: array{errors: array<string, string>, old: array<string, string>}, received: ?string}
     */
    private function admission(\App\Modules\Courses\Course $course, ?\App\Modules\Identity\CurrentUser $user, bool $enrolled): array
    {
        $gated = $this->learning->isGated($course);
        $application = $user === null || $enrolled ? null : $this->applications?->latestFor($course->tenantId, $course->id, $user->user->id, $user->user->email);

        return [
            'gated' => $gated,
            'mustApply' => $user === null ? $gated : $this->learning->mustApply($course, $user),
            'application' => $application,
            'position' => $application !== null && $this->applications !== null && $application->status === \App\Modules\Applications\Application::WAITING
                ? $this->applications->position($application)
                : null,
            'placesLeft' => $course->capacity === null ? null : max(0, $course->capacity - $this->courses->placesTaken($course->tenantId, $course->id)),
            'form' => $this->session !== null ? \App\Modules\Applications\Http\ApplyController::formFrom($this->session) : ['errors' => [], 'old' => []],
            'received' => $user === null && $this->session !== null
                ? (is_string($sent = $this->session->get(\App\Modules\Applications\Http\ApplyController::RECEIVED_KEY . $course->id)) ? $sent : null)
                : null,
        ];
    }

    /** Visitors may see a course when the school's list is public, or the course is open to everyone. */
    private static function visible(\App\Modules\Tenancy\Tenant $tenant, \App\Modules\Courses\Course $course, bool $signedIn): bool
    {
        return $signedIn || $tenant->publicCatalogue || $course->isOpen();
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            if (!$tenant->publicCatalogue && $user === null) {
                return Response::redirect('/login');
            }
            throw HttpException::notFound();
        }
        if (!self::visible($tenant, $course, $user !== null)) {
            return Response::redirect('/login');
        }

        $enrolment = $user === null ? null : $this->enrolments->find($tenant->id, $course->id, $user->user->id);
        $mustPay = $user !== null && $enrolment === null && $this->learning->needsPayment($course, $user);

        return Response::html($this->view->render('courses/show', [
            'pageTitle' => $course->title,
            'course' => $course,
            'outline' => $this->lessons->outline($tenant->id, $course->id),
            'enrolment' => $enrolment,
            'certificate' => $this->certificates->forEnrolment($enrolment),
            'completedIds' => $enrolment === null ? [] : $this->progress->completedLessonIds($tenant->id, $enrolment->id),
            'indexable' => $tenant->publicCatalogue || $course->isOpen(),
            'signUpOpen' => $tenant->signUpOpen(),
            'admission' => $this->admission($course, $user, $enrolment !== null),
            'staffView' => $user !== null && $user->can(\App\Modules\Identity\Permission::CourseManage),
            'metaDescription' => $course->summary !== '' ? $course->summary : sprintf('%s, a course from %s.', $course->title, $tenant->name),
            'jsonLd' => CourseSchema::course($course, $tenant, RequestContext::baseUrl($request)),
            'seoCrumbs' => ['Courses' => '/courses', $course->title => '/courses/' . $course->slug],
            'ogImage' => '/share/courses/' . rawurlencode($course->slug) . '.png?v=' . substr(hash('sha256', $course->updatedAt . $course->title), 0, 10),
            'ogImageAlt' => $course->title . ', a course from ' . $tenant->name,
            'ogImageSize' => [\App\Modules\Platform\Seo\ShareCard::WIDTH, \App\Modules\Platform\Seo\ShareCard::HEIGHT],
            'mustPay' => $mustPay,
            'payOnline' => $mustPay && $this->payments->isConnected($tenant),
            'waiting' => $mustPay && $user !== null ? $this->payments->waiting($tenant, $user->user->id, $course) : null,
        ]));
    }

    /**
     * A lesson anyone may read without joining: a free preview the author opened
     * (CRS-003), or any reading or video in an open course (ADR 0021). Progress is
     * not recorded here; joining does that.
     */
    public function preview(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            if (!$tenant->publicCatalogue && $user === null) {
                return Response::redirect('/login');
            }
            throw HttpException::notFound();
        }
        if (!self::visible($tenant, $course, $user !== null)) {
            return Response::redirect('/login');
        }
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson'));
        if ($lesson === null || !$course->lessonIsReadable($lesson)) {
            throw HttpException::notFound();
        }
        if ($user !== null && $this->enrolments->find($tenant->id, $course->id, $user->user->id) !== null) {
            return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id);
        }
        $mustPay = $user !== null && $this->learning->needsPayment($course, $user);
        $ordered = $this->lessons->orderedLessons($tenant->id, $course->id);
        $readable = array_values(array_filter($ordered, static fn ($l): bool => $course->lessonIsReadable($l)));
        $position = 1;
        foreach ($ordered as $i => $l) {
            if ($l->id === $lesson->id) {
                $position = $i + 1;
            }
        }
        $at = 0;
        foreach ($readable as $i => $l) {
            if ($l->id === $lesson->id) {
                $at = $i;
            }
        }

        return Response::html($this->view->render('learn/preview', [
            'pageTitle' => $lesson->title . ($course->isOpen() ? ': ' . $course->title : ': free preview'),
            'course' => $course,
            'lesson' => $lesson,
            'position' => $position,
            'total' => count($ordered),
            'openCourse' => $course->isOpen(),
            'previous' => $readable[$at - 1] ?? null,
            'next' => $readable[$at + 1] ?? null,
            'readableCount' => count($readable),
            'needsJoining' => count($ordered) - count($readable),
            'lockedKinds' => self::kinds(array_values(array_filter($ordered, static fn ($l): bool => !$course->lessonIsReadable($l)))),
            'otherPreviews' => array_values(array_filter($readable, static fn ($l): bool => $l->id !== $lesson->id)),
            'signedIn' => $user !== null,
            'signUpOpen' => $tenant->signUpOpen(),
            'canLearn' => $user === null || $user->can(\App\Modules\Identity\Permission::CourseLearn),
            'mustPay' => $mustPay,
            'payOnline' => $mustPay && $this->payments->isConnected($tenant),
            'indexable' => $tenant->publicCatalogue || $course->isOpen(),
            'metaDescription' => $course->isOpen()
                ? sprintf('%s: a free lesson from %s by %s. Read it without an account.', $lesson->title, $course->title, $tenant->name)
                : sprintf('A free lesson from %s, a course from %s.', $course->title, $tenant->name),
            'seoCrumbs' => ['Courses' => '/courses', $course->title => '/courses/' . $course->slug, $lesson->title => '/courses/' . $course->slug . '/preview/' . $lesson->id],
            'ogImage' => '/share/courses/' . rawurlencode($course->slug) . '.png?v=' . substr(hash('sha256', $course->updatedAt . $course->title), 0, 10),
            'ogImageAlt' => $course->title . ', a course from ' . $tenant->name,
            'ogImageSize' => [\App\Modules\Platform\Seo\ShareCard::WIDTH, \App\Modules\Platform\Seo\ShareCard::HEIGHT],
        ]));
    }
}
