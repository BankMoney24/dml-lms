<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Mail\MailSettings;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Identity\CurrentUser;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;

/** Platform administrators: every school on this installation. Everyone else gets "not found". */
final class SchoolsController
{
    private const CREATED_FLASH = 'school_created';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly TenantRepository $tenants,
        private readonly SchoolAdminService $schools,
        private readonly MailSettings $mail,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->admin($request);

        return $this->renderIndex($request);
    }

    public function store(Request $request): Response
    {
        $admin = $this->admin($request);
        $old = [];
        foreach (['name', 'host', 'owner_name', 'owner_email'] as $field) {
            $old[$field] = $request->input($field, '') ?? '';
        }
        try {
            $created = $this->schools->create($admin, $old['name'], $old['host'], $old['owner_name'], $old['owner_email'], $request->baseUrl(), $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors, $old, 422);
        }
        $this->session->flash(self::CREATED_FLASH, json_encode([
            'name' => $created['tenant']->name,
            'id' => $created['tenant']->id,
            'address' => $created['address'],
            'owner_name' => $created['owner_name'],
            'owner_email' => $created['owner_email'],
            'one_time_password' => $created['one_time_password'],
            'emailed' => $created['emailed'],
            'existing_account' => $created['existing_account'],
        ], JSON_THROW_ON_ERROR));

        return Response::redirect('/system/schools');
    }

    public function show(Request $request): Response
    {
        $this->admin($request);

        return $this->renderShow($request, $request->routeInt('school'));
    }

    public function addAddress(Request $request): Response
    {
        $admin = $this->admin($request);
        $id = $request->routeInt('school');
        try {
            $host = $this->schools->addAddress($admin, $id, $request->input('host', '') ?? '', $request->clientIp);
            $this->session->flash('success', sprintf('%s now opens this school, once it points at this server.', $host));
        } catch (ValidationException $e) {
            return $this->renderShow($request, $id, $e->errors, ['host' => $request->input('host', '') ?? ''], 422);
        }

        return Response::redirect('/system/schools/' . $id);
    }

    public function status(Request $request): Response
    {
        $admin = $this->admin($request);
        $id = $request->routeInt('school');
        $status = $request->input('status') === Tenant::STATUS_SUSPENDED ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE;
        try {
            $tenant = $this->schools->setStatus($admin, $id, $status, RequestContext::tenant($request)->id, $request->clientIp);
            $this->session->flash('success', $tenant->isActive()
                ? sprintf('%s is open again.', $tenant->name)
                : sprintf('%s is suspended. Its pages now say it is temporarily unavailable.', $tenant->name));
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/system/schools/' . $id);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $created = json_decode((string) $this->session->pullFlash(self::CREATED_FLASH), true);

        return Response::html($this->view->render('platform/schools', [
            'pageTitle' => 'Schools',
            'schools' => $this->tenants->overview(),
            'created' => is_array($created) ? $created : null,
            'errors' => $errors,
            'old' => $old,
            'emailing' => $this->mail->deliveryAvailable(),
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderShow(Request $request, int $id, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = $this->tenants->findById($id) ?? throw HttpException::notFound();
        $row = null;
        foreach ($this->tenants->overview() as $r) {
            if ($r['tenant']->id === $id) {
                $row = $r;
            }
        }

        return Response::html($this->view->render('platform/school', [
            'pageTitle' => $tenant->name,
            'school' => $tenant,
            'stats' => $row,
            'domains' => $this->tenants->domains($tenant->id),
            'isCurrent' => RequestContext::tenant($request)->id === $tenant->id,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    private function admin(Request $request): CurrentUser
    {
        $user = RequestContext::user($request);
        if (!$user->user->isPlatformAdmin) {
            throw HttpException::notFound();
        }

        return $user;
    }
}
