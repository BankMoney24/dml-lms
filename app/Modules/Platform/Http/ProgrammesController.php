<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;

/**
 * /programmes (ADR 0034): the school's courses grouped by how people learn them:
 * formal school subjects, skills for work, and everyday (informal) learning.
 * Everything on the page comes from the school's settings and published courses.
 */
final class ProgrammesController
{
    public function __construct(private readonly View $view, private readonly CourseRepository $courses)
    {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $openOnly = !$tenant->publicCatalogue && RequestContext::optionalUser($request) === null;
        $courses = $this->courses->browse($tenant->id, ['openOnly' => $openOnly], 100);
        if ($courses === []) {
            throw HttpException::notFound();
        }

        /** @var array<string, list<Course>> $groups */
        $groups = [];
        foreach ($courses as $course) {
            $groups[$course->track ?? 'other'][] = $course;
        }
        $shown = [];
        foreach (['school', 'skills', 'everyday', 'other'] as $key) {
            if (isset($groups[$key])) {
                $list = $groups[$key];
                usort($list, static fn (Course $a, Course $b): int => strcasecmp($a->title, $b->title));
                $shown[$key] = $list;
            }
        }

        return Response::html($this->view->render('site/programmes', [
            'pageTitle' => 'Programmes: formal and informal learning',
            'metaDescription' => 'How you can learn at ' . $tenant->name . ': school subjects, skills for work and everyday learning, with every course in each.',
            'indexable' => true,
            'canonicalPath' => '/programmes',
            'seoCrumbs' => ['Programmes' => '/programmes'],
            'school' => $tenant,
            'groups' => $shown,
            'signedIn' => RequestContext::optionalUser($request) !== null,
        ]));
    }
}
