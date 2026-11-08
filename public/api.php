<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = Labeler\Config::load(dirname(__DIR__));
$api = new Labeler\Api($config);
$response = $api->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    array_filter($_GET, 'is_string'),
    (string) file_get_contents('php://input'),
    $_SERVER['CONTENT_TYPE'] ?? '',
    // Apache drops Authorization unless told otherwise; X-Labeler-Token always arrives
    $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (isset($_SERVER['HTTP_X_LABELER_TOKEN']) ? 'Bearer ' . $_SERVER['HTTP_X_LABELER_TOKEN'] : ''),
    array_filter($_COOKIE, 'is_string'),
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);
[$status, $headers, $body] = $response->parts();
http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $body;
