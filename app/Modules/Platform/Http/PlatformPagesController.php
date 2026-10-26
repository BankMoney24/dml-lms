<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;

/**
 * Pages about the platform itself rather than the school (ADR 0033): who built it and
 * how to reach them (/developers), the accessibility statement (/accessibility), and
 * /.well-known/security.txt for people reporting security problems (RFC 9116).
 */
final class PlatformPagesController
{
    public function __construct(
        private readonly View $view,
        private readonly string $securityContact = '',
    ) {
    }

    public function developers(Request $request): Response
    {
        return Response::html($this->view->render('site/developers', [
            'pageTitle' => 'Developers',
            'metaDescription' => 'Who built this learning platform, how to report a problem, and how to work with TridaxLabs.',
            'securityContact' => $this->contact(),
            'seoCrumbs' => ['Developers' => '/developers'],
        ]));
    }

    public function accessibility(Request $request): Response
    {
        return Response::html($this->view->render('site/accessibility', [
            'pageTitle' => 'Accessibility',
            'metaDescription' => 'How this learning platform is built to be usable by everyone, what we test, and how to tell us about a problem.',
            'school' => RequestContext::tenant($request),
            'seoCrumbs' => ['Accessibility' => '/accessibility'],
        ]));
    }

    public function securityTxt(Request $request): Response
    {
        $expires = gmdate('Y-m-d\T00:00:00\Z', (int) strtotime('+1 year'));
        $body = "# Report security problems in this learning platform here. Please do not test against other people's accounts.\n"
            . 'Contact: ' . $this->contact() . "\n"
            . 'Expires: ' . $expires . "\n"
            . "Preferred-Languages: en\n"
            . 'Canonical: ' . $request->baseUrl() . "/.well-known/security.txt\n"
            . 'Policy: ' . $request->baseUrl() . "/developers#security\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** SECURITY_CONTACT from .env (an email or https address); TridaxLabs' website otherwise. */
    private function contact(): string
    {
        $c = trim($this->securityContact);
        if (filter_var($c, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:' . $c;
        }

        return str_starts_with($c, 'https://') ? $c : 'https://tridax.org';
    }
}
