<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Mail\MailSettings;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;

/**
 * Platform administrators create schools (TEN-001), give them web
 * addresses, and suspend or reactivate them. The first owner is invited by
 * email when email works, or gets a one-time password shown once.
 *
 * Every action is checked here (the caller must be a platform admin) and
 * recorded in the audit log of the school it concerns.
 */
final class SchoolAdminService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantRepository $tenants,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly AccountLinkService $links,
        private readonly MailSettings $mail,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @return array{tenant: Tenant, owner_email: string, owner_name: string, one_time_password: ?string, emailed: bool, existing_account: bool, address: string}
     */
    public function create(CurrentUser $admin, string $name, string $host, string $ownerName, string $ownerEmail, string $currentBaseUrl, string $clientIp): array
    {
        $this->authorise($admin);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $host = self::normaliseHost($host);
        $ownerName = trim((string) preg_replace('/\s+/u', ' ', $ownerName));
        $ownerEmail = UserRepository::normaliseEmail($ownerEmail);

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter the name of the school (2 to 120 characters).';
        }
        $hostProblem = $this->hostProblem($host);
        if ($hostProblem !== null) {
            $errors['host'] = $hostProblem;
        }
        if (mb_strlen($ownerName) < 2 || mb_strlen($ownerName) > 120) {
            $errors['owner_name'] = "Enter the owner's full name.";
        }
        if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['owner_email'] = 'Enter an email address like head@school.ng.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $emailing = $this->mail->deliveryAvailable();
        $result = $this->db->transaction(function () use ($admin, $name, $host, $ownerName, $ownerEmail, $emailing, $clientIp): array {
            $tenant = $this->tenants->create($this->freeSlug($name), $name);
            $this->tenants->addDomain($tenant->id, $host, true, true);

            $user = $this->users->findByEmail($ownerEmail);
            $password = null;
            $existing = $user !== null;
            if ($user === null) {
                $password = $emailing ? bin2hex(random_bytes(24)) : MemberService::oneTimePassword();
                $user = $this->users->create($ownerEmail, $ownerName, AuthService::hashPassword($password), false, true);
            }
            $this->memberships->add($tenant->id, $user->id, Role::Owner);

            $this->audit->record($tenant->id, $admin->user->id, 'school.created', 'tenant', $tenant->id, ['host' => $host], $clientIp);
            $this->audit->record($tenant->id, $admin->user->id, 'member.added', 'user', $user->id, ['role' => Role::Owner->value], $clientIp);

            return [
                'tenant' => $tenant,
                'user' => $user,
                'one_time_password' => $emailing || $existing ? null : $password,
                'existing_account' => $existing,
            ];
        });

        $address = self::baseUrlFor($host, $currentBaseUrl);
        if ($emailing) {
            $result['existing_account']
                ? $this->links->sendAddedNotice($result['tenant'], $result['user'], $admin, Role::Owner, $address)
                : $this->links->sendInvitation($result['tenant'], $result['user'], $admin, Role::Owner, $address);
        }

        return [
            'tenant' => $result['tenant'],
            'owner_email' => $result['user']->email,
            'owner_name' => $result['user']->displayName,
            'one_time_password' => $result['one_time_password'],
            'emailed' => $emailing,
            'existing_account' => $result['existing_account'],
            'address' => $address,
        ];
    }

    public function addAddress(CurrentUser $admin, int $tenantId, string $host, string $clientIp): string
    {
        $this->authorise($admin);
        $tenant = $this->tenants->findById($tenantId) ?? throw ValidationException::single('host', 'That school no longer exists.');
        $host = self::normaliseHost($host);
        $problem = $this->hostProblem($host);
        if ($problem !== null) {
            throw ValidationException::single('host', $problem);
        }
        $this->tenants->addDomain($tenant->id, $host, true, $this->tenants->domains($tenant->id) === []);
        $this->audit->record($tenant->id, $admin->user->id, 'school.address_added', 'tenant', $tenant->id, ['host' => $host], $clientIp);

        return $host;
    }

    /** $currentTenantId: the school whose address the admin is using, which cannot be suspended from under them. */
    public function setStatus(CurrentUser $admin, int $tenantId, string $status, int $currentTenantId, string $clientIp): Tenant
    {
        $this->authorise($admin);
        $tenant = $this->tenants->findById($tenantId) ?? throw ValidationException::single('status', 'That school no longer exists.');
        if ($status === Tenant::STATUS_SUSPENDED && $tenant->id === $currentTenantId) {
            throw ValidationException::single('status', 'You are signed in through this school, so suspending it would lock you out. Suspend it from another school\'s address.');
        }
        if ($tenant->status === $status) {
            return $tenant;
        }
        $this->tenants->setStatus($tenant->id, $status);
        $this->audit->record($tenant->id, $admin->user->id, $status === Tenant::STATUS_SUSPENDED ? 'school.suspended' : 'school.reactivated', 'tenant', $tenant->id, [], $clientIp);

        return $this->tenants->findById($tenant->id) ?? $tenant;
    }

    /** "https://Learn.School.ng:8443/x" → "learn.school.ng"; anything that is not a plain host is left for hostProblem() to refuse. */
    public static function normaliseHost(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);
        $value = (string) preg_replace('/:\d+$/', '', $value);

        return rtrim($value, '.');
    }

    /** The address people will use, keeping the scheme and port of the address the admin is on. */
    public static function baseUrlFor(string $host, string $currentBaseUrl): string
    {
        $scheme = (string) (parse_url($currentBaseUrl, PHP_URL_SCHEME) ?? 'https');
        $port = parse_url($currentBaseUrl, PHP_URL_PORT);

        return $scheme . '://' . $host . ($port === null || $port === false ? '' : ':' . $port);
    }

    private function hostProblem(string $host): ?string
    {
        $isIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isName = preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
        if (!$isIp && !$isName) {
            return 'Enter a web address such as learn.yourschool.ng (no https:// and nothing after the name).';
        }
        if ($this->tenants->hostExists($host)) {
            return 'That address is already used by a school.';
        }

        return null;
    }

    private function freeSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name))), '-');
        $base = substr($base === '' || strlen($base) < 2 ? 'school' : $base, 0, 50);
        $base = rtrim($base, '-');
        $slug = $base;
        for ($n = 2; $this->tenants->slugExists($slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function authorise(CurrentUser $admin): void
    {
        if (!$admin->user->isPlatformAdmin) {
            throw HttpException::notFound();
        }
    }
}
