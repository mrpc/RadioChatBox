<?php

/**
 * RadioChatBox front controller.
 *
 * Deliberately thin: it only locates the project, autoloads, and hands off to
 * the HTTP kernel that lives OUTSIDE the web root (bootstrap/http.php). All
 * request handling — routing, middleware, migrations, the SPA shell — is there.
 *
 * A single public/.htaccess routes every request that is not a real file/dir to
 * this file, so the app needs no VirtualHost-specific configuration and runs on
 * plain shared hosting (including a subdirectory install — the framework Request
 * strips the front-controller directory from the path).
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__));   // public/index.php -> project root
define('PUBLIC_PATH', __DIR__);     // the web root (asset paths, cache-busting)

require ROOT . '/vendor/autoload.php';
require ROOT . '/bootstrap/http.php';
