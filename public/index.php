<?php

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Core\Config;
use App\Core\Http\Kernel;
use App\Core\Http\Request;
use App\Modules\Notifications\MailOutbox;

// Releases are switched by repointing the "current" symlink. Where the web
// server hands PHP the symlinked path (Apache/LiteSpeed docroots), PHP's
// realpath cache can keep resolving the old release for minutes, so resolve
// the live release explicitly on each request. Nginx passes $realpath_root,
// which already points at the real release directory.
clearstatcache(true);
$base = dirname(__DIR__);
$docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($docRoot !== '') {
    $resolved = realpath($docRoot . '/..');
    if ($resolved !== false && is_file($resolved . '/vendor/autoload.php') && is_file($resolved . '/public/index.php')) {
        $base = $resolved;
    }
}

require $base . '/vendor/autoload.php';

// In production .env lives in the shared directory and is linked into the release.
$config = Config::load($base . '/.env');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');

$container = Application::create($config);
$container->get(Kernel::class)->handle(Request::fromGlobals())->send();

// Emails queued by this request are sent after the browser has its response,
// so a slow mail server never slows a page. Anything that fails is retried
// by `bin/console mail:work` (run every minute from cron).
if ($container->resolved(MailOutbox::class)) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        $container->get(MailOutbox::class)->deliverQueuedThisRequest();
    } catch (Throwable) {
        // The outbox has already recorded the message; the worker will retry it.
    }
}
