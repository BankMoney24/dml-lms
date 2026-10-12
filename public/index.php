<?php

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Core\Config;
use App\Core\Http\Kernel;
use App\Core\Http\Request;

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
