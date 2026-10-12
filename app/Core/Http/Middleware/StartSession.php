<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Core\Session\Session;
use App\Core\View\View;

final class StartSession implements Middleware
{
    public function __construct(
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly View $view,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start();
        $this->view->share('csrf_token', $this->csrf->token());
        $this->view->share('flash_success', $this->session->pullFlash('success'));
        $this->view->share('flash_error', $this->session->pullFlash('error'));

        return $next($request);
    }
}
