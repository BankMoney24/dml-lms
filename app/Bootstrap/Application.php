<?php

declare(strict_types=1);

namespace App\Bootstrap;

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
use App\Core\Security\Csrf;
use App\Core\Session\NativeSession;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assessments\AttemptRepository;
use App\Modules\Assessments\AttemptService;
use App\Modules\Assessments\Http\AttemptController;
use App\Modules\Assessments\QuizScorer;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Http\CatalogueController;
use App\Modules\Courses\Http\TeachController;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\LoginThrottle;
use App\Modules\Identity\Middleware\AuthenticateSession;
use App\Modules\Identity\Middleware\RequireAuthentication;
use App\Modules\Identity\Middleware\RequirePermission;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\CompletionRule;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\Http\LearnController;
use App\Modules\Learning\LearningService;
use App\Modules\Learning\ProgressRepository;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\HomeController;
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
        ));

        // Courses, learning, assessments
        $c->set(CourseRepository::class, static fn (Container $c) => new CourseRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(LessonRepository::class, static fn (Container $c) => new LessonRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AssessmentRepository::class, static fn (Container $c) => new AssessmentRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(AttemptRepository::class, static fn (Container $c) => new AttemptRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(EnrolmentRepository::class, static fn (Container $c) => new EnrolmentRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(ProgressRepository::class, static fn (Container $c) => new ProgressRepository($c->get(Connection::class), $c->get(Clock::class)));
        $c->set(CourseAuthoring::class, static fn (Container $c) => new CourseAuthoring(
            $c->get(Connection::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(AssessmentRepository::class),
            $c->get(AuditLog::class),
        ));
        $c->set(LearningService::class, static fn (Container $c) => new LearningService(
            $c->get(Connection::class),
            $c->get(EnrolmentRepository::class),
            $c->get(ProgressRepository::class),
            $c->get(LessonRepository::class),
            new CompletionRule(),
            $c->get(AuditLog::class),
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
        ));
        $c->set(VerifyCsrf::class, static fn (Container $c) => new VerifyCsrf($c->get(Csrf::class)));

        // Controllers
        $c->set(HealthController::class, static fn (Container $c) => new HealthController($c->get(Connection::class), self::releaseId()));
        $c->set(HomeController::class, static fn (Container $c) => new HomeController(
            $c->get(View::class),
            $c->get(CourseRepository::class),
            $c->get(EnrolmentRepository::class),
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
        ));
        $c->set(LearnController::class, static fn (Container $c) => new LearnController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(CourseRepository::class),
            $c->get(LessonRepository::class),
            $c->get(LearningService::class),
            $c->get(AssessmentRepository::class),
            $c->get(AttemptRepository::class),
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
        ));

        $c->set(Router::class, static function (): Router {
            $router = new Router();
            (require self::basePath('routes/web.php'))($router);

            return $router;
        });
        $c->set(Kernel::class, static fn (Container $c) => new Kernel(
            $c,
            $c->get(Router::class),
            [ResolveTenant::class, StartSession::class, AuthenticateSession::class, VerifyCsrf::class],
            static fn (string $spec): Middleware => self::routeMiddleware($c, $spec),
            $c->get(View::class),
            $c->get(Logger::class),
            $config->bool('APP_DEBUG') && !$config->isProduction(),
        ));

        foreach ($overrides as $id => $service) {
            $c->instance($id, $service);
        }

        return $c;
    }

    private static function routeMiddleware(Container $c, string $spec): Middleware
    {
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
