<?php

declare(strict_types=1);

use App\Core\Http\Kernel;
use App\Core\Http\Router;
use App\Modules\Assessments\Http\AttemptController;
use App\Modules\Assignments\Http\ReviewController;
use App\Modules\Audit\Http\ActivityController;
use App\Modules\Certificates\Http\CertificateController;
use App\Modules\Courses\Http\CatalogueController;
use App\Modules\Courses\Http\LessonEditorController;
use App\Modules\Courses\Http\TeachController;
use App\Modules\Files\Http\FileController;
use App\Modules\Groups\Http\GroupController;
use App\Modules\Identity\Http\AccountController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\PasswordResetController;
use App\Modules\Identity\Http\TwoFactorController;
use App\Modules\Learning\Http\LearnController;
use App\Modules\Payments\Http\PaymentAdminController;
use App\Modules\Payments\Http\PaymentController;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\HomeController;
use App\Modules\Platform\SchoolsController;
use App\Modules\Platform\SystemController;
use App\Modules\Tenancy\Http\PeopleController;
use App\Modules\VirtualLabs\Http\LabController;
use App\Modules\Tenancy\Http\SchoolController;
use App\Modules\Wizards\Http\WizardController;

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
    $r->get('/login/verify', [TwoFactorController::class, 'showChallenge'], ['tenant']);
    $r->post('/login/verify', [TwoFactorController::class, 'verifyChallenge'], ['tenant']);
    $r->get('/password/forgot', [PasswordResetController::class, 'showForgot'], ['tenant']);
    $r->post('/password/forgot', [PasswordResetController::class, 'sendForgot'], ['tenant']);
    $r->get('/password/reset/{token}', [PasswordResetController::class, 'showReset'], ['tenant']);
    $r->post('/password/reset/{token}', [PasswordResetController::class, 'reset'], ['tenant']);

    $r->get('/courses', [CatalogueController::class, 'index'], ['tenant']);
    $r->get('/courses/{slug}', [CatalogueController::class, 'show'], ['tenant']);

    $learn = ['tenant', 'auth', 'can:course.learn'];
    $r->post('/courses/{slug}/enrol', [LearnController::class, 'enrol'], $learn);
    // Paid courses (ADR 0012): access follows a payment Paystack itself confirms.
    $r->post('/courses/{slug}/pay', [PaymentController::class, 'pay'], $learn);
    $r->get('/payments/paystack/callback', [PaymentController::class, 'callback'], ['tenant', 'auth']);
    // No session or CSRF: Paystack's HMAC signature, checked with the school's key, is the authentication.
    $r->post('/webhooks/paystack', [PaymentController::class, 'webhook'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/courses/{slug}/lessons/{lesson:int}', [LearnController::class, 'lesson'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/complete', [LearnController::class, 'complete'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/attempts', [AttemptController::class, 'submit'], $learn);
    $r->get('/courses/{slug}/lessons/{lesson:int}/attempts/{attempt:int}', [AttemptController::class, 'result'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/submissions', [LearnController::class, 'submitAssignment'], $learn);

    // Virtual labs: the gateway checks on every request that the session is this learner's.
    $r->post('/courses/{slug}/lessons/{lesson:int}/lab', [LabController::class, 'start'], $learn);
    $r->get('/labs/runner', [LabController::class, 'runner'], [Kernel::BARE]);
    $r->get('/labs/{session:int}', [LabController::class, 'show'], ['tenant', 'auth']);
    $r->post('/labs/{session:int}/submit', [LabController::class, 'submit'], ['tenant', 'auth']);
    $r->post('/labs/{session:int}/end', [LabController::class, 'end'], ['tenant', 'auth']);

    $r->get('/files/{file:int}', [FileController::class, 'download'], ['tenant', 'auth']);
    $r->get('/certificates/{code:code}', [CertificateController::class, 'show'], ['tenant', 'auth']);
    $r->get('/verify', [CertificateController::class, 'verify'], ['tenant']);
    $r->get('/verify/{code:code}', [CertificateController::class, 'verify'], ['tenant']);

    $teach = ['tenant', 'auth', 'can:course.manage'];
    $r->get('/teach', [TeachController::class, 'index'], $teach);
    $r->get('/teach/courses/new', [TeachController::class, 'create'], $teach);
    $r->post('/teach/courses', [TeachController::class, 'store'], $teach);
    $r->get('/teach/courses/{course:int}', [TeachController::class, 'edit'], $teach);
    $r->post('/teach/courses/{course:int}/details', [TeachController::class, 'updateDetails'], $teach);
    $r->post('/teach/courses/{course:int}/price', [TeachController::class, 'updatePrice'], ['tenant', 'auth', 'can:school.manage']);
    $r->post('/teach/courses/{course:int}/modules', [TeachController::class, 'addModule'], $teach);
    $r->post('/teach/courses/{course:int}/modules/{module:int}', [TeachController::class, 'renameModule'], $teach);
    $r->post('/teach/courses/{course:int}/modules/{module:int}/delete', [TeachController::class, 'deleteModule'], $teach);
    $r->post('/teach/courses/{course:int}/lessons', [TeachController::class, 'addLesson'], $teach);
    $r->post('/teach/courses/{course:int}/publish', [TeachController::class, 'publish'], $teach);
    $r->post('/teach/courses/{course:int}/unpublish', [TeachController::class, 'unpublish'], $teach);
    $r->get('/teach/courses/{course:int}/progress', [TeachController::class, 'progress'], ['tenant', 'auth', 'can:reports.view']);

    $r->get('/teach/courses/{course:int}/lessons/{lesson:int}', [LessonEditorController::class, 'edit'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}', [LessonEditorController::class, 'update'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/move', [LessonEditorController::class, 'move'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/delete', [LessonEditorController::class, 'delete'], $teach);
    $r->get('/teach/courses/{course:int}/lessons/{lesson:int}/preview', [LessonEditorController::class, 'preview'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/questions', [LessonEditorController::class, 'addQuestion'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/questions/{question:int}/delete', [LessonEditorController::class, 'deleteQuestion'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/questions/{question:int}/explanation', [LessonEditorController::class, 'updateExplanation'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/files', [LessonEditorController::class, 'uploadFile'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/files/{file:int}/delete', [LessonEditorController::class, 'removeFile'], $teach);
    $r->get('/teach/labs', [LabController::class, 'catalogue'], $teach);
    $r->get('/teach/labs/{key}', [LabController::class, 'try'], $teach);
    $r->post('/teach/labs/{key}', [LabController::class, 'checkTry'], $teach);
    $r->get('/teach/reviews', [ReviewController::class, 'index'], $teach);
    $r->get('/teach/reviews/{submission:int}', [ReviewController::class, 'show'], $teach);
    $r->post('/teach/reviews/{submission:int}', [ReviewController::class, 'decide'], $teach);

    $signedIn = ['tenant', 'auth'];
    $r->get('/account', [AccountController::class, 'show'], $signedIn);
    $r->post('/account', [AccountController::class, 'updateName'], $signedIn);
    $r->post('/account/password', [AccountController::class, 'updatePassword'], $signedIn);
    $r->get('/account/two-step', [TwoFactorController::class, 'show'], $signedIn);
    $r->post('/account/two-step/enable', [TwoFactorController::class, 'enable'], $signedIn);
    $r->post('/account/two-step/disable', [TwoFactorController::class, 'disable'], $signedIn);
    $r->post('/account/two-step/recovery-codes', [TwoFactorController::class, 'regenerateCodes'], $signedIn);

    $people = ['tenant', 'auth', 'can:members.manage'];
    $r->get('/people', [PeopleController::class, 'index'], $people);
    $r->post('/people', [PeopleController::class, 'store'], $people);
    $r->post('/people/{user:int}/role', [PeopleController::class, 'updateRole'], $people);
    $r->post('/people/{user:int}/remove', [PeopleController::class, 'remove'], $people);
    $r->post('/people/{user:int}/send-link', [PeopleController::class, 'sendLink'], $people);
    $r->post('/people/{user:int}/reset-two-step', [PeopleController::class, 'resetTwoStep'], $people);

    // Classes, cohorts or groups (ADR 0013). The service decides who may see each group.
    $groups = ['tenant', 'auth'];
    $r->get('/groups', [GroupController::class, 'index'], $groups);
    $r->post('/groups', [GroupController::class, 'store'], ['tenant', 'auth', 'can:groups.manage']);
    $r->get('/groups/{group:int}', [GroupController::class, 'show'], $groups);
    $r->get('/groups/{group:int}/progress.csv', [GroupController::class, 'export'], $groups);
    $r->post('/groups/{group:int}/details', [GroupController::class, 'update'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/archive', [GroupController::class, 'archive'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/teachers', [GroupController::class, 'teachers'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/learners', [GroupController::class, 'addLearners'], $groups);
    $r->post('/groups/{group:int}/learners/{user:int}/remove', [GroupController::class, 'removeLearner'], $groups);
    $r->post('/groups/{group:int}/courses', [GroupController::class, 'assignCourse'], $groups);
    $r->post('/groups/{group:int}/courses/{course:int}/remove', [GroupController::class, 'unassignCourse'], $groups);

    $school = ['tenant', 'auth', 'can:school.manage'];
    $r->get('/school', [SchoolController::class, 'index'], $school);
    $r->get('/school/logo-preview', [SchoolController::class, 'logoPreview'], $school);
    $r->get('/school/payments', [PaymentAdminController::class, 'index'], $school);
    $r->get('/school/payments.csv', [PaymentAdminController::class, 'export'], $school);
    $r->post('/school/payments/connect', [PaymentAdminController::class, 'connect'], $school);
    $r->post('/school/payments/disconnect', [PaymentAdminController::class, 'disconnect'], $school);
    $r->post('/school/payments/manual', [PaymentAdminController::class, 'manual'], $school);
    $r->get('/logo', [SchoolController::class, 'logo'], ['tenant']);

    // Wizard engine; each wizard checks who may run it.
    $r->get('/setup/{wizard}', [WizardController::class, 'resume'], $signedIn);
    $r->post('/setup/{wizard}/discard', [WizardController::class, 'discard'], $signedIn);
    $r->get('/setup/{wizard}/{step}', [WizardController::class, 'show'], $signedIn);
    $r->post('/setup/{wizard}/{step}', [WizardController::class, 'submit'], $signedIn);

    $r->get('/system', [SystemController::class, 'show'], ['tenant', 'auth']);
    // Platform administrators only; the controller answers "not found" to anyone else.
    $r->get('/system/schools', [SchoolsController::class, 'index'], ['tenant', 'auth']);
    $r->post('/system/schools', [SchoolsController::class, 'store'], ['tenant', 'auth']);
    $r->get('/system/schools/{school:int}', [SchoolsController::class, 'show'], ['tenant', 'auth']);
    $r->post('/system/schools/{school:int}/addresses', [SchoolsController::class, 'addAddress'], ['tenant', 'auth']);
    $r->post('/system/schools/{school:int}/status', [SchoolsController::class, 'status'], ['tenant', 'auth']);
    $r->get('/activity', [ActivityController::class, 'index'], ['tenant', 'auth', 'can:audit.view']);
};
