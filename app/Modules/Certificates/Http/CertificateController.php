<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Certificates\CertificateService;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Permission;

final class CertificateController
{
    public function __construct(
        private readonly View $view,
        private readonly CertificateService $certificates,
        private readonly CourseRepository $courses,
    ) {
    }

    /** The printable certificate: for its holder, and for staff who can see reports. */
    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $certificate = $this->certificates->find($tenant->id, $request->routeParam('code'));
        if ($certificate === null || ($certificate->userId !== $user->user->id && !$user->can(Permission::ReportsView))) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('certificates/show', [
            'pageTitle' => 'Certificate: ' . $certificate->courseTitle,
            'certificate' => $certificate,
            'course' => $this->courses->findById($tenant->id, $certificate->courseId),
            'verifyUrl' => $request->baseUrl() . '/verify/' . $certificate->displayCode(),
        ]));
    }

    /** Public check for employers and other schools. Shows only what is printed on the certificate. */
    public function verify(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $raw = $request->routeParam('code');
        if ($raw === '') {
            $raw = trim($request->query('code', '') ?? '');
        }
        if ($raw === '') {
            return Response::html($this->view->render('certificates/verify', ['pageTitle' => 'Check a certificate', 'code' => '', 'certificate' => null, 'searched' => false]));
        }
        $certificate = $this->certificates->find($tenant->id, $raw);

        $response = Response::html($this->view->render('certificates/verify', [
            'pageTitle' => 'Check a certificate',
            'code' => $raw,
            'certificate' => $certificate,
            'searched' => true,
        ]), $certificate === null ? 404 : 200);

        return $response->withHeader('X-Robots-Tag', 'noindex');
    }
}
