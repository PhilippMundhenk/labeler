<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = Labeler\Config::load(dirname(__DIR__));
$api = new Labeler\Api($config);
$response = $api->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    array_filter($_GET, 'is_string'),
    (string) file_get_contents('php://input'),
    $_SERVER['CONTENT_TYPE'] ?? ''
);
[$status, $headers, $body] = $response->parts();
http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $body;
