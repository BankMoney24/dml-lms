<?php

declare(strict_types=1);

use App\Core\Http\Kernel;
use App\Core\Http\Router;
use App\Modules\Assessments\Http\AttemptController;
use App\Modules\Courses\Http\CatalogueController;
use App\Modules\Courses\Http\TeachController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Learning\Http\LearnController;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\HomeController;

/*
 * Route middleware:
 *   bare               skip session/tenant/auth (health checks)
 *   tenant             request must be on a tenant host
 *   auth               must be signed in
 *   can:<permission>   membership must grant the permission (object checks still happen in use cases)
 */
return static function (Router $r): void {
    $r->get('/health', [HealthController::class, 'live'], [Kernel::BARE]);
    $r->get('/health/ready', [HealthController::class, 'ready'], [Kernel::BARE]);

    $r->get('/', [HomeController::class, 'index']);

    $r->get('/login', [LoginController::class, 'show'], ['tenant']);
    $r->post('/login', [LoginController::class, 'login'], ['tenant']);
    $r->post('/logout', [LoginController::class, 'logout'], ['tenant']);

    $r->get('/courses', [CatalogueController::class, 'index'], ['tenant']);
    $r->get('/courses/{slug}', [CatalogueController::class, 'show'], ['tenant']);

    $learn = ['tenant', 'auth', 'can:course.learn'];
    $r->post('/courses/{slug}/enrol', [LearnController::class, 'enrol'], $learn);
    $r->get('/courses/{slug}/lessons/{lesson:int}', [LearnController::class, 'lesson'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/complete', [LearnController::class, 'complete'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/attempts', [AttemptController::class, 'submit'], $learn);
    $r->get('/courses/{slug}/lessons/{lesson:int}/attempts/{attempt:int}', [AttemptController::class, 'result'], $learn);

    $teach = ['tenant', 'auth', 'can:course.manage'];
    $r->get('/teach', [TeachController::class, 'index'], $teach);
    $r->get('/teach/courses/new', [TeachController::class, 'create'], $teach);
    $r->post('/teach/courses', [TeachController::class, 'store'], $teach);
    $r->get('/teach/courses/{course:int}', [TeachController::class, 'edit'], $teach);
    $r->post('/teach/courses/{course:int}/modules', [TeachController::class, 'addModule'], $teach);
    $r->post('/teach/courses/{course:int}/lessons', [TeachController::class, 'addLesson'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/questions', [TeachController::class, 'addQuestion'], $teach);
    $r->post('/teach/courses/{course:int}/publish', [TeachController::class, 'publish'], $teach);
};
