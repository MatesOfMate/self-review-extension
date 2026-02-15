<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Router script for PHP built-in server.
 * Routes API requests to ApiController, serves static files otherwise.
 */

// Get request URI
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url((string) $uri, \PHP_URL_PATH);

// API routes
if (str_starts_with((string) $path, '/api/')) {
    require __DIR__.'/api.php';
    exit;
}

// Serve static files from document root (where server was started)
$publicDir = $_SERVER['DOCUMENT_ROOT'];
$requestedFile = $publicDir.$path;

// If it's a directory, look for index.html
if (is_dir($requestedFile)) {
    $requestedFile = rtrim($requestedFile, '/').'/index.html';
}

// If file exists, let PHP built-in server handle it
if (file_exists($requestedFile) && !is_dir($requestedFile)) {
    return false; // Let PHP serve the static file
}

// For SPA routing, serve index.html
$indexFile = $publicDir.'/index.html';
if (file_exists($indexFile)) {
    header('Content-Type: text/html');
    readfile($indexFile);
    exit;
}

// 404 if nothing found
http_response_code(404);
echo '404 Not Found';
