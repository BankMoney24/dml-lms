<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Database\Connection;
use App\Core\Log\Logger;
use App\Core\Mail\MailSettings;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Courses\CoursePackages;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\LogoStore;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\SchoolTheme;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use App\Modules\Wizards\ReviewStep;
use App\Modules\Wizards\Wizard;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;
use Throwable;

/**
 * Institution onboarding (PRD §6.4): type, academic year, name and look,
 * learning features, first team members, then a review. The first finish
 * "launches" the school; later runs edit the same settings, starting from
 * what the school already has.
 *
 * Billing and policy (step 7 in the PRD) arrive with payments.
 */
final class SchoolSetupWizard implements Wizard
{
    public const ID = 'school';
    public const RESULT_FLASH = 'setup_result';

    /** @var list<WizardStep>|null */
    private ?array $steps = null;

    public function __construct(
        private readonly Connection $db,
        private readonly TenantRepository $tenants,
        private readonly LogoStore $logos,
        private readonly CoursePackages $packages,
        private readonly CoursePackageImporter $importer,
        private readonly CourseRepository $courses,
        private readonly MemberService $members,
        private readonly AccountLinkService $links,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly MailSettings $mail,
        private readonly Session $session,
        private readonly Logger $logger,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function runKey(\App\Modules\Wizards\WizardContext $ctx): string
    {
        return $this->id();
    }

    public function title(): string
    {
        return 'School setup';
    }

    public function mayRun(CurrentUser $user): bool
    {
        return $user->can(Permission::SchoolManage);
    }

    public function steps(): array
    {
        return $this->steps ??= [
            new WelcomeStep(),
            new TypeStep(),
            new StructureStep(),
            new BrandStep($this->logos, $this->tenants),
            new FeaturesStep($this->packages, $this->courses),
            new PeopleStep($this->users, $this->memberships, $this->mail),
            new ReviewStep('Check and confirm', 'Nothing changes until you press the button at the bottom of this page.'),
        ];
    }

    public function startsComplete(WizardContext $ctx): bool
    {
        return $ctx->tenant->isSetUp();
    }

    public function problems(WizardContext $ctx, array $data): array
    {
        $problems = [];
        if (($data['brand']['name'] ?? '') === '') {
            $problems[] = ['step' => 'brand', 'message' => 'Your institution needs a name.', 'blocking' => true];
        }
        if (($data['type']['kind'] ?? '') === '') {
            $problems[] = ['step' => 'type', 'message' => 'Choose the type of institution.', 'blocking' => true];
        }
        if (($data['brand']['contact_email'] ?? '') === '' && ($data['brand']['contact_phone'] ?? '') === '') {
            $problems[] = ['step' => 'brand', 'message' => 'No email or phone number: learners and parents will have no way to contact you from the site.', 'blocking' => false];
        }
        $hasCourses = $this->courses->titles($ctx->tenant->id) !== [];
        if (!$hasCourses && ($data['features']['packages'] ?? []) === []) {
            $problems[] = ['step' => 'features', 'message' => 'Your school has no courses yet. Pick a starter course, or create one after launch.', 'blocking' => false];
        }
        if (($data['people']['list'] ?? []) !== [] && !$this->mail->deliveryAvailable()) {
            $problems[] = ['step' => 'people', 'message' => 'Email is not set up, so after launch you will see one-time passwords to hand to each person.', 'blocking' => false];
        }

        return $problems;
    }

    public function finishLabel(WizardContext $ctx): string
    {
        return $ctx->tenant->isSetUp() ? 'Save changes' : 'Launch your school';
    }

    public function finish(WizardContext $ctx, array $data): string
    {
        $tenant = $ctx->tenant;
        $brand = $data['brand'];
        $structure = $data['structure'] ?? [];
        $features = $data['features'];
        $firstLaunch = !$tenant->isSetUp();
        $newLogo = is_string($brand['logo_key'] ?? null) && LogoStore::belongsTo($brand['logo_key'], $tenant->id) ? $brand['logo_key'] : null;

        $this->db->transaction(function () use ($tenant, $brand, $structure, $features, $data, $newLogo): void {
            $this->tenants->updateProfile($tenant->id, [
                'name' => (string) $brand['name'],
                'kind' => (string) $data['type']['kind'],
                'tagline' => (string) ($brand['tagline'] ?? ''),
                'theme' => SchoolTheme::exists((string) ($brand['theme'] ?? '')) ? (string) $brand['theme'] : SchoolTheme::DEFAULT,
                'contact_email' => (string) ($brand['contact_email'] ?? ''),
                'contact_phone' => (string) ($brand['contact_phone'] ?? ''),
                'website' => (string) ($brand['website'] ?? ''),
                'calendar' => isset($structure['calendar']) && $structure['calendar'] !== '' ? (string) $structure['calendar'] : $tenant->calendar,
                'levels' => isset($structure['levels']) ? StructureStep::levels((string) $structure['levels']) : $tenant->levels,
                'group_term' => isset(Tenant::GROUP_TERMS[(string) ($structure['group_term'] ?? '')]) ? (string) $structure['group_term'] : $tenant->groupTerm,
                'learner_term' => isset(Tenant::LEARNER_TERMS[(string) ($structure['learner_term'] ?? '')]) ? (string) $structure['learner_term'] : $tenant->learnerTerm,
                'certificates_enabled' => (bool) ($features['certificates'] ?? true),
                'public_catalogue' => (bool) ($features['public_catalogue'] ?? true),
            ]);
            if ($newLogo !== $tenant->logoKey) {
                $this->tenants->setLogo($tenant->id, $newLogo, $newLogo === null ? null : (string) $brand['logo_type']);
            }
            $this->tenants->markSetUp($tenant->id);
        });
        if ($newLogo !== $tenant->logoKey) {
            $this->logos->delete($tenant->logoKey);
        }

        $result = [
            'first' => $firstLaunch,
            'courses' => $this->importPackages($ctx, (array) ($features['packages'] ?? [])),
            'people' => $this->invite($ctx, is_array($data['people']['list'] ?? null) ? $data['people']['list'] : []),
        ];
        $this->session->flash(self::RESULT_FLASH, json_encode($result, JSON_THROW_ON_ERROR));

        return '/school';
    }

    public function discarded(WizardContext $ctx, array $data): void
    {
        $key = $data['brand']['logo_key'] ?? null;
        if (is_string($key) && $key !== $ctx->tenant->logoKey && LogoStore::belongsTo($key, $ctx->tenant->id)) {
            $this->logos->delete($key);
        }
    }

    public function exitPath(): string
    {
        return '/school';
    }

    /**
     * @param array<mixed> $names
     * @return list<array{title: string, id: ?int, error: ?string}>
     */
    private function importPackages(WizardContext $ctx, array $names): array
    {
        $done = [];
        $have = $this->courses->titles($ctx->tenant->id);
        foreach ($this->packages->all() as $package) {
            if (!in_array($package['name'], $names, true) || in_array($package['title'], $have, true)) {
                continue;
            }
            $directory = $this->packages->directory($package['name']);
            try {
                if ($directory === null) {
                    throw new \RuntimeException('package missing');
                }
                $imported = $this->importer->import($ctx->tenant->id, $ctx->user, $directory, false);
                $done[] = ['title' => $package['title'], 'id' => $imported['course']->id, 'error' => null];
            } catch (Throwable $e) {
                $this->logger->error('Starter course import failed', ['package' => $package['name'], 'error' => $e->getMessage()]);
                $done[] = ['title' => $package['title'], 'id' => null, 'error' => 'It could not be added. Try again from Teaching, or ask your platform administrator.'];
            }
        }

        return $done;
    }

    /**
     * @param array<mixed> $list
     * @return list<array{name: string, email: string, role: string, one_time_password: ?string, existing_account: bool, emailed: bool, error: ?string}>
     */
    private function invite(WizardContext $ctx, array $list): array
    {
        $emailing = $this->mail->deliveryAvailable();
        $results = [];
        foreach ($list as $person) {
            if (!is_array($person)) {
                continue;
            }
            $row = [
                'name' => (string) ($person['name'] ?? ''),
                'email' => (string) ($person['email'] ?? ''),
                'role' => (string) ($person['role'] ?? ''),
                'one_time_password' => null,
                'existing_account' => false,
                'emailed' => false,
                'error' => null,
            ];
            try {
                $added = $this->members->add($ctx->tenant, $ctx->user, $row['name'], $row['email'], $row['role'], $ctx->request->clientIp, !$emailing);
                $row['one_time_password'] = $added['one_time_password'];
                $row['existing_account'] = $added['existing_account'];
                $user = $this->users->findById($added['user_id']);
                if ($emailing && $user !== null) {
                    $tenant = $this->tenants->findById($ctx->tenant->id) ?? $ctx->tenant;
                    $role = Role::from($added['role']);
                    $added['existing_account']
                        ? $this->links->sendAddedNotice($tenant, $user, $ctx->user, $role, \App\Core\Http\RequestContext::baseUrl($ctx->request))
                        : $this->links->sendInvitation($tenant, $user, $ctx->user, $role, \App\Core\Http\RequestContext::baseUrl($ctx->request));
                    $row['emailed'] = true;
                }
            } catch (ValidationException $e) {
                $row['error'] = implode(' ', $e->errors);
            }
            $results[] = $row;
        }

        return $results;
    }
}
