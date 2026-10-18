<?php

declare(strict_types=1);

namespace App\Modules\Applications\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Modules\Applications\Admissions;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;

/** "Apply for a place" on a course page, and withdrawing (ADR 0025). */
final class ApplyController
{
    /** What was typed and what was wrong, shown again on the course page. */
    public const FORM_FLASH = 'apply_form';
    public const RECEIVED_KEY = 'applied.';

    public function __construct(
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly Admissions $admissions,
    ) {
    }

    public function apply(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->course($request);
        $member = RequestContext::optionalUser($request);
        $back = '/courses/' . $course->slug . '#apply';
        if (($request->input('website', '') ?? '') !== '') {
            return Response::redirect($back);
        }
        if (!$tenant->publicCatalogue && $member === null && !$course->isOpen()) {
            return Response::redirect('/login');
        }
        $input = [
            'name' => mb_substr($request->input('name', '') ?? '', 0, 200),
            'email' => mb_substr($request->input('email', '') ?? '', 0, 300),
            'phone' => mb_substr($request->input('phone', '') ?? '', 0, 60),
            'message' => mb_substr($request->input('message', '') ?? '', 0, 4000),
            'agree' => $request->input('agree') === '1',
        ];
        try {
            $application = $this->admissions->apply($tenant, $course, $member, $input, $request->clientIp);
        } catch (ValidationException $e) {
            unset($input['agree']);
            $this->session->flash(self::FORM_FLASH, (string) json_encode(['errors' => $e->errors, 'old' => $input]));

            return Response::redirect($back);
        }
        if ($member === null) {
            // Remember on this browser, so the course page shows "received" instead of the form again.
            $this->session->set(self::RECEIVED_KEY . $course->id, $application->email);
        }
        $this->session->flash('success', $member !== null
            ? 'Application sent. You are on the waiting list; we will tell you here when a place is ready.'
            : 'Thank you, ' . $application->name . '. Your application is in. The school will contact you at ' . $application->email . ' when a place is ready.');

        return Response::redirect($back);
    }

    public function withdraw(Request $request): Response
    {
        $course = $this->course($request);
        $this->admissions->withdraw(RequestContext::tenant($request), RequestContext::user($request), (int) ($request->input('application', '0') ?? '0'));
        $this->session->flash('success', 'You have left the waiting list for ' . $course->title . '.');

        return Response::redirect('/courses/' . $course->slug);
    }

    /** @return array{errors: array<string, string>, old: array<string, string>} */
    public static function formFrom(Session $session): array
    {
        $raw = $session->pullFlash(self::FORM_FLASH);
        $data = $raw === null ? null : json_decode($raw, true);
        $errors = is_array($data) && is_array($data['errors'] ?? null) ? array_filter($data['errors'], 'is_string') : [];
        $old = is_array($data) && is_array($data['old'] ?? null) ? array_filter($data['old'], 'is_string') : [];

        return ['errors' => $errors, 'old' => $old + ['name' => '', 'email' => '', 'phone' => '', 'message' => '']];
    }

    private function course(Request $request): Course
    {
        $course = $this->courses->findBySlug(RequestContext::tenant($request)->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        return $course;
    }
}
