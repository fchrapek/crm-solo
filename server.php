<?php

declare(strict_types=1);

// Custom router for `php -S` that replaces Laravel's vendor server.php.
//
// The only meaningful difference vs vendor: the request-log `file_put_contents`
// is `@`-suppressed. PHP's built-in server emits "Broken pipe (errno=32)"
// notices when a client disconnects mid-response (typical with the 3s Inertia
// poll on /tasks/{id}). The notice is harmless — the response already shipped
// or was aborted upstream — but the spam was making the dev terminal unusable
// and was getting mistaken for a real failure.
//
// We can't tell PHP's built-in server to skip the vendor router file (it's
// hardcoded in Laravel's ServeCommand), so the composer `dev` script invokes
// `php -S` directly against this file instead of going through `artisan serve`.

$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Mod_rewrite emulation: real static files in public/ are served directly,
// everything else falls through to public/index.php (Laravel's front controller).
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

// `@` suppresses the "Broken pipe (errno=32)" Notice when the client closed
// the socket before we could write the request log line. Vendor's server.php
// is identical to this except for the missing `@`.
@file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
