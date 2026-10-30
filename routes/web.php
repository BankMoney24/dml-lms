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
use App\Modules\Guardians\Http\ChildController;
use App\Modules\Guardians\Http\GuardianAdminController;
use App\Modules\Identity\Http\AccountController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\PasswordResetController;
use App\Modules\Identity\Http\TwoFactorController;
use App\Modules\Learning\Http\LearnController;
use App\Modules\Payments\Http\PaymentAdminController;
use App\Modules\Payments\Http\PaymentController;
use App\Modules\Subscriptions\Http\SubscribeController;
use App\Modules\LiveClasses\Http\LiveClassController;
use App\Modules\Notifications\Http\NotificationController;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\HomeController;
use App\Modules\Platform\SchoolsController;
use App\Modules\Platform\SeoController;
use App\Modules\Platform\SystemController;
use App\Modules\Search\Http\SearchController;
use App\Modules\Joining\Http\JoinController;
use App\Modules\Joining\Http\JoiningAdminController;
use App\Modules\Playground\Http\PlaygroundController;
use App\Modules\Discussions\Http\DiscussionController;
use App\Modules\Reports\Http\ReportsController;
use App\Modules\Paths\Http\PathController;
use App\Modules\Achievements\Http\AchievementsController;
use App\Modules\Paths\Http\PathEditorController;
use App\Modules\Site\Http\SiteController;
use App\Modules\Platform\Seo\ShareController;
use App\Modules\Study\Http\StudyController;
use App\Modules\Support\Http\SupportController;
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
    // Search engines: no session, no cookies; the school comes from the verified host (none on the platform host).
    $r->get('/robots.txt', [SeoController::class, 'robots'], [Kernel::BARE, 'host-tenant']);
    $r->get('/sitemap.xml', [SeoController::class, 'sitemap'], [Kernel::BARE, 'host-tenant']);
    $r->get('/.well-known/security.txt', [\App\Modules\Platform\Http\PlatformPagesController::class, 'securityTxt'], [Kernel::BARE]);
    $r->get('/developers', [\App\Modules\Platform\Http\PlatformPagesController::class, 'developers'], ['tenant']);
    $r->get('/accessibility', [\App\Modules\Platform\Http\PlatformPagesController::class, 'accessibility'], ['tenant']);
    $r->get('/health/ready', [HealthController::class, 'ready'], [Kernel::BARE]);
    // API v1 for the school's own systems (ADR 0036): read-only, key per school, no cookies.
    $api = [Kernel::BARE, 'host-tenant', 'api'];
    $r->get('/api/v1', [\App\Modules\Api\Http\ApiController::class, 'index'], $api);
    foreach (['courses', 'learners', 'enrolments', 'payments', 'certificates'] as $apiList) {
        $r->get('/api/v1/' . $apiList, [\App\Modules\Api\Http\ApiController::class, $apiList], $api);
    }
    // Link-preview images for social networks (ADR 0019): public, cookie-free, cached.
    $r->get('/share/site.png', [ShareController::class, 'site'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/share/courses/{slug}.png', [ShareController::class, 'course'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/share/pages/{page}.png', [ShareController::class, 'page'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/share/posts/{slug}.png', [ShareController::class, 'post'], [Kernel::BARE, 'host-tenant', 'tenant']);

    $r->get('/', [HomeController::class, 'index']);

    $r->get('/login', [LoginController::class, 'show'], ['tenant']);
    $r->post('/login', [LoginController::class, 'login'], ['tenant']);
    $r->post('/logout', [LoginController::class, 'logout'], ['tenant']);
    $r->get('/login/verify', [TwoFactorController::class, 'showChallenge'], ['tenant']);
    $r->post('/login/verify', [TwoFactorController::class, 'verifyChallenge'], ['tenant']);
    // Joining (ADR 0020): sign up as a learner, parents ask for access, members ask to teach.
    $r->get('/join', [JoinController::class, 'index'], ['tenant']);
    $r->post('/join', [JoinController::class, 'store'], ['tenant']);
    $r->get('/join/parent', [JoinController::class, 'parent'], ['tenant']);
    $r->post('/join/parent', [JoinController::class, 'storeParent'], ['tenant']);
    $r->get('/join/teach', [JoinController::class, 'teach'], ['tenant', 'auth']);
    $r->post('/join/teach', [JoinController::class, 'storeTeach'], ['tenant', 'auth']);
    $r->get('/account/confirm/{token}', [JoinController::class, 'confirm'], ['tenant']);
    $r->post('/account/confirm', [JoinController::class, 'resendConfirmation'], ['tenant', 'auth']);
    $r->post('/welcome/skip', [JoinController::class, 'skipWelcome'], ['tenant', 'auth', 'can:course.learn']);
    $r->get('/password/forgot', [PasswordResetController::class, 'showForgot'], ['tenant']);
    $r->post('/password/forgot', [PasswordResetController::class, 'sendForgot'], ['tenant']);
    $r->get('/password/reset/{token}', [PasswordResetController::class, 'showReset'], ['tenant']);
    $r->post('/password/reset/{token}', [PasswordResetController::class, 'reset'], ['tenant']);

    // The school's public pages (ADR 0019); only published ones answer.
    foreach (array_keys(\App\Modules\Site\SitePages::PAGES) as $sitePage) {
        $r->get('/' . $sitePage, [SiteController::class, 'show'], ['tenant']);
    }
    // The school's blog (ADR 0034): published posts only; the feed is cookie-free.
    $r->get('/blog', [\App\Modules\Blog\Http\BlogController::class, 'index'], ['tenant']);
    $r->get('/blog/feed.xml', [\App\Modules\Blog\Http\BlogController::class, 'feed'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/blog/{slug}', [\App\Modules\Blog\Http\BlogController::class, 'show'], ['tenant']);
    $r->get('/programmes', [\App\Modules\Platform\Http\ProgrammesController::class, 'index'], ['tenant']);
    $r->get('/courses', [CatalogueController::class, 'index'], ['tenant']);
    $r->get('/courses/{slug}', [CatalogueController::class, 'show'], ['tenant']);
    // Free preview lessons (CRS-003): open to anyone who may see the course page.
    $r->get('/courses/{slug}/preview/{lesson:int}', [CatalogueController::class, 'preview'], ['tenant']);
    $r->get('/courses/{slug}/preview/{lesson:int}/media', [CatalogueController::class, 'media'], ['tenant']);

    $learn = ['tenant', 'auth', 'can:course.learn'];
    $r->post('/courses/{slug}/enrol', [LearnController::class, 'enrol'], $learn);
    // Paid courses (ADR 0012): access follows a payment Paystack itself confirms.
    $r->post('/courses/{slug}/pay', [PaymentController::class, 'pay'], $learn);
    // Subscribing (ADR 0026): account, then payment for paid courses, then the first lesson.
    $r->get('/courses/{slug}/subscribe', [SubscribeController::class, 'show'], ['tenant']);
    $r->post('/courses/{slug}/subscribe', [SubscribeController::class, 'subscribe'], $learn);
    $r->post('/courses/{slug}/subscribe/transfer', [SubscribeController::class, 'claim'], $learn);
    $r->post('/courses/{slug}/subscribe/part', [SubscribeController::class, 'payPart'], $learn);
    $r->post('/courses/{slug}/subscribe/transfer/withdraw', [SubscribeController::class, 'withdrawClaim'], $learn);
    $r->get('/account/receipts/{reference:code}', [\App\Modules\Payments\Http\ReceiptController::class, 'show'], ['tenant', 'auth']);
    $r->get('/payments/paystack/callback', [PaymentController::class, 'callback'], ['tenant', 'auth']);
    // No session or CSRF: Paystack's HMAC signature, checked with the school's key, is the authentication.
    $r->post('/webhooks/paystack', [PaymentController::class, 'webhook'], [Kernel::BARE, 'host-tenant', 'tenant']);
    // Not $learn: teachers following a lesson link are sent to the preview (the controller checks).
    $r->get('/courses/{slug}/lessons/{lesson:int}', [LearnController::class, 'lesson'], ['tenant', 'auth']);
    $r->post('/courses/{slug}/lessons/{lesson:int}/complete', [LearnController::class, 'complete'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/attempts', [AttemptController::class, 'submit'], $learn);
    $r->get('/courses/{slug}/lessons/{lesson:int}/attempts/{attempt:int}', [AttemptController::class, 'result'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/submissions', [LearnController::class, 'submitAssignment'], $learn);
    // Saved lessons and private notes (ADR 0018).
    $r->post('/courses/{slug}/lessons/{lesson:int}/save', [StudyController::class, 'toggleSave'], $learn);
    $r->post('/courses/{slug}/lessons/{lesson:int}/note', [StudyController::class, 'saveNote'], $learn);
    $r->get('/saved', [StudyController::class, 'saved'], $learn);

    // Virtual labs: the gateway checks on every request that the session is this learner's.
    $r->post('/courses/{slug}/lessons/{lesson:int}/lab', [LabController::class, 'start'], $learn);
    $r->get('/labs/runner', [LabController::class, 'runner'], [Kernel::BARE]);
    $r->get('/labs/web-runner', [LabController::class, 'webRunner'], [Kernel::BARE]);
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
    $r->post('/teach/courses/{course:int}/access', [TeachController::class, 'updateAccess'], $teach);
    $r->post('/teach/courses/{course:int}/modules', [TeachController::class, 'addModule'], $teach);
    $r->post('/teach/courses/{course:int}/modules/{module:int}', [TeachController::class, 'renameModule'], $teach);
    $r->post('/teach/courses/{course:int}/modules/{module:int}/delete', [TeachController::class, 'deleteModule'], $teach);
    $r->post('/teach/courses/{course:int}/lessons', [TeachController::class, 'addLesson'], $teach);
    $r->post('/teach/courses/{course:int}/publish', [TeachController::class, 'publish'], $teach);
    $r->post('/teach/courses/{course:int}/unpublish', [TeachController::class, 'unpublish'], $teach);
    $r->get('/teach/courses/{course:int}/progress', [TeachController::class, 'progress'], ['tenant', 'auth', 'can:reports.view']);

    $r->get('/teach/courses/{course:int}/lessons/{lesson:int}', [LessonEditorController::class, 'edit'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}', [LessonEditorController::class, 'update'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/media', [LessonEditorController::class, 'uploadMedia'], $teach);
    $r->post('/teach/courses/{course:int}/lessons/{lesson:int}/media/remove', [LessonEditorController::class, 'removeMedia'], $teach);
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

    // Quizzes and exams (ADR 0042): question banks, CBT exams and live quizzes.
    $bank = \App\Modules\Exams\Http\BankController::class;
    $r->get('/teach/banks', [$bank, 'index'], $teach);
    $r->post('/teach/banks', [$bank, 'store'], $teach);
    $r->post('/teach/banks/samples', [$bank, 'samples'], $teach);
    $r->get('/teach/banks/{bank:int}', [$bank, 'show'], $teach);
    $r->post('/teach/banks/{bank:int}', [$bank, 'update'], $teach);
    $r->post('/teach/banks/{bank:int}/archive', [$bank, 'archive'], $teach);
    $r->get('/teach/banks/{bank:int}/export.csv', [$bank, 'export'], $teach);
    $r->post('/teach/banks/{bank:int}/import', [$bank, 'import'], $teach);
    $r->get('/teach/banks/{bank:int}/questions/new', [$bank, 'newQuestion'], $teach);
    $r->post('/teach/banks/{bank:int}/questions', [$bank, 'storeQuestion'], $teach);
    $r->get('/teach/banks/{bank:int}/questions/{question:int}', [$bank, 'editQuestion'], $teach);
    $r->post('/teach/banks/{bank:int}/questions/{question:int}', [$bank, 'updateQuestion'], $teach);
    $r->post('/teach/banks/{bank:int}/questions/{question:int}/retire', [$bank, 'retire'], $teach);
    $r->post('/teach/banks/{bank:int}/questions/{question:int}/image', [$bank, 'image'], $teach);
    $r->post('/teach/banks/{bank:int}/questions/{question:int}/image/remove', [$bank, 'removeImage'], $teach);
    $r->post('/teach/banks/{bank:int}/passages', [$bank, 'storePassage'], $teach);
    $r->post('/teach/banks/{bank:int}/passages/{passage:int}', [$bank, 'updatePassage'], $teach);
    $cbtAdmin = \App\Modules\Exams\Http\CbtAdminController::class;
    $r->get('/teach/exams', [$cbtAdmin, 'index'], $teach);
    $r->get('/teach/exams/new', [$cbtAdmin, 'create'], $teach);
    $r->post('/teach/exams', [$cbtAdmin, 'store'], $teach);
    $r->get('/teach/exams/{exam:int}', [$cbtAdmin, 'show'], $teach);
    $r->post('/teach/exams/{exam:int}', [$cbtAdmin, 'update'], $teach);
    $r->post('/teach/exams/{exam:int}/publish', [$cbtAdmin, 'publish'], $teach);
    $r->post('/teach/exams/{exam:int}/close', [$cbtAdmin, 'close'], $teach);
    $r->post('/teach/exams/{exam:int}/reopen', [$cbtAdmin, 'reopen'], $teach);
    $r->post('/teach/exams/{exam:int}/try', [$cbtAdmin, 'trial'], $teach);
    $r->get('/teach/exams/{exam:int}/results', [$cbtAdmin, 'results'], $teach);
    $r->get('/teach/exams/{exam:int}/results.csv', [$cbtAdmin, 'csv'], $teach);
    $r->get('/teach/exams/{exam:int}/attempts/{attempt:int}', [$cbtAdmin, 'review'], $teach);
    $r->post('/teach/exams/{exam:int}/attempts/{attempt:int}/extend', [$cbtAdmin, 'extend'], $teach);
    $r->post('/teach/exams/{exam:int}/attempts/{attempt:int}/reset', [$cbtAdmin, 'reset'], $teach);
    $liveAdmin = \App\Modules\Exams\Http\LiveAdminController::class;
    $r->get('/teach/live', [$liveAdmin, 'index'], $teach);
    $r->post('/teach/live', [$liveAdmin, 'store'], $teach);
    $r->get('/teach/live/games/{game:int}', [$liveAdmin, 'stage'], $teach);
    $r->get('/teach/live/games/{game:int}/state', [$liveAdmin, 'state'], $teach);
    $r->post('/teach/live/games/{game:int}/advance', [$liveAdmin, 'advance'], $teach);
    $r->post('/teach/live/games/{game:int}/remove', [$liveAdmin, 'remove'], $teach);
    $r->get('/teach/live/games/{game:int}/results', [$liveAdmin, 'results'], $teach);
    $r->get('/teach/live/games/{game:int}/results.csv', [$liveAdmin, 'csv'], $teach);
    $r->get('/teach/live/{quiz:int}', [$liveAdmin, 'edit'], $teach);
    $r->post('/teach/live/{quiz:int}', [$liveAdmin, 'rename'], $teach);
    $r->post('/teach/live/{quiz:int}/archive', [$liveAdmin, 'archive'], $teach);
    $r->post('/teach/live/{quiz:int}/questions', [$liveAdmin, 'addQuestion'], $teach);
    $r->get('/teach/live/{quiz:int}/questions/{question:int}', [$liveAdmin, 'editQuestion'], $teach);
    $r->post('/teach/live/{quiz:int}/questions/{question:int}', [$liveAdmin, 'updateQuestion'], $teach);
    $r->post('/teach/live/{quiz:int}/questions/{question:int}/delete', [$liveAdmin, 'deleteQuestion'], $teach);
    $r->post('/teach/live/{quiz:int}/questions/{question:int}/move', [$liveAdmin, 'move'], $teach);
    $r->post('/teach/live/{quiz:int}/from-bank', [$liveAdmin, 'fromBank'], $teach);
    $r->post('/teach/live/{quiz:int}/host', [$liveAdmin, 'host'], $teach);
    $cbt = \App\Modules\Exams\Http\CbtController::class;
    $r->get('/exams', [$cbt, 'index'], ['tenant', 'auth']);
    $r->post('/exams/practice', [$cbt, 'practice'], ['tenant', 'auth']);
    $r->get('/exams/{exam:int}', [$cbt, 'show'], ['tenant', 'auth']);
    $r->post('/exams/{exam:int}/start', [$cbt, 'start'], ['tenant', 'auth']);
    $r->get('/exams/attempts/{attempt:int}', [$cbt, 'room'], ['tenant', 'auth']);
    $r->get('/exams/attempts/{attempt:int}/paper', [$cbt, 'paper'], ['tenant', 'auth']);
    $r->post('/exams/attempts/{attempt:int}/save', [$cbt, 'save'], ['tenant', 'auth']);
    $r->post('/exams/attempts/{attempt:int}/submit', [$cbt, 'submit'], ['tenant', 'auth']);
    $r->get('/exams/attempts/{attempt:int}/result', [$cbt, 'result'], ['tenant', 'auth']);
    $play = \App\Modules\Exams\Http\PlayController::class;
    $r->get('/play', [$play, 'join'], ['tenant']);
    $r->post('/play', [$play, 'enter'], ['tenant']);
    $r->get('/play/game', [$play, 'screen'], ['tenant']);
    $r->get('/play/state', [$play, 'state'], ['tenant']);
    $r->post('/play/answer', [$play, 'answer'], ['tenant']);
    $r->get('/play/leave', [$play, 'leave'], ['tenant']);

    $signedIn = ['tenant', 'auth'];
    $r->get('/account', [AccountController::class, 'show'], $signedIn);
    $r->get('/account/subscriptions', [SubscribeController::class, 'mine'], $signedIn);
    $r->post('/account', [AccountController::class, 'updateName'], $signedIn);
    $r->post('/account/password', [AccountController::class, 'updatePassword'], $signedIn);
    $r->get('/account/two-step', [TwoFactorController::class, 'show'], $signedIn);
    $r->post('/account/two-step/enable', [TwoFactorController::class, 'enable'], $signedIn);
    $r->post('/account/two-step/disable', [TwoFactorController::class, 'disable'], $signedIn);
    $r->post('/account/two-step/recovery-codes', [TwoFactorController::class, 'regenerateCodes'], $signedIn);
    $r->post('/account/appearance', [AccountController::class, 'updateAppearance'], $signedIn);
    $r->get('/account/calendar', [StudyController::class, 'calendar'], $signedIn);
    $r->post('/account/calendar', [StudyController::class, 'issueCalendar'], $signedIn);
    $r->post('/account/calendar/revoke', [StudyController::class, 'revokeCalendar'], $signedIn);
    // Calendar apps fetch this themselves: no session; the secret in the address is the key (ADR 0018).
    $r->get('/calendar/{token:code}.ics', [StudyController::class, 'feed'], [Kernel::BARE, 'host-tenant', 'tenant']);
    $r->get('/search', [SearchController::class, 'index'], $signedIn);
    // Questions and answers under lessons (ADR 0022). The service checks enrolment.
    $r->post('/courses/{slug}/lessons/{lesson:int}/questions', [DiscussionController::class, 'ask'], $signedIn);
    $r->post('/courses/{slug}/questions/{question:int}/answers', [DiscussionController::class, 'answer'], $signedIn);
    $r->post('/courses/{slug}/questions/{question:int}/accept', [DiscussionController::class, 'accept'], $signedIn);
    $r->post('/courses/{slug}/questions/{question:int}/delete', [DiscussionController::class, 'deleteQuestion'], $signedIn);
    $r->post('/courses/{slug}/answers/{answer:int}/delete', [DiscussionController::class, 'deleteAnswer'], $signedIn);
    $r->post('/courses/{slug}/questions/moderate', [DiscussionController::class, 'moderate'], ['tenant', 'auth', 'can:course.manage']);
    $r->get('/teach/questions', [DiscussionController::class, 'inbox'], ['tenant', 'auth', 'can:course.manage']);
    // Applying for a place and waiting to be added (ADR 0025).
    $r->post('/courses/{slug}/apply', [\App\Modules\Applications\Http\ApplyController::class, 'apply'], ['tenant']);
    $r->post('/courses/{slug}/apply/withdraw', [\App\Modules\Applications\Http\ApplyController::class, 'withdraw'], ['tenant', 'auth']);
    $r->get('/teach/applications', [\App\Modules\Applications\Http\ApplicationsAdminController::class, 'index'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/applications/{application:int}/offer', [\App\Modules\Applications\Http\ApplicationsAdminController::class, 'offer'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/applications/{application:int}/decline', [\App\Modules\Applications\Http\ApplicationsAdminController::class, 'decline'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/courses/{course:int}/enrolment', [TeachController::class, 'updateEnrolment'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/courses/{course:int}/completion', [TeachController::class, 'updateCompletion'], $teach);
    // Learning paths and achievements (ADR 0023).
    $r->get('/achievements', [AchievementsController::class, 'index'], ['tenant', 'auth', 'can:course.learn']);
    $r->get('/paths/{slug}', [PathController::class, 'show'], ['tenant']);
    $r->post('/paths/{slug}/start', [PathController::class, 'start'], ['tenant', 'auth', 'can:course.learn']);
    $r->post('/paths/{slug}/leave', [PathController::class, 'leave'], ['tenant', 'auth']);
    $r->get('/teach/paths', [PathEditorController::class, 'index'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths', [PathEditorController::class, 'store'], ['tenant', 'auth', 'can:course.manage']);
    $r->get('/teach/paths/{path:int}', [PathEditorController::class, 'edit'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}', [PathEditorController::class, 'update'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}/courses', [PathEditorController::class, 'addCourse'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}/courses/{course:int}/remove', [PathEditorController::class, 'removeCourse'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}/courses/{course:int}/move', [PathEditorController::class, 'move'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}/publish', [PathEditorController::class, 'publish'], ['tenant', 'auth', 'can:course.manage']);
    $r->post('/teach/paths/{path:int}/delete', [PathEditorController::class, 'delete'], ['tenant', 'auth', 'can:course.manage']);
    // School reports and spreadsheet downloads (ADR 0022).
    $r->get('/school/reports', [ReportsController::class, 'index'], ['tenant', 'auth', 'can:reports.view']);
    $r->get('/school/reports/courses.csv', [ReportsController::class, 'coursesCsv'], ['tenant', 'auth', 'can:reports.view']);
    $r->get('/school/reports/learners.csv', [ReportsController::class, 'learnersCsv'], ['tenant', 'auth', 'can:members.manage']);
    $r->get('/playground', [PlaygroundController::class, 'index'], $signedIn);
    $r->post('/playground', [PlaygroundController::class, 'save'], $signedIn);
    $r->post('/playground/{snippet:int}/delete', [PlaygroundController::class, 'delete'], $signedIn);
    // Practice challenges (ADR 0027): the tests run in the learner's browser.
    $r->get('/playground/challenges', [PlaygroundController::class, 'challenges'], $signedIn);
    $r->get('/playground/challenges/{key}', [PlaygroundController::class, 'challenge'], $signedIn);
    $r->post('/playground/challenges/{key}', [PlaygroundController::class, 'solved'], $signedIn);

    $people = ['tenant', 'auth', 'can:members.manage'];
    $r->get('/people', [PeopleController::class, 'index'], $people);
    $r->post('/people', [PeopleController::class, 'store'], $people);
    $r->post('/people/{user:int}/role', [PeopleController::class, 'updateRole'], $people);
    // Parents and guardians (ADR 0016): linked by people who manage members.
    $r->get('/people/{user:int}/guardians', [GuardianAdminController::class, 'show'], $people);
    $r->post('/people/{user:int}/guardians', [GuardianAdminController::class, 'store'], $people);
    $r->post('/people/{user:int}/guardians/{link:int}', [GuardianAdminController::class, 'update'], $people);
    $r->post('/people/{user:int}/guardians/{link:int}/revoke', [GuardianAdminController::class, 'revoke'], $people);
    $r->post('/people/{user:int}/remove', [PeopleController::class, 'remove'], $people);
    $r->post('/people/{user:int}/send-link', [PeopleController::class, 'sendLink'], $people);
    $r->post('/people/{user:int}/reset-two-step', [PeopleController::class, 'resetTwoStep'], $people);

    // A guardian's view of their linked learners; the link is checked on every request.
    $r->get('/children', [ChildController::class, 'index'], ['tenant', 'auth']);
    $r->get('/children/{learner:int}', [ChildController::class, 'show'], ['tenant', 'auth']);
    $r->get('/children/{learner:int}/report', [\App\Modules\Guardians\Http\ReportSheetController::class, 'forParent'], ['tenant', 'auth']);
    $r->get('/people/{user:int}/report', [\App\Modules\Guardians\Http\ReportSheetController::class, 'forStaff'], $people);
    // Help requests (SUP-001): everyone asks, support staff answer.
    $r->get('/help', [SupportController::class, 'index'], ['tenant', 'auth']);
    $r->post('/help', [SupportController::class, 'store'], ['tenant', 'auth']);
    $r->get('/help/{request:int}', [SupportController::class, 'show'], ['tenant', 'auth']);
    $r->post('/help/{request:int}/reply', [SupportController::class, 'reply'], ['tenant', 'auth']);
    $r->post('/help/{request:int}/status', [SupportController::class, 'status'], ['tenant', 'auth']);
    $r->get('/school/support', [SupportController::class, 'inbox'], ['tenant', 'auth', 'can:support.manage']);
    $r->get('/notifications', [NotificationController::class, 'index'], ['tenant', 'auth']);
    $r->get('/notifications/{notification:int}', [NotificationController::class, 'open'], ['tenant', 'auth']);
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll'], ['tenant', 'auth']);

    // Classes, cohorts or groups (ADR 0013). The service decides who may see each group.
    $groups = ['tenant', 'auth'];
    $r->get('/groups', [GroupController::class, 'index'], $groups);
    $r->post('/groups', [GroupController::class, 'store'], ['tenant', 'auth', 'can:groups.manage']);
    $r->get('/groups/{group:int}', [GroupController::class, 'show'], $groups);
    $r->get('/groups/{group:int}/progress.csv', [GroupController::class, 'export'], $groups);
    $r->get('/groups/{group:int}/exams.csv', [GroupController::class, 'examsExport'], $groups);
    // Report cards (ADR 0046).
    $rc = \App\Modules\ReportCards\Http\ReportCardController::class;
    $r->get('/groups/{group:int}/report-cards', [$rc, 'index'], $groups);
    $r->post('/groups/{group:int}/report-cards', [$rc, 'store'], $groups);
    $r->get('/groups/{group:int}/report-cards/{card:int}', [$rc, 'show'], $groups);
    $r->post('/groups/{group:int}/report-cards/{card:int}', [$rc, 'update'], $groups);
    $r->get('/groups/{group:int}/report-cards/{card:int}/edit', [$rc, 'edit'], $groups);
    $r->get('/groups/{group:int}/report-cards/{card:int}/print', [$rc, 'printAll'], $groups);
    $r->get('/groups/{group:int}/report-cards/{card:int}/broadsheet.csv', [$rc, 'csv'], $groups);
    $r->post('/groups/{group:int}/report-cards/{card:int}/publish', [$rc, 'publish'], $groups);
    $r->post('/groups/{group:int}/report-cards/{card:int}/unpublish', [$rc, 'unpublish'], $groups);
    $r->post('/groups/{group:int}/report-cards/{card:int}/delete', [$rc, 'delete'], $groups);
    $r->get('/groups/{group:int}/report-cards/{card:int}/learners/{learner:int}', [$rc, 'learner'], $groups);
    $r->post('/groups/{group:int}/report-cards/{card:int}/learners/{learner:int}', [$rc, 'saveRemarks'], $groups);
    $r->get('/report-cards', [$rc, 'mine'], ['tenant', 'auth']);
    $r->get('/report-cards/{card:int}', [$rc, 'myCard'], ['tenant', 'auth']);
    $r->get('/children/{learner:int}/report-cards/{card:int}', [$rc, 'childCard'], ['tenant', 'auth']);
    $r->post('/groups/{group:int}/details', [GroupController::class, 'update'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/archive', [GroupController::class, 'archive'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/teachers', [GroupController::class, 'teachers'], ['tenant', 'auth', 'can:groups.manage']);
    $r->post('/groups/{group:int}/learners', [GroupController::class, 'addLearners'], $groups);
    $r->post('/groups/{group:int}/learners/{user:int}/remove', [GroupController::class, 'removeLearner'], $groups);
    $r->post('/groups/{group:int}/courses', [GroupController::class, 'assignCourse'], $groups);
    $r->post('/groups/{group:int}/courses/{course:int}/remove', [GroupController::class, 'unassignCourse'], $groups);
    $r->post('/groups/{group:int}/announcements', [GroupController::class, 'announce'], $groups);
    // Live classes and attendance (ADR 0028). The service checks who teaches or learns in the group.
    $r->post('/groups/{group:int}/classes', [GroupController::class, 'scheduleClass'], $groups);
    $r->get('/groups/{group:int}/attendance.csv', [GroupController::class, 'attendance'], $groups);
    $r->get('/classes', [LiveClassController::class, 'index'], $groups);
    $r->get('/classes/{session:int}', [LiveClassController::class, 'show'], $groups);
    $r->post('/classes/{session:int}', [LiveClassController::class, 'update'], $groups);
    $r->post('/classes/{session:int}/join', [LiveClassController::class, 'join'], $groups);
    $r->post('/classes/{session:int}/register', [LiveClassController::class, 'register'], $groups);
    $r->post('/classes/{session:int}/cancel', [LiveClassController::class, 'cancel'], $groups);
    $r->post('/classes/now', [LiveClassController::class, 'startNow'], $groups);
    $r->get('/classes/{session:int}/room', [LiveClassController::class, 'room'], $groups);
    $r->post('/classes/{session:int}/extend', [LiveClassController::class, 'extend'], $groups);
    $r->post('/classes/{session:int}/end', [LiveClassController::class, 'finish'], $groups);
    // Virtual meetings (ADR 0030)
    $r->get('/meetings', [\App\Modules\Meetings\Http\MeetingController::class, 'index'], $groups);
    $r->get('/meetings/new', [\App\Modules\Meetings\Http\MeetingController::class, 'create'], $groups);
    $r->post('/meetings', [\App\Modules\Meetings\Http\MeetingController::class, 'store'], $groups);
    $r->get('/meetings/{meeting:int}', [\App\Modules\Meetings\Http\MeetingController::class, 'show'], $groups);
    $r->post('/meetings/{meeting:int}/invite', [\App\Modules\Meetings\Http\MeetingController::class, 'invite'], $groups);
    $r->post('/meetings/{meeting:int}/cancel', [\App\Modules\Meetings\Http\MeetingController::class, 'cancel'], $groups);
    $r->post('/meetings/{meeting:int}/extend', [\App\Modules\Meetings\Http\MeetingController::class, 'extend'], $groups);
    $r->post('/meetings/{meeting:int}/end', [\App\Modules\Meetings\Http\MeetingController::class, 'finish'], $groups);
    $r->get('/meetings/{meeting:int}/room', [\App\Modules\Meetings\Http\MeetingController::class, 'room'], $groups);
    $r->post('/meetings/{meeting:int}/signal', [\App\Modules\Meetings\Http\MeetingController::class, 'signal'], $groups);
    $r->post('/classes/{session:int}/signal', [LiveClassController::class, 'signal'], $groups);
    $r->post('/classes/{session:int}/materials', [LiveClassController::class, 'addMaterial'], $groups);
    $r->post('/classes/{session:int}/materials/{file:int}/delete', [LiveClassController::class, 'removeMaterial'], $groups);
    $r->post('/groups/{group:int}/announcements/{announcement:int}/delete', [GroupController::class, 'deleteAnnouncement'], $groups);

    $school = ['tenant', 'auth', 'can:school.manage'];
    $r->get('/school', [SchoolController::class, 'index'], $school);
    $r->get('/school/logo-preview', [SchoolController::class, 'logoPreview'], $school);
    $r->get('/school/classroom', [\App\Modules\LiveClasses\Http\ClassroomAdminController::class, 'index'], $school);
    $r->post('/school/classroom', [\App\Modules\LiveClasses\Http\ClassroomAdminController::class, 'save'], $school);
    $r->post('/school/classroom/check', [\App\Modules\LiveClasses\Http\ClassroomAdminController::class, 'check'], $school);
    $r->post('/school/classroom/disconnect', [\App\Modules\LiveClasses\Http\ClassroomAdminController::class, 'disconnect'], $school);
    $r->get('/school/payments', [PaymentAdminController::class, 'index'], $school);
    $r->get('/school/payments.csv', [PaymentAdminController::class, 'export'], $school);
    $r->post('/school/payments/connect', [PaymentAdminController::class, 'connect'], $school);
    $r->post('/school/payments/disconnect', [PaymentAdminController::class, 'disconnect'], $school);
    $r->post('/school/payments/manual', [PaymentAdminController::class, 'manual'], $school);
    $r->post('/school/payments/instructions', [PaymentAdminController::class, 'instructions'], $school);
    $r->post('/school/payments/claims/{claim:int}/confirm', [PaymentAdminController::class, 'confirmClaim'], $school);
    $r->post('/school/payments/claims/{claim:int}/reject', [PaymentAdminController::class, 'rejectClaim'], $school);
    $r->get('/school/payments/plans/{plan:int}', [PaymentAdminController::class, 'plan'], $school);
    $r->post('/school/payments/plans/{plan:int}/record', [PaymentAdminController::class, 'recordPart'], $school);
    $r->post('/school/payments/plans/{plan:int}/write-off', [PaymentAdminController::class, 'writeOff'], $school);
    $r->get('/school/payments/{payment:int}', [PaymentAdminController::class, 'show'], $school);
    $r->post('/school/payments/{payment:int}/refund', [PaymentAdminController::class, 'refund'], $school);
    $r->get('/school/payments/codes', [\App\Modules\Payments\Http\CouponController::class, 'index'], $school);
    $r->post('/school/payments/codes', [\App\Modules\Payments\Http\CouponController::class, 'store'], $school);
    $r->post('/school/payments/codes/{coupon:int}/status', [\App\Modules\Payments\Http\CouponController::class, 'status'], $school);
    $r->get('/logo', [SchoolController::class, 'logo'], ['tenant']);
    $r->get('/school/joining', [JoiningAdminController::class, 'index'], ['tenant', 'auth', 'can:members.manage']);
    $r->post('/school/joining/settings', [JoiningAdminController::class, 'saveSettings'], $school);
    $r->post('/school/joining/code', [JoiningAdminController::class, 'newCode'], $school);
    $r->post('/school/joining/requests/{request:int}/approve', [JoiningAdminController::class, 'approve'], ['tenant', 'auth', 'can:members.manage']);
    $r->post('/school/joining/requests/{request:int}/decline', [JoiningAdminController::class, 'decline'], ['tenant', 'auth', 'can:members.manage']);
    $r->get('/school/website', [SiteController::class, 'index'], $school);
    $r->post('/school/website-settings', [SiteController::class, 'saveSettings'], $school);
    $r->get('/school/website/ready', [SiteController::class, 'ready'], $school);
    $r->post('/school/website/ready/drafts', [SiteController::class, 'readyDrafts'], $school);
    $r->post('/school/website/ready/publish', [SiteController::class, 'readyPublish'], $school);
    $r->get('/school/website/{page}', [SiteController::class, 'edit'], $school);
    $r->post('/school/website/{page}', [SiteController::class, 'save'], $school);
    $r->get('/school/website/{page}/preview', [SiteController::class, 'preview'], $school);
    $r->post('/school/website/{page}/publish', [SiteController::class, 'publish'], $school);
    $r->post('/school/website/{page}/full-draft', [SiteController::class, 'fullDraft'], $school);
    $r->post('/school/website/{page}/unpublish', [SiteController::class, 'unpublish'], $school);
    $r->get('/school/api', [\App\Modules\Api\Http\ApiKeyController::class, 'index'], $school);
    $r->post('/school/api', [\App\Modules\Api\Http\ApiKeyController::class, 'store'], $school);
    $r->post('/school/api/{key:int}/revoke', [\App\Modules\Api\Http\ApiKeyController::class, 'revoke'], $school);
    $r->get('/school/blog', [\App\Modules\Blog\Http\BlogAdminController::class, 'index'], $school);
    $r->get('/school/blog/new', [\App\Modules\Blog\Http\BlogAdminController::class, 'create'], $school);
    $r->post('/school/blog', [\App\Modules\Blog\Http\BlogAdminController::class, 'store'], $school);
    $r->post('/school/blog/starters', [\App\Modules\Blog\Http\BlogAdminController::class, 'starters'], $school);
    $r->get('/school/blog/{post:int}', [\App\Modules\Blog\Http\BlogAdminController::class, 'edit'], $school);
    $r->post('/school/blog/{post:int}', [\App\Modules\Blog\Http\BlogAdminController::class, 'update'], $school);
    $r->get('/school/blog/{post:int}/preview', [\App\Modules\Blog\Http\BlogAdminController::class, 'preview'], $school);
    $r->post('/school/blog/{post:int}/publish', [\App\Modules\Blog\Http\BlogAdminController::class, 'publish'], $school);
    $r->post('/school/blog/{post:int}/unpublish', [\App\Modules\Blog\Http\BlogAdminController::class, 'unpublish'], $school);
    $r->post('/school/blog/{post:int}/delete', [\App\Modules\Blog\Http\BlogAdminController::class, 'delete'], $school);

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
