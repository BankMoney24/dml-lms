<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Container;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Core\Log\Logger;
use App\Core\View\View;
use Throwable;

/**
 * Turns a Request into a Response: global middleware, route matching,
 * route middleware, controller dispatch and error rendering.
 */
final class Kernel
{
    /** Route middleware marker: skip session/tenant/auth globals (health checks). */
    public const BARE = 'bare';

    /**
     * @param list<string>                         $globalMiddleware service ids, outermost first
     * @param callable(string): Middleware         $routeMiddlewareFactory
     */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $globalMiddleware,
        private readonly mixed $routeMiddlewareFactory,
        private readonly View $view,
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $request = $request->withAttribute('request_id', $requestId);
        $this->view->resetShared();
        $this->view->share('request_id', $requestId);
        $this->view->share('path', $request->path);

        try {
            try {
                [$route, $params] = $this->router->match($request->method, $request->path);
            } catch (HttpException $e) {
                return $this->unmatched($request, $e, $requestId);
            }
            $request = $request->withAttribute('route.params', $params);
            $isBare = in_array(self::BARE, $route->middleware, true);

            $stack = [];
            foreach ($isBare ? [] : $this->globalMiddleware as $id) {
                $stack[] = $this->container->get($id);
            }
            foreach ($route->middleware as $spec) {
                if ($spec !== self::BARE) {
                    $stack[] = ($this->routeMiddlewareFactory)($spec);
                }
            }

            $response = $this->pipeline($stack, $request, function (Request $request) use ($route): Response {
                [$class, $method] = $route->handler;
                $controller = $this->container->get($class);

                return $controller->{$method}($request);
            });
        } catch (HttpException $e) {
            $response = $this->renderError($e->status, $e->getMessage(), $requestId);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', ['request_id' => $requestId, 'path' => $request->path], $e);
            $message = $this->debug
                ? $e::class . ': ' . $e->getMessage()
                : 'Something went wrong on our side. Please try again in a moment.';
            $response = $this->renderError(500, $message, $requestId);
        }

        return (new SecurityHeaders($request->secure))->process($request, static fn () => $response)
            ->withHeader('X-Request-Id', $requestId);
    }

    /**
     * No route matched. For a page someone tried to open (GET or HEAD), run the
     * global middleware first so the error page carries the school's name and
     * the signed-in person's navigation instead of the bare platform frame.
     */
    private function unmatched(Request $request, HttpException $e, string $requestId): Response
    {
        $render = fn (): Response => $this->renderError($e->status, $e->getMessage(), $requestId);
        $response = null;
        if (in_array($request->method, ['GET', 'HEAD'], true)) {
            try {
                $stack = array_map(fn (string $id): object => $this->container->get($id), $this->globalMiddleware);
                $response = $this->pipeline($stack, $request, static fn (Request $r): Response => $render());
            } catch (Throwable) {
                // Unknown host, database down, ...: fall back to the plain error page.
                $this->view->resetShared();
                $this->view->share('request_id', $requestId);
                $this->view->share('path', $request->path);
            }
        }
        $response ??= $render();

        return (new SecurityHeaders($request->secure))->process($request, static fn () => $response)
            ->withHeader('X-Request-Id', $requestId);
    }

    /**
     * @param list<object>              $stack
     * @param callable(Request): Response $core
     */
    private function pipeline(array $stack, Request $request, callable $core): Response
    {
        $next = $core;
        foreach (array_reverse($stack) as $middleware) {
            if (!$middleware instanceof Middleware) {
                continue;
            }
            $next = static fn (Request $r): Response => $middleware->process($r, $next);
        }

        return $next($request);
    }

    private function renderError(int $status, string $message, string $requestId): Response
    {
        $titles = [
            400 => 'Something is wrong with that request',
            403 => 'You do not have access',
            404 => 'Page not found',
            405 => 'Not available',
            409 => 'That could not be done',
            413 => 'Too much to send',
            419 => 'Session expired',
            503 => 'Temporarily unavailable',
        ];
        try {
            $title = $titles[$status] ?? 'Something went wrong';
            $html = $this->view->render('errors/error', [
                'pageTitle' => $title,
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'requestId' => $requestId,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Error page failed to render', ['request_id' => $requestId], $e);
            $html = '<!doctype html><title>Error</title><p>Something went wrong. Reference: '
                . htmlspecialchars($requestId) . '</p>';
        }

        return Response::html($html, $status);
    }
}
