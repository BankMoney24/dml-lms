<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\LabGateway;
use App\Modules\VirtualLabs\LabSessionRepository;
use App\Modules\VirtualLabs\Http\LabController;
use App\Modules\Groups\GroupProgress;
use App\Modules\Guardians\ChildReport;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Guardians\GuardianService;
use App\Modules\Guardians\Http\ChildController;
use App\Modules\Guardians\Http\GuardianAdminController;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Groups\Http\GroupController;
use App\Modules\Notifications\Http\NotificationController;
use App\Modules\Notifications\NotificationRepository;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Http\PaymentAdminController;
use App\Modules\Payments\Http\PaymentController;
use App\Modules\Payments\HttpPaystackApi;
use App\Modules\Payments\PaymentAccountRepository;
use App\Modules\Payments\PaymentRepository;
use App\Modules\Payments\PaymentService;
use App\Modules\Payments\PaystackApi;
use App\Modules\Platform\SchoolsController;
use App\Modules\Platform\SeoController;
use App\Modules\Platform\SchoolAdminService;
use App\Modules\Files\VirusScanner;
use App\Modules\Support\Http\SupportController;
use App\Modules\Support\SupportRepository;
use App\Modules\Support\SupportService;
use App\Modules\Tenancy\LogoStore;
use App\Modules\Courses\CoursePackages;
use App\Modules\Wizards\WizardRunRepository;
use App\Modules\Wizards\WizardRegistry;
use App\Modules\Wizards\Http\WizardController;
use App\Modules\Tenancy\Setup\SchoolSetupWizard;
use App\Modules\Tenancy\Http\SchoolController;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Http\HttpException;
use App\Core\Http\Kernel;
use App\Core\Http\Middleware;
use App\Core\Http\Middleware\StartSession;
use App\Core\Http\Middleware\VerifyCsrf;
use App\Core\Http\Router;
use App\Core\Log\Logger;
use App\Core\Mail\MailSettings;
use App\Core\Mail\Mailer;
use App\Core\Security\Csrf;
use App\Core\Security\RateLimiter;
use App\Core\Security\SecretBox;
use App\Core\Session\NativeSession;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assessments\AttemptRepository;
use App\Modules\Assessments\AttemptService;
use App\Modules\Assessments\Http\AttemptController;
use App\Modules\Assessments\QuizScorer;
use App\Modules\Assignments\AssignmentService;
use App\Modules\Assignments\Http\ReviewController;
use App\Modules\Assignments\SubmissionRepository;
use App\Modules\Audit\ActivityDescriber;
use App\Modules\Audit\AuditLog;
use App\Modules\Audit\Http\ActivityController;
use App\Modules\Certificates\CertificateRepository;
use App\Modules\Certificates\CertificateService;
use App\Modules\Certificates\Http\CertificateController;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Http\CatalogueController;
use App\Modules\Courses\Http\LessonEditorController;
use App\Modules\Courses\Http\TeachController;
use App\Modules\Courses\LessonRepository;
use App\Modules\Files\ClamdScanner;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Files\Http\FileController;
use App\Modules\Files\NoScanner;
use App\Modules\Files\UploadPolicy;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\AccountService;
use App\Modules\Identity\AccountTokenRepository;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Http\AccountController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\PasswordResetController;
use App\Modules\Identity\Http\TwoFactorController;
use App\Modules\Identity\LoginThrottle;
use App\Modules\Identity\Middleware\AuthenticateSession;
use App\Modules\Identity\Middleware\RequireAuthentication;
use App\Modules\Identity\Middleware\RequirePermission;
use App\Modules\Identity\Permission;
use App\Modules\Identity\TwoFactor\TwoFactorRepository;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\CompletionRule;
use App\Modules\Learning\CourseCards;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\Http\LearnController;
use App\Modules\Learning\LearningService;
use App\Modules\Learning\ProgressReport;
use App\Modules\Learning\ProgressRepository;
use App\Modules\Notifications\EmailComposer;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\HomeController;
use App\Modules\Platform\SystemController;
use App\Modules\Platform\SystemStatus;
use App\Modules\Tenancy\Http\PeopleController;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\RequireTenant;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Tenancy\TenantRepository;
use RuntimeException;

/**
 * Builds the service graph. Explicit factories keep every dependency
 * visible; add a service here when you add a class that needs wiring.
 */
final class Application
{
    public static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2) . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /** @param array<string, object> $overrides services to replace (tests) */
    public static function create(Config $config, array $overrides = []): Container
    {
        $c = new Container();
        $c->instance(Config::class, $config);

        $storage = rtrim($config->get('STORAGE_PATH', self::basePath('storage')) ?? '', '/');
        $appKey = $config->require('APP_KEY');
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY must be at least 32 characters.');
        }

        $c->set(Clock::class, static fn () => new Clock());
        $c->set(Logger::class, static fn () => new Logger($storage . '/logs/app.log'));
        $c->set(Connection::class, static fn () => Connection::connect(
            self::dsn($config),
            $config->require('DB_USERNAME'),
            $config->get('DB_PASSWORD', '') ?? '',
        ));
        $c->set(Migrator::class, static fn (Container $c) => new Migrator($c->get(Connection::class), self::basePath('database/migrations')));
        $c->set(Session::class, static fn () => new NativeSession(
            $storage . '/sessions',
            $config->bool('SESSION_SECURE_COOKIE', true),
            $config->int('SESSION_IDLE_SECONDS', 7200),
        ));
        $c->set(Csrf::class, static fn (Container $c) => new Csrf($c->get(Session::class)));
        $c->set(View::class, static fn () => new View(self::basePath('resources/views'), self::releaseId()));

        // Tenancy & identity
        $c->set(TenantContext::class, static fn () => new TenantContext());
        $c->set(TenantRepository::class, static fn (Container $c) => new TenantRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(MembershipRepository::class, static fn (Container $c) => new MembershipRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(UserRepository::class, static fn (Container $c) => new UserRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(LoginThrottle::class, static fn (Container $c) => new LoginThrottle($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AuditLog::class, static fn (Container $c) => new AuditLog($c->get(Connection::class), $c->get(Clock::class), $appKey));
        $c->set(AuthService::class, static fn (Container $c) => new AuthService(
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(LoginThrottle::class),
            $c->get(Session::class),
            $c->get(Csrf::class),
            $c->get(AuditLog::class),
            $c->get(TwoFactorService::class),
        ));

        // Email, account links, two-step sign-in
        $c->set(MailSettings::class, static fn () => new MailSettings($config, $storage));
        $c->set(Mailer::class, static fn (Container $c) => $c->get(MailSettings::class)->mailer());
        $c->set(MailOutbox::class, static fn (Container $c) => new MailOutbox(
            $c->get(Connection::class),
            $c->get(Mailer::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));
        $c->set(EmailComposer::class, static fn (Container $c) => new EmailComposer($c->get(View::class)));
        $c->set(RateLimiter::class, static fn (Container $c) => new RateLimiter($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AccountTokenRepository::class, static fn (Container $c) => new AccountTokenRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AccountLinkService::class, static fn (Container $c) => new AccountLinkService(
            $c->get(Connection::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(AccountTokenRepository::class),
            $c->get(EmailComposer::class),
            $c->get(MailOutbox::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
        ));
        $c->set(TwoFactorService::class, static fn (Container $c) => new TwoFactorService(
            new TwoFactorRepository($c->get(Connection::class), $c->get(Clock::class)),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            new SecretBox($appKey),
            $c->get(Session::class),
            $c->get(Clock::class),
            $c->get(AuditLog::class),
            $appKey,
            self::twoStepRoles($config),
        ));

        // Courses, learning, assessments
        $c->set(CourseRepository::class, static fn (Container $c) => new CourseRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(LessonRepository::class, static fn (Container $c) => new LessonRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AssessmentRepository::class, static fn (Container $c) => new AssessmentRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AttemptRepository::class, static fn (Container $c) => new AttemptRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(EnrolmentRepository::class, static fn (Container $c) => new EnrolmentRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(CourseCards::class, static fn (Container $c) => new CourseCards($c->get(Connection::class)));
        $c->set(ProgressRepository::class, static fn (Container $c) => new ProgressRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(CourseAuthoring::class, static fn (Container $c) => new CourseAuthoring(
            $c->get(Connection::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(AssessmentRepository::class),
            $c->get(AuditLog::class),
            $c->get(LabCatalogue::class),
        ));
        $c->set(CoursePackageImporter::class, static fn (Container $c) => new CoursePackageImporter(
            $c->get(Connection::class),
            $c->get(CourseAuthoring::class),
            $c->get(FileService::class),
            $c->get(FileRepository::class),
            $c->get(LessonRepository::class),
            $c->get(\App\Modules\Courses\LessonMedia::class),
        ));
        $c->set(NotificationRepository::class, static fn (Container $c) => new NotificationRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(Notifier::class, static fn (Container $c) => new Notifier($c->get(NotificationRepository::class)));
        $c->set(CertificateService::class, static fn (Container $c) => new CertificateService(
            new CertificateRepository($c->get(Connection::class), $c->get(Clock::class)),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
            $c->get(AuditLog::class),
            $c->get(TenantRepository::class),
            $c->get(Notifier::class),
            $c->get(GuardianRepository::class),
        ));
        $c->set(LearningService::class, static fn (Container $c) => new LearningService(
            $c->get(Connection::class),
            $c->get(EnrolmentRepository::class),
            $c->get(ProgressRepository::class),
            $c->get(LessonRepository::class),
            new CompletionRule(),
            $c->get(AuditLog::class),
            $c->get(CertificateService::class),
            $c->get(PaymentRepository::class),
            $c->get(\App\Modules\Applications\ApplicationRepository::class),
        ));
        $c->set(
            \App\Modules\Applications\ApplicationRepository::class,
            static fn (Container $c) => new \App\Modules\Applications\ApplicationRepository($c->get(Connection::class), $c->get(Clock::class)),
        );
        $c->set(FileRepository::class, static fn (Container $c) => new FileRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(VirusScanner::class, static fn () => strtolower($config->get('UPLOAD_SCANNER', '') ?? '') === 'clamd'
            ? new ClamdScanner($config->get('CLAMD_HOST', '127.0.0.1') ?? '127.0.0.1', $config->int('CLAMD_PORT', 3310))
            : new NoScanner());
        $c->set(LogoStore::class, static fn (Container $c) => new LogoStore($storage . '/uploads', $c->get(VirusScanner::class), $c->get(Logger::class)));
        $c->set(CoursePackages::class, static fn () => new CoursePackages(self::basePath('courses')));
        $c->set(LabCatalogue::class, static fn () => LabCatalogue::builtIn());
        $c->set(LabSessionRepository::class, static fn (Container $c) => new LabSessionRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(LabGateway::class, static fn (Container $c) => new LabGateway(
            $c->get(Connection::class),
            $c->get(LabCatalogue::class),
            $c->get(LabSessionRepository::class),
            $c->get(LessonRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(LearningService::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
        ));
        $c->set(PaymentRepository::class, static fn (Container $c) => new PaymentRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(PaymentAccountRepository::class, static fn (Container $c) => new PaymentAccountRepository($c->get(Connection::class), $c->get(Clock::class), new SecretBox($appKey)));
        $c->set(PaystackApi::class, static fn () => new HttpPaystackApi());
        $c->set(PaymentService::class, static fn (Container $c) => new PaymentService(
            $c->get(Connection::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentAccountRepository::class),
            $c->get(PaystackApi::class),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(LearningService::class),
            $c->get(AuditLog::class),
            $c->get(Logger::class),
            $c->get(Clock::class),
            $c->get(Notifier::class),
        ));
        $c->set(GroupRepository::class, static fn (Container $c) => new GroupRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(GroupService::class, static fn (Container $c) => new GroupService(
            $c->get(Connection::class),
            $c->get(GroupRepository::class),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(LearningService::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
            $c->get(Notifier::class),
            $c->get(GuardianRepository::class),
        ));
        $c->set(GuardianRepository::class, static fn (Container $c) => new GuardianRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(GuardianService::class, static fn (Container $c) => new GuardianService(
            $c->get(Connection::class),
            $c->get(GuardianRepository::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(MemberService::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\Study\NoteRepository::class, static fn (Container $c) => new \App\Modules\Study\NoteRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Study\Deadlines::class, static fn (Container $c) => new \App\Modules\Study\Deadlines($c->get(Connection::class)));
        $c->set(\App\Modules\Study\ActivityReport::class, static fn (Container $c) => new \App\Modules\Study\ActivityReport($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Study\CalendarFeed::class, static fn (Container $c) => new \App\Modules\Study\CalendarFeed(
            $c->get(Connection::class),
            $c->get(Clock::class),
            $c->get(\App\Modules\Study\Deadlines::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(\App\Modules\Meetings\MeetingRepository::class),
        ));
        $c->set(SupportRepository::class, static fn (Container $c) => new SupportRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(SupportService::class, static fn (Container $c) => new SupportService(
            $c->get(Connection::class),
            $c->get(SupportRepository::class),
            $c->get(FileService::class),
            $c->get(AuditLog::class),
            $c->get(Notifier::class),
        ));
        $c->set(WizardRunRepository::class, static fn (Container $c) => new WizardRunRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(FileService::class, static fn (Container $c) => new FileService(
            $c->get(FileRepository::class),
            $c->get(LessonRepository::class),
            new UploadPolicy(max(1, $config->int('UPLOAD_MAX_MB', 10)) * 1048576, max(1, $config->int('MEDIA_MAX_MB', 200)) * 1048576),
            $c->get(VirusScanner::class),
            $c->get(AuditLog::class),
            $c->get(Logger::class),
            $storage . '/uploads',
            max(1, $config->int('UPLOAD_TENANT_QUOTA_MB', 5120)) * 1048576,
        ));
        $c->set(SubmissionRepository::class, static fn (Container $c) => new SubmissionRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AssignmentService::class, static fn (Container $c) => new AssignmentService(
            $c->get(Connection::class),
            $c->get(SubmissionRepository::class),
            $c->get(LessonRepository::class),
            $c->get(CourseRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(LearningService::class),
            $c->get(UserRepository::class),
            $c->get(AuditLog::class),
            $c->get(MailSettings::class),
            $c->get(MailOutbox::class),
            $c->get(EmailComposer::class),
            $c->get(FileService::class),
            $c->get(FileRepository::class),
            $c->get(Notifier::class),
            $c->get(GroupRepository::class),
        ));
        $c->set(AttemptService::class, static fn (Container $c) => new AttemptService(
            $c->get(Connection::class),
            $c->get(AssessmentRepository::class),
            $c->get(AttemptRepository::class),
            new QuizScorer(),
            $c->get(LearningService::class),
            $c->get(AuditLog::class),
        ));

        // Middleware
        $c->set(ResolveTenant::class, static fn (Container $c) => new ResolveTenant(
            $c->get(TenantRepository::class),
            $c->get(TenantContext::class),
            array_map('strtolower', $config->list('PLATFORM_HOSTS')),
        ));
        $c->set(StartSession::class, static fn (Container $c) => new StartSession($c->get(Session::class), $c->get(Csrf::class), $c->get(View::class)));
        $c->set(AuthenticateSession::class, static fn (Container $c) => new AuthenticateSession(
            $c->get(AuthService::class),
            $c->get(TenantContext::class),
            $c->get(View::class),
            self::twoStepRoles($config),
            $c->get(NotificationRepository::class),
            new \App\Modules\Platform\NavCounts($c->get(Connection::class)),
        ));
        $c->set(\App\Modules\Tenancy\CanonicalUrl::class, static fn (Container $c) => new \App\Modules\Tenancy\CanonicalUrl(
            $c->get(TenantRepository::class),
            strtolower($config->get('PUBLIC_SCHEME', '') ?? ''),
        ));
        $c->set(\App\Modules\Tenancy\CanonicalHost::class, static fn (Container $c) => new \App\Modules\Tenancy\CanonicalHost(
            $c->get(\App\Modules\Tenancy\CanonicalUrl::class),
            $c->get(View::class),
            $config->bool('CANONICAL_REDIRECT', false),
        ));
        $c->set(VerifyCsrf::class, static fn (Container $c) => new VerifyCsrf($c->get(Csrf::class)));

        // Controllers
        $clamd = strtolower($config->get('UPLOAD_SCANNER', '') ?? '') === 'clamd'
            ? new ClamdScanner($config->get('CLAMD_HOST', '127.0.0.1') ?? '127.0.0.1', $config->int('CLAMD_PORT', 3310))
            : null;
        $c->set(SystemController::class, static fn (Container $c) => new SystemController(
            $c->get(View::class),
            new SystemStatus(
                $c->get(Connection::class),
                new Migrator($c->get(Connection::class), self::basePath('database/migrations')),
                $c->get(MailSettings::class),
                $c->get(MailOutbox::class),
                $storage,
                self::releaseId(),
                max(1, $config->int('UPLOAD_TENANT_QUOTA_MB', 5120)) * 1048576,
                $clamd,
            ),
        ));
        $c->set(SchoolsController::class, static fn (Container $c) => new SchoolsController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(TenantRepository::class),
            new SchoolAdminService(
                $c->get(Connection::class),
                $c->get(TenantRepository::class),
                $c->get(UserRepository::class),
                $c->get(MembershipRepository::class),
                $c->get(AccountLinkService::class),
                $c->get(MailSettings::class),
                $c->get(AuditLog::class),
            ),
            $c->get(MailSettings::class),
        ));
        $c->set(HealthController::class, static fn (Container $c) => new HealthController($c->get(Connection::class), self::releaseId()));
        $c->set(HomeController::class, static fn (Container $c) => new HomeController(
            $c->get(View::class),
            $c->get(CourseRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(CertificateService::class),
            $c->get(SubmissionRepository::class),
            $c->get(CourseCards::class),
            $c->get(LessonRepository::class),
            $c->get(ProgressRepository::class),
            $c->get(GroupRepository::class),
            new GroupProgress($c->get(Connection::class)),
            $c->get(GuardianRepository::class),
            $c->get(\App\Modules\Study\ActivityReport::class),
            $c->get(\App\Modules\Study\Deadlines::class),
            $c->get(\App\Modules\Study\NoteRepository::class),
            $c->get(\App\Modules\Site\SitePageRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
            $c->get(MailSettings::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(\App\Modules\Paths\PathService::class),
            $c->get(\App\Modules\Achievements\Achievements::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(\App\Modules\Meetings\MeetingRepository::class),
        ));
        $c->set(LoginController::class, static fn (Container $c) => new LoginController(
            $c->get(View::class),
            $c->get(AuthService::class),
            $c->get(Session::class),
        ));
        $c->set(CatalogueController::class, static fn (Container $c) => new CatalogueController(
            $c->get(View::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(ProgressRepository::class),
            $c->get(CertificateService::class),
            $c->get(CourseCards::class),
            $c->get(LearningService::class),
            $c->get(PaymentService::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Applications\ApplicationRepository::class),
            $c->get(\App\Modules\Payments\TransferPayments::class),
            $c->get(\App\Modules\Courses\LessonMedia::class),
            $c->get(FileService::class),
        ));
        $c->set(LearnController::class, static fn (Container $c) => new LearnController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(LearningService::class),
            $c->get(AssessmentRepository::class),
            $c->get(AttemptRepository::class),
            $c->get(AssignmentService::class),
            $c->get(SubmissionRepository::class),
            $c->get(FileRepository::class),
            $c->get(FileService::class),
            $c->get(LabCatalogue::class),
            $c->get(LabSessionRepository::class),
            $c->get(\App\Modules\Study\NoteRepository::class),
            $c->get(\App\Modules\Discussions\QuestionRepository::class),
        ));
        $c->set(LabController::class, static fn (Container $c) => new LabController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(LabCatalogue::class),
            $c->get(LabGateway::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(LearningService::class),
            $appKey,
        ));
        $c->set(AttemptController::class, static fn (Container $c) => new AttemptController(
            $c->get(View::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(LearningService::class),
            $c->get(EnrolmentRepository::class),
            $c->get(AttemptService::class),
            $c->get(AttemptRepository::class),
            $c->get(AssessmentRepository::class),
        ));
        $c->set(TeachController::class, static fn (Container $c) => new TeachController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(AssessmentRepository::class),
            $c->get(CourseAuthoring::class),
            new ProgressReport($c->get(Connection::class)),
            $c->get(SubmissionRepository::class),
            $c->get(LabCatalogue::class),
            $c->get(PaymentAccountRepository::class),
        ));
        $c->set(ReviewController::class, static fn (Container $c) => new ReviewController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(SubmissionRepository::class),
            $c->get(AssignmentService::class),
            $c->get(LessonRepository::class),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
            $c->get(FileRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
        ));
        $c->set(FileController::class, static fn (Container $c) => new FileController(
            $c->get(FileRepository::class),
            $c->get(FileService::class),
            $c->get(LessonRepository::class),
            $c->get(CourseRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(SubmissionRepository::class),
            $c->get(SupportService::class),
            $c->get(\App\Modules\LiveClasses\LiveClasses::class),
        ));
        $c->set(CertificateController::class, static fn (Container $c) => new CertificateController(
            $c->get(View::class),
            $c->get(CertificateService::class),
            $c->get(CourseRepository::class),
        ));
        $c->set(LessonEditorController::class, static fn (Container $c) => new LessonEditorController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(AssessmentRepository::class),
            $c->get(CourseAuthoring::class),
            $c->get(FileService::class),
            $c->get(FileRepository::class),
            $c->get(\App\Modules\Courses\LessonMedia::class),
        ));
        $c->set(\App\Modules\Courses\LessonMedia::class, static fn (Container $c) => new \App\Modules\Courses\LessonMedia(
            $c->get(FileService::class),
            $c->get(FileRepository::class),
            $c->get(LessonRepository::class),
            $c->get(CourseRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(AccountService::class, static fn (Container $c) => new AccountService(
            $c->get(UserRepository::class),
            $c->get(Session::class),
            $c->get(AuditLog::class),
        ));
        $c->set(AccountController::class, static fn (Container $c) => new AccountController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AccountService::class),
            $c->get(PaymentRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
            $c->get(MailSettings::class),
        ));
        $c->set(MemberService::class, static fn (Container $c) => new MemberService(
            $c->get(Connection::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(PeopleController::class, static fn (Container $c) => new PeopleController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(MembershipRepository::class),
            $c->get(MemberService::class),
            $c->get(AccountLinkService::class),
            $c->get(UserRepository::class),
            $c->get(TwoFactorService::class),
            $c->get(MailSettings::class),
            $c->get(GuardianRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
        ));
        $c->set(PasswordResetController::class, static fn (Container $c) => new PasswordResetController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AccountLinkService::class),
            $c->get(MailSettings::class),
        ));
        $c->set(TwoFactorController::class, static fn (Container $c) => new TwoFactorController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(TwoFactorService::class),
        ));
        $c->set(SchoolSetupWizard::class, static fn (Container $c) => new SchoolSetupWizard(
            $c->get(Connection::class),
            $c->get(TenantRepository::class),
            $c->get(LogoStore::class),
            $c->get(CoursePackages::class),
            $c->get(CoursePackageImporter::class),
            $c->get(CourseRepository::class),
            $c->get(MemberService::class),
            $c->get(AccountLinkService::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(MailSettings::class),
            $c->get(Session::class),
            $c->get(Logger::class),
        ));
        $c->set(WizardRegistry::class, static fn (Container $c) => new WizardRegistry([
            $c->get(SchoolSetupWizard::class),
            new \App\Modules\Joining\Welcome\WelcomeWizard($c->get(\App\Modules\Joining\ProfileRepository::class)),
        ]));
        $c->set(\App\Modules\Joining\ProfileRepository::class, static fn (Container $c) => new \App\Modules\Joining\ProfileRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Joining\JoinRequestRepository::class, static fn (Container $c) => new \App\Modules\Joining\JoinRequestRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Joining\JoinRequests::class, static fn (Container $c) => new \App\Modules\Joining\JoinRequests(
            $c->get(Connection::class),
            $c->get(\App\Modules\Joining\JoinRequestRepository::class),
            $c->get(MemberService::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
            $c->get(\App\Modules\Notifications\Notifier::class),
        ));
        $c->set(\App\Modules\Joining\RegistrationService::class, static fn (Container $c) => new \App\Modules\Joining\RegistrationService(
            $c->get(Connection::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
            $c->get(AuthService::class),
            $c->get(AccountLinkService::class),
            $c->get(MailSettings::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\Joining\Http\JoinController::class, static fn (Container $c) => new \App\Modules\Joining\Http\JoinController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Joining\RegistrationService::class),
            $c->get(\App\Modules\Joining\JoinRequests::class),
            $c->get(\App\Modules\Joining\JoinRequestRepository::class),
            $c->get(\App\Modules\Joining\ProfileRepository::class),
            $c->get(AccountLinkService::class),
            $c->get(MailSettings::class),
            $c->get(RateLimiter::class),
            $c->get(CourseRepository::class),
            $c->get(LearningService::class),
            $c->get(MembershipRepository::class),
        ));
        $c->set(\App\Modules\Joining\Http\JoiningAdminController::class, static fn (Container $c) => new \App\Modules\Joining\Http\JoiningAdminController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(TenantRepository::class),
            $c->get(\App\Modules\Joining\JoinRequests::class),
            $c->get(\App\Modules\Joining\JoinRequestRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\Playground\Http\PlaygroundController::class, static fn (Container $c) => new \App\Modules\Playground\Http\PlaygroundController(
            $c->get(View::class),
            $c->get(Session::class),
            new \App\Modules\Playground\SnippetRepository($c->get(Connection::class), $c->get(Clock::class)),
        ));
        $c->set(\App\Modules\Payments\Http\ReceiptController::class, static fn (Container $c) => new \App\Modules\Payments\Http\ReceiptController(
            $c->get(View::class),
            $c->get(\App\Modules\Payments\PaymentRepository::class),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
        ));
        $c->set(\App\Modules\Applications\Admissions::class, static fn (Container $c) => new \App\Modules\Applications\Admissions(
            $c->get(Connection::class),
            $c->get(\App\Modules\Applications\ApplicationRepository::class),
            $c->get(CourseRepository::class),
            $c->get(LearningService::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(MemberService::class),
            $c->get(AccountLinkService::class),
            $c->get(MailSettings::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
            $c->get(\App\Modules\Notifications\Notifier::class),
        ));
        $c->set(\App\Modules\Applications\Http\ApplyController::class, static fn (Container $c) => new \App\Modules\Applications\Http\ApplyController(
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(\App\Modules\Applications\Admissions::class),
        ));
        $c->set(\App\Modules\Applications\Http\ApplicationsAdminController::class, static fn (Container $c) => new \App\Modules\Applications\Http\ApplicationsAdminController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Applications\ApplicationRepository::class),
            $c->get(\App\Modules\Applications\Admissions::class),
        ));
        $c->set(\App\Modules\Paths\PathRepository::class, static fn (Container $c) => new \App\Modules\Paths\PathRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Paths\PathService::class, static fn (Container $c) => new \App\Modules\Paths\PathService(
            $c->get(Connection::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(CourseRepository::class),
            $c->get(LearningService::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\Achievements\Achievements::class, static fn (Container $c) => new \App\Modules\Achievements\Achievements(
            $c->get(Connection::class),
            $c->get(Clock::class),
            $c->get(\App\Modules\Notifications\Notifier::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(\App\Modules\Paths\PathService::class),
        ));
        $c->set(\App\Modules\Achievements\Http\AchievementsController::class, static fn (Container $c) => new \App\Modules\Achievements\Http\AchievementsController(
            $c->get(View::class),
            $c->get(\App\Modules\Achievements\Achievements::class),
            $c->get(\App\Modules\Study\ActivityReport::class),
        ));
        $c->set(\App\Modules\Paths\Http\PathController::class, static fn (Container $c) => new \App\Modules\Paths\Http\PathController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(\App\Modules\Paths\PathService::class),
        ));
        $c->set(\App\Modules\Paths\Http\PathEditorController::class, static fn (Container $c) => new \App\Modules\Paths\Http\PathEditorController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Paths\PathRepository::class),
            $c->get(\App\Modules\Paths\PathService::class),
            $c->get(CourseRepository::class),
        ));
        $c->set(\App\Modules\Reports\Http\ReportsController::class, static fn (Container $c) => new \App\Modules\Reports\Http\ReportsController(
            $c->get(View::class),
            new \App\Modules\Reports\SchoolReport($c->get(Connection::class), $c->get(Clock::class)),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
        ));
        $c->set(\App\Modules\Discussions\QuestionRepository::class, static fn (Container $c) => new \App\Modules\Discussions\QuestionRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Discussions\Discussions::class, static fn (Container $c) => new \App\Modules\Discussions\Discussions(
            $c->get(\App\Modules\Discussions\QuestionRepository::class),
            $c->get(CourseRepository::class),
            $c->get(LearningService::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
            $c->get(GroupRepository::class),
            $c->get(\App\Modules\Notifications\Notifier::class),
        ));
        $c->set(\App\Modules\Discussions\Http\DiscussionController::class, static fn (Container $c) => new \App\Modules\Discussions\Http\DiscussionController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(\App\Modules\Discussions\QuestionRepository::class),
            $c->get(\App\Modules\Discussions\Discussions::class),
        ));
        $c->set(WizardController::class, static fn (Container $c) => new WizardController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(WizardRegistry::class),
            $c->get(WizardRunRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(SchoolController::class, static fn (Container $c) => new SchoolController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(TenantRepository::class),
            $c->get(WizardRunRepository::class),
            $c->get(SchoolSetupWizard::class),
            $c->get(LogoStore::class),
            $c->get(MembershipRepository::class),
            $c->get(CourseRepository::class),
            $c->get(PaymentAccountRepository::class),
        ));
        $c->set(PaymentController::class, static fn (Container $c) => new PaymentController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LearningService::class),
            $c->get(PaymentService::class),
        ));
        $c->set(PaymentAdminController::class, static fn (Container $c) => new PaymentAdminController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(PaymentService::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentAccountRepository::class),
            $c->get(CourseRepository::class),
            $c->get(Clock::class),
            $c->get(\App\Modules\Payments\TransferPayments::class),
        ));
        // Subscribing and paying the school directly (ADR 0026).
        $c->set(\App\Modules\Payments\PaymentClaimRepository::class, static fn (Container $c) => new \App\Modules\Payments\PaymentClaimRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Payments\TransferPayments::class, static fn (Container $c) => new \App\Modules\Payments\TransferPayments(
            $c->get(Connection::class),
            $c->get(\App\Modules\Payments\PaymentClaimRepository::class),
            $c->get(PaymentService::class),
            $c->get(CourseRepository::class),
            $c->get(UserRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(LearningService::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
            $c->get(Notifier::class),
        ));
        $c->set(\App\Modules\Subscriptions\Http\SubscribeController::class, static fn (Container $c) => new \App\Modules\Subscriptions\Http\SubscribeController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(LearningService::class),
            $c->get(PaymentService::class),
            $c->get(PaymentRepository::class),
            $c->get(\App\Modules\Payments\TransferPayments::class),
            $c->get(\App\Modules\Applications\ApplicationRepository::class),
        ));
        $c->set(GroupController::class, static fn (Container $c) => new GroupController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(GroupService::class),
            $c->get(GroupRepository::class),
            new GroupProgress($c->get(Connection::class)),
            $c->get(CourseRepository::class),
            $c->get(Clock::class),
            $c->get(\App\Modules\LiveClasses\LiveClasses::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
        ));
        // Live classes and attendance (ADR 0028).
        $c->set(
            \App\Modules\LiveClasses\ClassSessionRepository::class,
            static fn (Container $c) => new \App\Modules\LiveClasses\ClassSessionRepository($c->get(Connection::class), $c->get(Clock::class)),
        );
        $c->set(\App\Modules\LiveClasses\LiveClasses::class, static fn (Container $c) => new \App\Modules\LiveClasses\LiveClasses(
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(GroupRepository::class),
            $c->get(GroupService::class),
            $c->get(CourseRepository::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
            $c->get(GuardianRepository::class),
            $c->get(Notifier::class),
            $c->get(\App\Modules\LiveClasses\Classroom\ClassroomSettingsRepository::class),
        ));
        $c->set(\App\Modules\LiveClasses\Classroom\ClassroomSettingsRepository::class, static fn (Container $c) => new \App\Modules\LiveClasses\Classroom\ClassroomSettingsRepository(
            $c->get(Connection::class),
            $c->get(Clock::class),
            new SecretBox($appKey),
        ));
        $c->set(\App\Modules\LiveClasses\Classroom\Classroom::class, static fn (Container $c) => new \App\Modules\LiveClasses\Classroom\Classroom(
            $c->get(\App\Modules\LiveClasses\Classroom\ClassroomSettingsRepository::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(GroupRepository::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
        ));
        $c->set(\App\Modules\LiveClasses\Http\ClassroomAdminController::class, static fn (Container $c) => new \App\Modules\LiveClasses\Http\ClassroomAdminController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\LiveClasses\Classroom\Classroom::class),
        ));
        $c->set(\App\Modules\LiveClasses\ClassMaterials::class, static fn (Container $c) => new \App\Modules\LiveClasses\ClassMaterials(
            $c->get(\App\Modules\LiveClasses\LiveClasses::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(GroupRepository::class),
            $c->get(FileService::class),
            $c->get(FileRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\LiveClasses\Http\LiveClassController::class, static fn (Container $c) => new \App\Modules\LiveClasses\Http\LiveClassController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\LiveClasses\LiveClasses::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
            $c->get(GroupRepository::class),
            $c->get(CourseRepository::class),
            $c->get(Clock::class),
            $c->get(\App\Modules\LiveClasses\ClassMaterials::class),
            $c->get(\App\Modules\LiveClasses\Classroom\Classroom::class),
            $c->get(FileService::class),
            $c->get(\App\Modules\Meetings\MeetingRepository::class),
        ));
        $c->set(\App\Modules\Meetings\MeetingRepository::class, static fn (Container $c) => new \App\Modules\Meetings\MeetingRepository(
            $c->get(Connection::class),
            $c->get(Clock::class),
        ));
        $c->set(\App\Modules\Meetings\Meetings::class, static fn (Container $c) => new \App\Modules\Meetings\Meetings(
            $c->get(\App\Modules\Meetings\MeetingRepository::class),
            $c->get(\App\Modules\LiveClasses\Classroom\Classroom::class),
            $c->get(GroupRepository::class),
            $c->get(AuditLog::class),
            $c->get(Clock::class),
            $c->get(Notifier::class),
        ));
        $c->set(\App\Modules\Meetings\Http\MeetingController::class, static fn (Container $c) => new \App\Modules\Meetings\Http\MeetingController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Meetings\Meetings::class),
            $c->get(\App\Modules\Meetings\MeetingRepository::class),
            $c->get(Clock::class),
        ));
        $c->set(SeoController::class, static fn (Container $c) => new SeoController(
            $c->get(Connection::class),
            $c->get(\App\Modules\Tenancy\CanonicalUrl::class),
            $c->get(\App\Modules\Site\SitePageRepository::class),
        ));
        $c->set(\App\Modules\Site\SitePageRepository::class, static fn (Container $c) => new \App\Modules\Site\SitePageRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(\App\Modules\Site\ShareSiteLinks::class, static fn (Container $c) => new \App\Modules\Site\ShareSiteLinks(
            $c->get(\App\Modules\Site\SitePageRepository::class),
            $c->get(View::class),
            $c->get(LogoStore::class),
            $c->get(CourseRepository::class),
        ));
        $c->set(\App\Modules\Site\SitePages::class, static fn (Container $c) => new \App\Modules\Site\SitePages(
            $c->get(\App\Modules\Site\SitePageRepository::class),
            $c->get(Connection::class),
            $c->get(AuditLog::class),
        ));
        $c->set(\App\Modules\Site\Http\SiteController::class, static fn (Container $c) => new \App\Modules\Site\Http\SiteController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(\App\Modules\Site\SitePages::class),
            $c->get(\App\Modules\Site\SitePageRepository::class),
        ));
        $c->set(\App\Modules\Platform\Seo\ShareController::class, static fn (Container $c) => new \App\Modules\Platform\Seo\ShareController(
            new \App\Modules\Platform\Seo\ShareCard($storage . '/share', self::basePath('resources/fonts')),
            $c->get(CourseRepository::class),
            $c->get(\App\Modules\Site\SitePageRepository::class),
            $c->get(LogoStore::class),
            self::basePath('public/assets/brand/og-image.jpg'),
        ));
        $c->set(NotificationController::class, static fn (Container $c) => new NotificationController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(NotificationRepository::class),
        ));
        $c->set(GuardianAdminController::class, static fn (Container $c) => new GuardianAdminController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(GuardianService::class),
            $c->get(GuardianRepository::class),
            $c->get(UserRepository::class),
            $c->get(AccountLinkService::class),
            $c->get(MailSettings::class),
        ));
        $c->set(ChildController::class, static fn (Container $c) => new ChildController(
            $c->get(View::class),
            $c->get(GuardianService::class),
            $c->get(GuardianRepository::class),
            new ChildReport($c->get(Connection::class)),
            $c->get(GroupRepository::class),
            $c->get(CertificateService::class),
            $c->get(\App\Modules\LiveClasses\ClassSessionRepository::class),
        ));
        $c->set(\App\Modules\Search\Http\SearchController::class, static fn (Container $c) => new \App\Modules\Search\Http\SearchController(
            $c->get(View::class),
            new \App\Modules\Search\SearchService($c->get(Connection::class), $c->get(GroupRepository::class)),
        ));
        $c->set(\App\Modules\Study\Http\StudyController::class, static fn (Container $c) => new \App\Modules\Study\Http\StudyController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(LearningService::class),
            $c->get(\App\Modules\Study\NoteRepository::class),
            $c->get(\App\Modules\Study\CalendarFeed::class),
            $c->get(\App\Modules\Study\Deadlines::class),
        ));
        $c->set(SupportController::class, static fn (Container $c) => new SupportController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(SupportService::class),
            $c->get(SupportRepository::class),
            $c->get(FileRepository::class),
        ));
        $c->set(ActivityController::class, static fn (Container $c) => new ActivityController(
            $c->get(View::class),
            $c->get(AuditLog::class),
            new ActivityDescriber(),
        ));

        $c->set(Router::class, static function (): Router {
            $router = new Router();
            (require self::basePath('routes/web.php'))($router);

            return $router;
        });
        $c->set(Kernel::class, static fn (Container $c) => new Kernel(
            $c,
            $c->get(Router::class),
            [ResolveTenant::class, \App\Modules\Tenancy\CanonicalHost::class, \App\Modules\Site\ShareSiteLinks::class, StartSession::class, AuthenticateSession::class, VerifyCsrf::class],
            static fn (string $spec): Middleware => self::routeMiddleware($c, $spec),
            $c->get(View::class),
            $c->get(Logger::class),
            $config->bool('APP_DEBUG') && !$config->isProduction(),
            self::hstsHeader($config),
        ));

        foreach ($overrides as $id => $service) {
            $c->instance($id, $service);
        }

        return $c;
    }

    /**
     * HSTS for HTTPS responses: a year by default, without subdomains unless
     * HSTS_INCLUDE_SUBDOMAINS is set (other services on the domain may still use HTTP).
     * HSTS_MAX_AGE=0 turns it off.
     */
    public static function hstsHeader(Config $config): string
    {
        $age = $config->int('HSTS_MAX_AGE', 31536000);
        if ($age <= 0) {
            return '';
        }

        return 'max-age=' . $age . ($config->bool('HSTS_INCLUDE_SUBDOMAINS', false) ? '; includeSubDomains' : '');
    }

    private static function routeMiddleware(Container $c, string $spec): Middleware
    {
        if ($spec === 'host-tenant') {
            // For bare routes (webhooks): find the school from the host without a session.
            return $c->get(ResolveTenant::class);
        }
        if ($spec === 'tenant') {
            return new RequireTenant($c->get(TenantContext::class));
        }
        if ($spec === 'auth') {
            return new RequireAuthentication($c->get(Session::class));
        }
        if (str_starts_with($spec, 'can:')) {
            $permission = Permission::tryFrom(substr($spec, 4));
            if ($permission === null) {
                throw new RuntimeException(sprintf('Unknown permission in route middleware: %s', $spec));
            }

            return new RequirePermission($permission);
        }
        throw new HttpException(500, 'Server misconfiguration.');
    }

    /**
     * Roles that must use two-step sign-in. Default: owners and admins
     * (PRD IAM-005). Set TWO_FACTOR_REQUIRED_ROLES= (empty) to require none.
     *
     * @return list<string>
     */
    public static function twoStepRoles(Config $config): array
    {
        $value = $config->has('TWO_FACTOR_REQUIRED_ROLES') ? ($config->get('TWO_FACTOR_REQUIRED_ROLES') ?? '') : 'owner,admin';

        return array_values(array_filter(array_map('trim', explode(',', strtolower($value))), static fn ($r) => $r !== ''));
    }

    /** DB_SOCKET (a Unix socket path) takes precedence over DB_HOST/DB_PORT. */
    public static function dsn(Config $config): string
    {
        $socket = $config->get('DB_SOCKET');
        $target = $socket !== null
            ? 'unix_socket=' . $socket
            : sprintf('host=%s;port=%d', $config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306));

        return sprintf('mysql:%s;dbname=%s;charset=utf8mb4', $target, $config->require('DB_DATABASE'));
    }

    /** Release identifier written by the deploy pipeline; "dev" locally. */
    public static function releaseId(): string
    {
        $file = self::basePath('REVISION');
        $id = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) === 1 ? $id : 'dev';
    }
}
