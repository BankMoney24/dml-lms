<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Config;
use App\Core\Container;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Database\Seeders\DemoSeeder;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
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
