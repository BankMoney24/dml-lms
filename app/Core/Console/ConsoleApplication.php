<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Config;
use App\Core\Container;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailSettings;
use App\Core\Mail\Message;
use App\Bootstrap\Application;
use App\Database\Seeders\DemoSeeder;
use App\Modules\Certificates\CertificateService;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\TenantRepository;
use Throwable;

/**
 * Operator commands. Secrets are never accepted as command-line arguments
 * (they would land in shell history and process listings).
 */
final class ConsoleApplication
{
    public function __construct(private readonly Container $container)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'migrate' => $this->migrate(),
                'migrate:status' => $this->migrateStatus(),
                'tenant:create' => $this->tenantCreate($args),
                'tenant:add-domain' => $this->tenantAddDomain($args),
                'user:create' => $this->userCreate($args),
                'seed:demo' => $this->seedDemo($args),
                'mail:work' => $this->mailWork(),
                'mail:status' => $this->mailStatus(),
                'mail:test' => $this->mailTest($args),
                'course:packages' => $this->coursePackages(),
                'course:import' => $this->courseImport($args),
                'course:sync-files' => $this->courseSyncFiles($args),
                'certificate:revoke' => $this->certificateRevoke($args),
                default => $this->help(),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function help(): int
    {
        echo <<<TXT
        DML Learning Platform console

          migrate                                   Apply pending database migrations
          migrate:status                            List migrations and whether they are applied
          tenant:create <slug> "<name>" <host>      Create a tenant with a verified primary host
          tenant:add-domain <slug> <host>           Map another verified host to a tenant
          user:create <tenant-slug> <email> "<name>" <role>
                                                    Create a user and membership. Password is read
                                                    from STDIN, or generated and printed once.
                                                    Roles: owner, admin, educator, learner, guardian
          seed:demo <tenant-slug>                   Add a demo course (refused in production)
          mail:work                                 Send queued emails that are due (run every minute from cron)
          mail:status                               Counts of queued / sent / failed emails in the last 7 days
          mail:test <email>                         Send a test email straight away, to check MAIL_* settings
          course:packages                           List the course packages shipped with this release
          course:import <tenant-slug> <package> [--publish] [--author=<email>]
                                                    Import a course package (a name from course:packages,
                                                    or a folder with course.json). Draft unless --publish.
          course:sync-files <tenant-slug> <package> <course-slug>
                                                    Add a package's lesson files to a course imported earlier
          certificate:revoke <code> "<reason>"      Revoke a certificate; the reason shows on /verify

        TXT;

        return 0;
    }

    private function migrate(): int
    {
        $ran = $this->container->get(Migrator::class)->migrate();
        echo $ran === [] ? "Nothing to migrate.\n" : 'Applied: ' . implode(', ', $ran) . "\n";

        return 0;
    }

    private function migrateStatus(): int
    {
        foreach ($this->container->get(Migrator::class)->status() as $name => $applied) {
            printf("%s  %s\n", $applied ? '[x]' : '[ ]', $name);
        }

        return 0;
    }

    /** @param list<string> $args */
    private function tenantCreate(array $args): int
    {
        [$slug, $name, $host] = $this->requireArgs($args, 3, 'tenant:create <slug> "<name>" <host>');
        $db = $this->container->get(Connection::class);
        $tenants = $this->container->get(TenantRepository::class);
        $tenant = $db->transaction(static function () use ($tenants, $slug, $name, $host) {
            $tenant = $tenants->create($slug, $name);
            $tenants->addDomain($tenant->id, $host, true, true);

            return $tenant;
        });
        printf("Created tenant #%d %s on %s\n", $tenant->id, $tenant->slug, $host);

        return 0;
    }

    /** @param list<string> $args */
    private function tenantAddDomain(array $args): int
    {
        [$slug, $host] = $this->requireArgs($args, 2, 'tenant:add-domain <slug> <host>');
        $tenant = $this->container->get(TenantRepository::class)->findBySlug($slug);
        if ($tenant === null) {
            throw new \RuntimeException('No tenant with that slug.');
        }
        $this->container->get(TenantRepository::class)->addDomain($tenant->id, $host, true);
        printf("Mapped %s to %s\n", $host, $slug);

        return 0;
    }

    /** @param list<string> $args */
    private function userCreate(array $args): int
    {
        [$slug, $email, $name, $roleName] = $this->requireArgs($args, 4, 'user:create <tenant-slug> <email> "<name>" <role>');
        $role = Role::tryFrom($roleName) ?? throw new \RuntimeException('Unknown role.');
        $tenant = $this->container->get(TenantRepository::class)->findBySlug($slug)
            ?? throw new \RuntimeException('No tenant with that slug.');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('That email address is not valid.');
        }

        $generated = false;
        $password = '';
        if (!stream_isatty(STDIN)) {
            $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        }
        if ($password === '') {
            $password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
            $generated = true;
        }
        $problems = AuthService::passwordProblems($password);
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }

        $users = $this->container->get(UserRepository::class);
        $memberships = $this->container->get(MembershipRepository::class);
        $db = $this->container->get(Connection::class);
        $user = $db->transaction(static function () use ($users, $memberships, $email, $name, $password, $tenant, $role) {
            $user = $users->findByEmail($email) ?? $users->create($email, $name, AuthService::hashPassword($password));
            if ($memberships->find($tenant->id, $user->id) === null) {
                $memberships->add($tenant->id, $user->id, $role);
            }

            return $user;
        });

        printf("User #%d %s is %s in %s\n", $user->id, $user->email, $role->value, $tenant->slug);
        if ($generated) {
            echo "Generated password (shown once, ask them to change it): {$password}\n";
        }

        return 0;
    }

    /** @param list<string> $args */
    private function seedDemo(array $args): int
    {
        if ($this->container->get(Config::class)->isProduction()) {
            throw new \RuntimeException('Demo data is not allowed when APP_ENV=production.');
        }
        [$slug] = $this->requireArgs($args, 1, 'seed:demo <tenant-slug>');
        $tenant = $this->container->get(TenantRepository::class)->findBySlug($slug)
            ?? throw new \RuntimeException('No tenant with that slug.');
        (new DemoSeeder($this->container))->run($tenant);
        echo "Demo course added to {$slug}.\n";

        return 0;
    }

    private function coursePackages(): int
    {
        $found = false;
        foreach (glob(Application::basePath('courses/*/course.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $title = is_array($data) && is_string($data['title'] ?? null) ? $data['title'] : '(no title)';
            printf("%-34s %s\n", basename(dirname($file)), $title);
            $found = true;
        }
        if (!$found) {
            echo "No course packages found in courses/.\n";
        }

        return 0;
    }

    /**
     * Splits "--flag" and "--key=value" options from positional arguments.
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private static function options(array $args): array
    {
        $positional = [];
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2] ?? '1';
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $options];
    }

    private function packageDirectory(string $package): string
    {
        $directory = is_dir($package) ? $package : Application::basePath('courses/' . basename($package));
        if (!is_file($directory . '/course.json')) {
            throw new \RuntimeException("No course package \"$package\". Run course:packages to see what is available.");
        }

        return $directory;
    }

    /** The member who will be recorded as author: --author=<email>, or the first owner/admin/educator. */
    private function author(\App\Modules\Tenancy\Tenant $tenant, ?string $email): CurrentUser
    {
        $memberships = $this->container->get(MembershipRepository::class);
        $users = $this->container->get(UserRepository::class);
        if ($email !== null) {
            $user = $users->findByEmail(strtolower($email)) ?? throw new \RuntimeException('No user with that email.');
        } else {
            $authorId = $this->container->get(Connection::class)->fetchValue(
                "SELECT user_id FROM memberships WHERE tenant_id = ? AND role IN ('owner', 'admin', 'educator') AND status = 'active' ORDER BY id LIMIT 1",
                [$tenant->id],
            );
            $user = $authorId === null ? null : $users->findById((int) $authorId);
            if ($user === null) {
                throw new \RuntimeException('Create an owner, admin or educator in this tenant first (user:create).');
            }
        }
        $membership = $memberships->find($tenant->id, $user->id);
        if ($membership === null || !$membership->isActive()) {
            throw new \RuntimeException('The author must be an active member of this tenant.');
        }

        return new CurrentUser($user, $membership);
    }

    /** @param list<string> $args */
    private function courseImport(array $args): int
    {
        [$positional, $options] = self::options($args);
        [$slug, $package] = $this->requireArgs($positional, 2, 'course:import <tenant-slug> <package> [--publish] [--author=<email>]');
        $tenant = $this->container->get(TenantRepository::class)->findBySlug($slug)
            ?? throw new \RuntimeException('No tenant with that slug.');
        $publish = isset($options['publish']);

        $result = $this->container->get(CoursePackageImporter::class)->import(
            $tenant->id,
            $this->author($tenant, $options['author'] ?? null),
            $this->packageDirectory($package),
            $publish,
        );
        printf(
            "Imported \"%s\": %d modules, %d lessons, %d quiz questions, %d files. %s\nTeaching page: /teach/courses/%d\n",
            $result['course']->title,
            $result['modules'],
            $result['lessons'],
            $result['questions'],
            $result['files'],
            $publish ? 'Published at /courses/' . $result['course']->slug . '.' : 'Saved as a draft; publish it from the Teaching page.',
            $result['course']->id,
        );

        return 0;
    }

    /** @param list<string> $args */
    private function courseSyncFiles(array $args): int
    {
        [$positional, $options] = self::options($args);
        [$slug, $package, $courseSlug] = $this->requireArgs($positional, 3, 'course:sync-files <tenant-slug> <package> <course-slug> [--author=<email>]');
        $tenant = $this->container->get(TenantRepository::class)->findBySlug($slug)
            ?? throw new \RuntimeException('No tenant with that slug.');
        $course = $this->container->get(\App\Modules\Courses\CourseRepository::class)->findBySlug($tenant->id, $courseSlug)
            ?? throw new \RuntimeException("No course \"$courseSlug\" in $slug.");

        $added = $this->container->get(CoursePackageImporter::class)->syncFiles(
            $course,
            $this->author($tenant, $options['author'] ?? null),
            $this->packageDirectory($package),
        );
        printf("Added %d file%s to \"%s\".\n", $added, $added === 1 ? '' : 's', $course->title);

        return 0;
    }

    /** @param list<string> $args */
    private function certificateRevoke(array $args): int
    {
        [$code, $reason] = $this->requireArgs($args, 2, 'certificate:revoke <code> "<reason>"');
        $certificate = $this->container->get(CertificateService::class)->revoke($code, $reason);
        printf("Revoked %s (%s, %s).\n", $certificate->displayCode(), $certificate->learnerName, $certificate->courseTitle);

        return 0;
    }

    private function mailWork(): int
    {
        $lock = fopen(sys_get_temp_dir() . '/dml-lms-mail-work.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Another mail worker is running.\n";

            return 0;
        }
        $result = $this->container->get(MailOutbox::class)->deliverDue(100);
        if (array_sum($result) > 0) {
            printf("sent %d, retrying %d, failed %d\n", $result['sent'], $result['retrying'], $result['failed']);
        }
        flock($lock, LOCK_UN);

        return $result['failed'] > 0 ? 2 : 0;
    }

    private function mailStatus(): int
    {
        $settings = $this->container->get(MailSettings::class);
        printf("driver: %s (%s)\n", $settings->driver(), $settings->deliveryAvailable() ? 'emails are offered to users' : 'email features hidden');
        foreach ($this->container->get(MailOutbox::class)->summary() as $status => $count) {
            printf("%-8s %d\n", $status, $count);
        }

        return 0;
    }

    /** @param list<string> $args */
    private function mailTest(array $args): int
    {
        [$to] = $this->requireArgs($args, 1, 'mail:test <email>');
        $driver = $this->container->get(MailSettings::class)->driver();
        if ($driver === 'disabled') {
            echo "Email is disabled (MAIL_DRIVER=disabled). Nothing sent.\n";

            return 1;
        }
        $this->container->get(Mailer::class)->send(new Message(
            $to,
            null,
            'DML Learning Platform: test email',
            "This is a test from the DML Learning Platform.\nIf you can read this, outgoing email works.\n",
        ));
        if ($driver !== 'smtp') {
            printf("MAIL_DRIVER=%s: the message was written to storage/mail, not sent. Set MAIL_DRIVER=smtp to send real email.\n", $driver);

            return 1;
        }
        printf("Sent to %s through the mail server.\n", $to);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function requireArgs(array $args, int $count, string $usage): array
    {
        if (count($args) < $count) {
            throw new \RuntimeException('Usage: ' . $usage);
        }

        return array_slice($args, 0, $count);
    }
}
