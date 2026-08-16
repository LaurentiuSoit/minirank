<?php
declare(strict_types=1);

// PHP built-in development server router.
// Serves static assets from /assets/ and forwards all other requests to index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static assets from /assets/ — reject path traversal.
if (str_starts_with($path, '/assets/') && !str_contains($path, '..')) {
    $staticFile = __DIR__ . $path;
    if (is_file($staticFile)) {
        return false; // Let the built-in server serve the file.
    }
    http_response_code(404);
    echo 'Asset not found.';
    return;
}

// Forward all application requests to the entry point.
require __DIR__ . '/index.php';
