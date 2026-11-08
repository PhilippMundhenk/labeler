<?php

// Router for PHP's built-in server:  php -S 0.0.0.0:8080 -t public src/router.php
// Static files get caching headers and gzip (StaticFiles); everything else, api.php included,
// is left to the built-in server.

declare(strict_types=1);

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/StaticFiles.php';

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strncmp($key, 'HTTP_', 5) === 0 && is_string($value)) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
$response = (new Labeler\StaticFiles(dirname(__DIR__) . '/public'))->serve((string) ($_SERVER['REQUEST_URI'] ?? '/'), $headers);
if ($response === null) {
    return false;
}
[$status, $responseHeaders, $body] = $response->parts();
http_response_code($status);
foreach ($responseHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $body;
return true;
