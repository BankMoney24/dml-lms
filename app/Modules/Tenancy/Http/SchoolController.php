<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Tenancy\LogoStore;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Setup\SchoolSetupWizard;
use App\Modules\Tenancy\TenantRepository;
use App\Modules\Wizards\WizardRunRepository;

/** The school's own settings page, and its logo. */
final class SchoolController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly TenantRepository $tenants,
        private readonly WizardRunRepository $runs,
        private readonly SchoolSetupWizard $wizard,
        private readonly LogoStore $logos,
        private readonly MembershipRepository $memberships,
        private readonly CourseRepository $courses,
        private readonly ?\App\Modules\Payments\PaymentAccountRepository $paymentAccounts = null,
        private readonly ?\App\Modules\Tenancy\LaunchChecklist $checklist = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $run = $this->runs->find($tenant->id, $this->wizard->id());
        $result = json_decode((string) $this->session->pullFlash(SchoolSetupWizard::RESULT_FLASH), true);
        $steps = array_values(array_filter($this->wizard->steps(), static fn ($s): bool => !$s->isReview()));
        $handled = $run === null ? 0 : count(array_filter($steps, static fn ($s): bool => $run->isHandled($s->key())));

        return Response::html($this->view->render('school/index', [
            'pageTitle' => 'Your school',
            'school' => $tenant,
            'run' => $run,
            'progress' => ['handled' => $handled, 'total' => count($steps)],
            'result' => is_array($result) ? $result : null,
            'domains' => $this->tenants->domains($tenant->id),
            'memberCount' => array_sum($this->memberships->countByRole($tenant->id)),
            'courseCount' => count($this->courses->titles($tenant->id)),
            'payments' => $this->paymentAccounts?->find($tenant->id),
            'launch' => $this->checklist?->items($tenant) ?? [],
        ]));
    }

    /** Public: shown in the header before anyone signs in. The URL carries a version, so it can be cached. */
    public function logo(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $path = $tenant->hasLogo() ? $this->logos->path($tenant->logoKey) : null;
        if ($path === null || $tenant->logoType === null) {
            throw HttpException::notFound();
        }

        return Response::file($path, $tenant->logoType, 'logo', true)
            ->withHeader('Cache-Control', 'public, max-age=604800');
    }

    /** A logo uploaded in the setup wizard but not yet launched. Only for people who may run the wizard. */
    public function logoPreview(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $run = $this->runs->find($tenant->id, $this->wizard->id());
        $key = $run?->data['brand']['logo_key'] ?? null;
        $type = $run?->data['brand']['logo_type'] ?? null;
        $path = is_string($key) && LogoStore::belongsTo($key, $tenant->id) ? $this->logos->path($key) : null;
        if ($path === null || !is_string($type)) {
            throw HttpException::notFound();
        }

        return Response::file($path, $type, 'logo', true);
    }
}
